<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\HttpBasics\Idempotency\ClaimOutcome;
use Qiick\HttpBasics\Idempotency\DatabaseIdempotencyStore;
use Qiick\HttpBasics\Idempotency\IdempotencyScope;
use Qiick\HttpBasics\Idempotency\IdempotencyStoreException;
use Qiick\HttpBasics\Idempotency\ReplayableResponse;

beforeEach(function (): void {
    Schema::dropIfExists('idempotency_records');
    Schema::create('idempotency_records', function (Blueprint $table): void {
        $table->string('bucket', 128);
        $table->char('key_hash', 64);
        $table->string('method', 8);
        $table->string('path', 512);
        $table->char('fingerprint', 64);
        $table->char('lease', 64);
        $table->string('status');
        $table->unsignedSmallInteger('status_code')->nullable();
        $table->text('headers_json')->nullable();
        $table->longText('body')->nullable();
        $table->timestamp('created_at');
        $table->timestamp('updated_at');
        $table->timestamp('expires_at')->nullable();
        $table->unique(['bucket', 'key_hash']);
        $table->index('expires_at');
    });
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
    // Simulate MySQL TEXT overflow: SQLite has no hard TEXT cap, so abort large body updates.
    DB::statement('
        CREATE TRIGGER idempotency_reject_large_body
        BEFORE UPDATE ON idempotency_records
        FOR EACH ROW
        WHEN NEW.body IS NOT NULL AND length(NEW.body) > 100
        BEGIN
            SELECT RAISE(ABORT, \'Data too long for column body\');
        END
    ');

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
