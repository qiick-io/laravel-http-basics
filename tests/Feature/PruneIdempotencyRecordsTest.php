<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\HttpBasics\Idempotency\IdempotencyRecord;

/*
 * Both prune entry points compare expires_at, which the store writes as UTC
 * wall-clock, against "now": that "now" must be UTC whatever app.timezone says.
 */

beforeEach(function (): void {
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

/**
 * Inserts one completed row whose expires_at is the given UTC wall-clock time.
 */
function insertIdempotencyRecordExpiringAt(string $utcExpiresAt, string $keySuffix): void
{
    DB::table('idempotency_records')->insert([
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
