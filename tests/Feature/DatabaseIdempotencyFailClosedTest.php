<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\HttpBasics\Idempotency\ClaimOutcome;
use Qiick\HttpBasics\Idempotency\DatabaseIdempotencyStore;
use Qiick\HttpBasics\Idempotency\IdempotencyScope;
use Qiick\HttpBasics\Idempotency\IdempotencyStoreException;
use Qiick\HttpBasics\Idempotency\ReplayableResponse;

beforeEach(function (): void {
    Schema::dropIfExists('idempotency_records');
    /** @var Migration $migration */
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_22_000001_create_idempotency_records_table.php';
    $migration->up();
});

function failClosedStore(): DatabaseIdempotencyStore
{
    return new DatabaseIdempotencyStore(
        DB::connection(),
        new Repository(new ArrayStore),
        'idempotency_records',
        86400,
        30,
        5,
    );
}

it('complete() seals unreplayable on QueryException so a retry never re-claims New', function (): void {
    // Abort updates that try to store a large body (simulates MySQL TEXT overflow / write failure).
    $driver = DB::connection()->getDriverName();

    if ($driver === 'sqlite') {
        DB::statement('
            CREATE TRIGGER idempotency_reject_large_body
            BEFORE UPDATE ON idempotency_records
            FOR EACH ROW
            WHEN NEW.body IS NOT NULL AND length(NEW.body) > 100
            BEGIN
                SELECT RAISE(ABORT, \'Data too long for column body\');
            END
        ');
    } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
        DB::unprepared('
            CREATE TRIGGER idempotency_reject_large_body
            BEFORE UPDATE ON idempotency_records
            FOR EACH ROW
            BEGIN
                IF NEW.body IS NOT NULL AND CHAR_LENGTH(NEW.body) > 100 THEN
                    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'Data too long for column body\';
                END IF;
            END
        ');
    } elseif ($driver === 'pgsql') {
        DB::unprepared('
            CREATE OR REPLACE FUNCTION idempotency_reject_large_body() RETURNS trigger AS $$
            BEGIN
                IF NEW.body IS NOT NULL AND length(NEW.body) > 100 THEN
                    RAISE EXCEPTION \'Data too long for column body\';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ');
        DB::unprepared('
            CREATE TRIGGER idempotency_reject_large_body
            BEFORE UPDATE ON idempotency_records
            FOR EACH ROW EXECUTE FUNCTION idempotency_reject_large_body();
        ');
    } else {
        $this->markTestSkipped('No overflow trigger for driver '.$driver);
    }

    $store = failClosedStore();
    $scope = new IdempotencyScope('bucket', 'POST', '/api/items');
    $now = new DateTimeImmutable;
    $key = '550e8400-e29b-41d4-a716-446655440202';
    $claim = $store->claim($scope, $key, 'fp', $now);

    expect(fn () => $store->complete(
        $claim,
        new ReplayableResponse(201, ['Content-Type' => 'application/json'], str_repeat('x', 80_000)),
        $now,
    ))->toThrow(IdempotencyStoreException::class);

    $row = DB::table('idempotency_records')->first();
    expect($row->status)->toBe('completed')
        ->and($row->body)->toBeNull()
        ->and($row->status_code)->toBeNull();

    $again = $store->claim($scope, $key, 'fp', $now);
    expect($again->outcome)->toBe(ClaimOutcome::NotReplayable)
        ->and(DB::table('idempotency_records')->count())->toBe(1);
});

it('treats expired completed rows as absent so keys do not replay forever', function (): void {
    $store = failClosedStore();
    $scope = new IdempotencyScope('bucket', 'POST', '/api/items');
    $now = new DateTimeImmutable('2026-01-01 12:00:00');
    $key = '550e8400-e29b-41d4-a716-446655440203';

    $claim = $store->claim($scope, $key, 'fp', $now);
    $store->complete($claim, new ReplayableResponse(201, [], '{"ok":true}'), $now);

    DB::table('idempotency_records')->update(['expires_at' => $now->modify('-1 second')]);

    $again = $store->claim($scope, $key, 'fp', $now->modify('+2 days'));
    expect($again->outcome)->toBe(ClaimOutcome::New);
});

it('pruneExpired deletes rows past expires_at', function (): void {
    $store = failClosedStore();
    $scope = new IdempotencyScope('bucket', 'POST', '/api/items');
    $now = new DateTimeImmutable('2026-01-01 12:00:00');
    $claim = $store->claim($scope, '550e8400-e29b-41d4-a716-446655440205', 'fp', $now);
    $store->complete($claim, new ReplayableResponse(201, [], 'ok'), $now);

    DB::table('idempotency_records')->update(['expires_at' => $now->modify('-1 minute')]);

    expect($store->pruneExpired($now))->toBe(1)
        ->and(DB::table('idempotency_records')->count())->toBe(0);
});
