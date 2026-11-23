<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\HttpBasics\Contracts\IdempotencyStore;
use Qiick\HttpBasics\Idempotency\CacheIdempotencyStore;
use Qiick\HttpBasics\Idempotency\ClaimOutcome;
use Qiick\HttpBasics\Idempotency\DatabaseIdempotencyStore;
use Qiick\HttpBasics\Idempotency\IdempotencyScope;
use Qiick\HttpBasics\Idempotency\IdempotencyStoreException;
use Qiick\HttpBasics\Idempotency\ReplayableResponse;

/**
 * Shared fail-closed contract: both stores must seal on complete() persistence
 * failure so a retry cannot claim New and re-run the handler.
 */
dataset('idempotency_fail_closed_stores', [
    'cache' => ['cache'],
    'database' => ['database'],
]);

/**
 * @return array{0: IdempotencyStore, 1: callable(): void}
 */
function failClosedStorePair(string $driver): array
{
    if ($driver === 'cache') {
        $inner = new ArrayStore;
        $repository = new class($inner) extends Repository
        {
            public bool $shouldFailCompletedBodyPuts = false;

            public function put($key, $value, $seconds = null): bool
            {
                if (
                    $this->shouldFailCompletedBodyPuts
                    && is_array($value)
                    && ($value['status'] ?? '') === 'completed'
                    && array_key_exists('body', $value)
                    && $value['body'] !== null
                ) {
                    throw new RuntimeException('simulated cache put failure');
                }

                return parent::put($key, $value, $seconds);
            }
        };
        $repository->shouldFailCompletedBodyPuts = true;

        return [new CacheIdempotencyStore($repository, 3600, 30, 5), static function (): void {}];
    }

    Schema::dropIfExists('idempotency_records');
    /** @var Migration $migration */
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_22_000001_create_idempotency_records_table.php';
    $migration->up();

    $engineDriver = DB::connection()->getDriverName();

    if ($engineDriver === 'sqlite') {
        DB::statement('
            CREATE TRIGGER idempotency_reject_large_body_shared
            BEFORE UPDATE ON idempotency_records
            FOR EACH ROW
            WHEN NEW.body IS NOT NULL AND length(NEW.body) > 100
            BEGIN
                SELECT RAISE(ABORT, \'Data too long for column body\');
            END
        ');
    } elseif (in_array($engineDriver, ['mysql', 'mariadb'], true)) {
        DB::unprepared('
            CREATE TRIGGER idempotency_reject_large_body_shared
            BEFORE UPDATE ON idempotency_records
            FOR EACH ROW
            BEGIN
                IF NEW.body IS NOT NULL AND CHAR_LENGTH(NEW.body) > 100 THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'Data too long for column body\';
                END IF;
            END
        ');
    } elseif ($engineDriver === 'pgsql') {
        DB::unprepared('
            CREATE OR REPLACE FUNCTION idempotency_reject_large_body_shared() RETURNS trigger AS $$
            BEGIN
                IF NEW.body IS NOT NULL AND length(NEW.body) > 100 THEN
                    RAISE EXCEPTION \'Data too long for column body\';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ');
        DB::unprepared('
            CREATE TRIGGER idempotency_reject_large_body_shared
            BEFORE UPDATE ON idempotency_records
            FOR EACH ROW EXECUTE FUNCTION idempotency_reject_large_body_shared();
        ');
    } else {
        throw new RuntimeException('No overflow trigger for driver '.$engineDriver);
    }

    $store = new DatabaseIdempotencyStore(
        DB::connection(),
        new Repository(new ArrayStore),
        'idempotency_records',
        86400,
        30,
        5,
    );

    return [$store, static function (): void {}];
}

it('complete() seals unreplayable on persistence failure so a retry never re-claims New', function (string $driver): void {
    [$store] = failClosedStorePair($driver);
    $scope = new IdempotencyScope('bucket', 'POST', '/api/items');
    $now = new DateTimeImmutable;
    $key = '550e8400-e29b-41d4-a716-446655440301';
    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());

    expect($claim->outcome)->toBe(ClaimOutcome::New);

    expect(fn () => $store->complete(
        $claim,
        new ReplayableResponse(201, ['Content-Type' => 'application/json'], str_repeat('x', 80_000)),
        $now,
    ))->toThrow(IdempotencyStoreException::class);

    $again = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    expect($again->outcome)->toBe(ClaimOutcome::NotReplayable);
})->with('idempotency_fail_closed_stores');
