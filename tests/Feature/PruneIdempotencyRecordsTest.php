<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Qiick\HttpBasics\Idempotency\IdempotencyRecord;
use Qiick\HttpBasics\Laravel\HttpBasicsServiceProvider;

/*
 * Both prune entry points compare expires_at, which the store writes as UTC
 * wall-clock, against "now": that "now" must be UTC whatever app.timezone says.
 */

beforeEach(function (): void {
    // PostgreSQL keeps the original index names when the configured-table test renames the table.
    Schema::dropIfExists('idempotency_custom');
    Schema::dropIfExists('idempotency_records');
    /** @var Migration $migration */
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_22_000001_create_idempotency_records_table.php';
    $migration->up();

    $this->originalTimezone = date_default_timezone_get();
    date_default_timezone_set('Europe/Rome');
    config(['app.timezone' => 'Europe/Rome']);
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
    date_default_timezone_set($this->originalTimezone);
});

it('schedules idempotency pruning only for the database adapter when enabled', function (string $store, bool $shouldSchedule, int $expectedCount): void {
    config()->set('http-basics.idempotency.store', $store);
    config()->set('http-basics.idempotency.database.schedule', $shouldSchedule);
    $this->app->getProvider(HttpBasicsServiceProvider::class)->boot();

    $events = array_values(array_filter(app(Schedule::class)->events(), static fn ($event): bool => str_contains((string) $event->command, 'http-basics:prune-idempotency')));

    expect($events)->toHaveCount($expectedCount);

    if ($expectedCount === 1) {
        expect($events[0]->onOneServer)->toBeTrue()
            ->and($events[0]->withoutOverlapping)->toBeTrue()
            ->and($events[0]->expression)->toBe('0 * * * *');
    }
})->with([
    'database is scheduled' => ['database', true, 1],
    'custom schedule' => ['database', false, 0],
    'cache uses native expiry' => ['cache', true, 0],
]);

/**
 * Inserts one completed row whose expires_at is the given UTC wall-clock time.
 */
function insertIdempotencyRecordExpiringAt(string $utcExpiresAt, string $keySuffix): void
{
    DB::table('idempotency_records')->insert([
        'public_id' => (string) Str::uuid7(),
        'key_format' => 'uuid',
        'bucket' => 'bucket-a',
        'key_hash' => hash('sha256', 'key-'.$keySuffix),
        'method' => 'POST',
        'path' => '/items',
        'fingerprint' => 'fp',
        'lease' => str_repeat('a', 64),
        'status' => 'completed',
        'status_code' => 201,
        'headers_json' => '{}',
        'body' => 'created',
        'created_at' => '2026-01-01 11:00:00',
        'updated_at' => '2026-01-01 11:00:00',
        'expires_at' => $utcExpiresAt,
    ]);
}

it('keeps rows that expire later today in UTC when model:prune runs in a non-UTC app', function (): void {
    insertIdempotencyRecordExpiringAt('2026-01-01 12:30:00', 'live');
    insertIdempotencyRecordExpiringAt('2026-01-01 11:30:00', 'expired');

    expect((new IdempotencyRecord)->prunable()->pluck('key_hash')->all())
        ->toBe([hash('sha256', 'key-expired')]);
});

it('keeps rows that expire later today in UTC when the prune command runs in a non-UTC app', function (): void {
    insertIdempotencyRecordExpiringAt('2026-01-01 12:30:00', 'live');
    insertIdempotencyRecordExpiringAt('2026-01-01 11:30:00', 'expired');

    $this->artisan('http-basics:prune-idempotency')
        ->expectsOutput('Deleted 1 expired idempotency record(s).')
        ->assertSuccessful();

    expect(DB::table('idempotency_records')->pluck('key_hash')->all())
        ->toBe([hash('sha256', 'key-live')]);
});

it('prunes a backlog in bounded batches', function (): void {
    insertIdempotencyRecordExpiringAt('2026-01-01 11:30:00', 'first');
    $expiredRecord = (array) DB::table('idempotency_records')->first();
    unset($expiredRecord['id']);

    $expiredRecords = [];

    foreach (range(2, 251) as $recordNumber) {
        $expiredRecords[] = array_replace($expiredRecord, [
            'public_id' => (string) Str::uuid7(),
            'key_hash' => hash('sha256', 'expired-'.$recordNumber),
        ]);
    }

    foreach (array_chunk($expiredRecords, 25) as $batch) {
        DB::table('idempotency_records')->insert($batch);
    }

    $deleteBatchSizes = [];
    DB::connection()->beforeExecuting(static function (string $query, array $bindings) use (&$deleteBatchSizes): void {
        if (str_starts_with($query, 'delete from')) {
            // The batch binds the expiry cutoff in addition to the IDs.
            $deleteBatchSizes[] = count($bindings) - 1;
        }
    });

    $this->artisan('http-basics:prune-idempotency')->expectsOutput('Deleted 251 expired idempotency record(s).')->assertSuccessful();

    expect($deleteBatchSizes)->toBe([250, 1])
        ->and(DB::table('idempotency_records')->count())->toBe(0);
});

it('prunes on the configured idempotency connection, not the default one', function (): void {
    // The store writes to http-basics.idempotency.database.connection; prune must follow it.
    config(['database.connections.idempotency' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]]);
    config(['http-basics.idempotency.database.connection' => 'idempotency']);

    Schema::connection('idempotency')->create('idempotency_records', function ($table): void {
        $table->id();
        $table->string('key_hash', 64);
        $table->dateTime('expires_at');
    });
    DB::connection('idempotency')->table('idempotency_records')->insert([
        ['key_hash' => 'live', 'expires_at' => '2026-01-01 12:30:00'],
        ['key_hash' => 'expired', 'expires_at' => '2026-01-01 11:30:00'],
    ]);
    insertIdempotencyRecordExpiringAt('2026-01-01 11:30:00', 'default-connection');

    expect((new IdempotencyRecord)->prunable()->pluck('key_hash')->all())->toBe(['expired']);

    $this->artisan('http-basics:prune-idempotency')->assertSuccessful();

    expect(DB::connection('idempotency')->table('idempotency_records')->pluck('key_hash')->all())->toBe(['live'])
        ->and(DB::table('idempotency_records')->count())->toBe(1);
});

it('keeps a connection chosen with on() or setConnection() over the configured one', function (): void {
    config(['http-basics.idempotency.database.connection' => 'idempotency']);

    expect((new IdempotencyRecord)->setConnection('sqlite')->getConnectionName())->toBe('sqlite')
        ->and((new IdempotencyRecord)->getConnectionName())->toBe('idempotency');
});

it('prunes only rows expired at least --hours ago, never a negative window', function (string $hours, array $kept): void {
    // Now is 12:00 UTC (beforeEach).
    insertIdempotencyRecordExpiringAt('2026-01-01 08:00:00', 'four-hours-ago');
    insertIdempotencyRecordExpiringAt('2026-01-01 11:00:00', 'one-hour-ago');
    insertIdempotencyRecordExpiringAt('2026-01-01 13:00:00', 'live');

    $this->artisan('http-basics:prune-idempotency', ['--hours' => $hours])->assertSuccessful();

    expect(DB::table('idempotency_records')->orderBy('expires_at')->pluck('key_hash')->all())
        ->toBe(array_map(static fn (string $suffix): string => hash('sha256', 'key-'.$suffix), $kept));
})->with([
    'two hours' => ['2', ['one-hour-ago', 'live']],
    'negative clamps to zero' => ['-5', ['live']],
]);

it('only counts on --dry-run', function (): void {
    insertIdempotencyRecordExpiringAt('2026-01-01 11:00:00', 'expired');

    $this->artisan('http-basics:prune-idempotency', ['--dry-run' => true])
        ->expectsOutput('Would delete 1 expired idempotency record(s).')
        ->assertSuccessful();

    expect(DB::table('idempotency_records')->count())->toBe(1);
});

it('prunes the configured table', function (): void {
    Schema::rename('idempotency_records', 'idempotency_custom');
    config(['http-basics.idempotency.database.table' => 'idempotency_custom']);
    DB::table('idempotency_custom')->insert([
        'public_id' => (string) Str::uuid7(),
        'key_format' => 'uuid',
        'bucket' => 'bucket-a',
        'key_hash' => hash('sha256', 'custom'),
        'method' => 'POST',
        'path' => '/items',
        'fingerprint' => 'fp',
        'lease' => str_repeat('a', 64),
        'status' => 'completed',
        'expires_at' => '2026-01-01 11:00:00',
    ]);

    $this->artisan('http-basics:prune-idempotency')
        ->expectsOutput('Deleted 1 expired idempotency record(s).')
        ->assertSuccessful();

    expect(DB::table('idempotency_custom')->count())->toBe(0);
});

it('reads --hours as whole hours', function (): void {
    // 1.5 counts as 1: a row expired at 10:45 is past the 11:00 cutoff.
    insertIdempotencyRecordExpiringAt('2026-01-01 10:45:00', 'quarter-to-eleven');

    $this->artisan('http-basics:prune-idempotency', ['--hours' => '1.5'])->assertSuccessful();

    expect(DB::table('idempotency_records')->count())->toBe(0);
});
