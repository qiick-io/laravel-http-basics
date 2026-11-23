<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\HttpBasics\Idempotency\ClaimOutcome;
use Qiick\HttpBasics\Idempotency\DatabaseIdempotencyStore;
use Qiick\HttpBasics\Idempotency\IdempotencyClaim;
use Qiick\HttpBasics\Idempotency\IdempotencyScope;
use Qiick\HttpBasics\Idempotency\IdempotencyStoreException;
use Qiick\HttpBasics\Idempotency\ReplayableResponse;

beforeEach(function (): void {
    Schema::dropIfExists('idempotency_records');
    /** @var Migration $migration */
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_22_000001_create_idempotency_records_table.php';
    $migration->up();
});

function databaseIdempotencyStore(
    int $ttlSeconds = 86400,
    int $lockTtlSeconds = 30,
    int $staleAfterSeconds = 5,
    ?Repository $cache = null,
): DatabaseIdempotencyStore {
    return new DatabaseIdempotencyStore(
        DB::connection(),
        $cache ?? new Repository(new ArrayStore),
        'idempotency_records',
        $ttlSeconds,
        $lockTtlSeconds,
        $staleAfterSeconds,
    );
}

it('rejects cache stores without atomic locks', function (): void {
    $storeWithoutLocks = new class implements Store
    {
        public function get($key): mixed
        {
            return null;
        }

        /** @param  array<int, string>  $keys */
        public function many(array $keys): array
        {
            return [];
        }

        public function put($key, $value, $seconds): bool
        {
            return true;
        }

        /** @param  array<string, mixed>  $values */
        public function putMany(array $values, $seconds): bool
        {
            return true;
        }

        public function increment($key, $value = 1): int|bool
        {
            return 1;
        }

        public function decrement($key, $value = 1): int|bool
        {
            return 1;
        }

        public function forever($key, $value): bool
        {
            return true;
        }

        public function touch($key, $seconds): bool
        {
            return true;
        }

        public function forget($key): bool
        {
            return true;
        }

        public function flush(): bool
        {
            return true;
        }

        public function getPrefix(): string
        {
            return '';
        }
    };

    expect(fn () => new DatabaseIdempotencyStore(
        DB::connection(),
        new Repository($storeWithoutLocks),
        'idempotency_records',
        60,
        30,
        5,
    ))->toThrow(InvalidArgumentException::class);
});

it('enforces unique bucket and key hash pairs', function (): void {
    $store = databaseIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440000';

    $claim = $store->claim($scope, $key, 'fp-1', $now);
    expect($claim->outcome)->toBe(ClaimOutcome::New)
        ->and(strlen((string) $claim->lease))->toBe(64);

    $store->complete($claim, new ReplayableResponse(201, ['X-A' => '1'], '{"ok":true}'), $now);

    $replay = $store->claim($scope, $key, 'fp-1', $now);
    expect($replay->outcome)->toBe(ClaimOutcome::Replay)
        ->and($replay->replay?->status)->toBe(201)
        ->and($replay->replay?->body)->toBe('{"ok":true}')
        ->and($replay->replay?->headers)->toBe(['X-A' => '1'])
        ->and(DB::table('idempotency_records')->count())->toBe(1);

    $row = DB::table('idempotency_records')->first();
    expect($row->key_hash)->toBe(hash('sha256', $key))
        ->and($row->expires_at)->not->toBeNull();
});

it('returns mismatch for a completed row with a different fingerprint', function (): void {
    $store = databaseIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440001';

    $claim = $store->claim($scope, $key, 'fp-1', $now);
    $store->complete($claim, new ReplayableResponse(200, [], 'ok'), $now);

    expect($store->claim($scope, $key, 'fp-2', $now)->outcome)->toBe(ClaimOutcome::Mismatch);
});

it('conflicts while the lock is held and takes over after the row goes stale', function (): void {
    $cache = new Repository(new ArrayStore);
    $store = databaseIdempotencyStore(staleAfterSeconds: 5, cache: $cache);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440002';

    $first = $store->claim($scope, $key, 'fp', $now);
    expect($first->outcome)->toBe(ClaimOutcome::New);
    expect($store->claim($scope, $key, 'fp', $now)->outcome)->toBe(ClaimOutcome::Conflict);

    $lockKey = $scope->cacheKey($key).':lock';
    $cache->getStore()->restoreLock($lockKey, (string) $first->lease)->forceRelease();

    expect($store->claim($scope, $key, 'fp', $now)->outcome)->toBe(ClaimOutcome::Conflict);

    $staleNow = $now->modify('+10 seconds');
    $takeover = $store->claim($scope, $key, 'fp-takeover', $staleNow);
    expect($takeover->outcome)->toBe(ClaimOutcome::New);

    $row = DB::table('idempotency_records')->first();
    expect($row->fingerprint)->toBe('fp-takeover')
        ->and($row->status)->toBe('in_progress')
        ->and($row->status_code)->toBeNull()
        ->and($row->headers_json)->toBeNull()
        ->and($row->body)->toBeNull();
});

it('replays completed rows with defensive header and status parsing', function (): void {
    $store = databaseIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440003';

    DB::table('idempotency_records')->insert([
        'bucket' => $scope->bucket,
        'key_hash' => hash('sha256', $key),
        'method' => 'POST',
        'path' => '/items',
        'fingerprint' => 'fp',
        'lease' => str_repeat('a', 64),
        'status' => 'completed',
        'status_code' => '202',
        'headers_json' => json_encode([
            'X-Text' => 'ok',
            'X-List' => ['a', 2],
            9 => 'skip',
            'X-Object' => ['nested' => true],
        ], JSON_THROW_ON_ERROR),
        'body' => null,
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => $now->modify('+1 day'),
    ]);

    $replay = $store->claim($scope, $key, 'fp', $now);
    expect($replay->outcome)->toBe(ClaimOutcome::Replay)
        ->and($replay->replay?->status)->toBe(202)
        ->and($replay->replay?->body)->toBe('')
        ->and($replay->replay?->headers['X-Text'])->toBe('ok')
        ->and($replay->replay?->headers['X-List'])->toBe('a, 2');
});

it('defaults replay status and headers when the completed row is sparse', function (): void {
    // MySQL/pgsql reject non-numeric smallints; SQLite stores the defensive fixtures as text.
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Sparse status_code/headers fixtures require SQLite column affinity.');
    }

    $store = databaseIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440004';

    DB::table('idempotency_records')->insert([
        'bucket' => $scope->bucket,
        'key_hash' => hash('sha256', $key),
        'method' => 'POST',
        'path' => '/items',
        'fingerprint' => 'fp',
        'lease' => str_repeat('b', 64),
        'status' => 'completed',
        'status_code' => 'not-numeric',
        'headers_json' => '{not-json',
        'body' => null,
        'created_at' => $now,
        'updated_at' => $now,
        'expires_at' => $now,
    ]);

    $replay = $store->claim($scope, $key, 'fp', $now);
    expect($replay->outcome)->toBe(ClaimOutcome::Replay)
        ->and($replay->replay?->status)->toBe(200)
        ->and($replay->replay?->headers)->toBe([])
        ->and($replay->replay?->body)->toBe('');
});

it('rejects completion unless the claim is new with a matching in-progress lease', function (): void {
    $store = databaseIdempotencyStore(ttlSeconds: 120);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440005';
    $response = new ReplayableResponse(200, ['X' => '1'], '{}');

    expect(fn () => $store->complete(
        new IdempotencyClaim(ClaimOutcome::Replay, null, 'lease', $scope, $key),
        $response,
        $now,
    ))->toThrow(IdempotencyStoreException::class);

    expect(fn () => $store->complete(
        new IdempotencyClaim(ClaimOutcome::New, null, '', $scope, $key),
        $response,
        $now,
    ))->toThrow(IdempotencyStoreException::class);

    $claim = $store->claim($scope, $key, 'fp', $now);
    $completeAt = $now->modify('+2 seconds');
    $store->complete($claim, $response, $completeAt);

    $row = DB::table('idempotency_records')->first();
    expect($row->status)->toBe('completed')
        ->and($row->status_code)->toBe(200)
        ->and($row->body)->toBe('{}');

    expect(fn () => $store->complete(
        new IdempotencyClaim(ClaimOutcome::New, null, 'other-lease', $scope, $key),
        $response,
        $now,
    ))->toThrow(IdempotencyStoreException::class);
});

it('abandons only new in-progress claims with a matching lease', function (): void {
    $cache = new Repository(new ArrayStore);
    $store = databaseIdempotencyStore(cache: $cache);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440006';

    $store->abandon(new IdempotencyClaim(ClaimOutcome::Replay, null, 'lease', $scope, $key), $now);
    $store->abandon(new IdempotencyClaim(ClaimOutcome::New, null, '', $scope, $key), $now);

    $claim = $store->claim($scope, $key, 'fp', $now);
    $store->abandon(new IdempotencyClaim(ClaimOutcome::New, null, 'wrong', $scope, $key), $now);
    expect(DB::table('idempotency_records')->count())->toBe(1);

    $store->abandon($claim, $now);
    expect(DB::table('idempotency_records')->count())->toBe(0);

    $lockKey = $scope->cacheKey($key).':lock';
    expect($cache->getStore()->lock($lockKey, 1, 'probe')->get())->toBeTrue();
});

it('holds the lock on the scoped :lock key', function (): void {
    $cache = new Repository(new ArrayStore);
    $store = databaseIdempotencyStore(cache: $cache);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440007';

    $wrong = $cache->getStore()->lock($scope->cacheKey($key), 30, 'foreign');
    expect($wrong->get())->toBeTrue();

    $claim = $store->claim($scope, $key, 'fp', $now);
    expect($claim->outcome)->toBe(ClaimOutcome::New);

    $right = $cache->getStore()->lock($scope->cacheKey($key).':lock', 30, 'blocker');
    expect($right->get())->toBeFalse();
});

it('uses updated_at then created_at when judging stale in-progress rows', function (): void {
    $cache = new Repository(new ArrayStore);
    $store = databaseIdempotencyStore(staleAfterSeconds: 5, cache: $cache);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440008';

    DB::table('idempotency_records')->insert([
        'bucket' => $scope->bucket,
        'key_hash' => hash('sha256', $key),
        'method' => 'POST',
        'path' => '/items',
        'fingerprint' => 'old',
        'lease' => str_repeat('c', 64),
        'status' => 'in_progress',
        'status_code' => null,
        'headers_json' => null,
        'body' => null,
        'created_at' => '2025-12-01 00:00:00',
        'updated_at' => '2025-12-01 00:00:00',
        'expires_at' => '2025-12-02 00:00:00',
    ]);

    $takeover = $store->claim($scope, $key, 'fresh', $now);
    expect($takeover->outcome)->toBe(ClaimOutcome::New);

    // Fresh updated_at must win over a stale created_at (kills CoalesceRemoveLeft on updated_at).
    DB::table('idempotency_records')->update([
        'created_at' => '2025-12-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
        'lease' => str_repeat('d', 64),
    ]);
    $cache->getStore()->lock($scope->cacheKey($key).':lock', 1, 'x')->forceRelease();

    expect($store->claim($scope, $key, 'fresh-2', $now)->outcome)->toBe(ClaimOutcome::Conflict);

    // Dropping the trailing `?? null` collapses the timestamp to null/epoch 0 and would allow takeover.
    DB::table('idempotency_records')->update([
        'created_at' => $now->format('Y-m-d H:i:s'),
        'updated_at' => $now->format('Y-m-d H:i:s'),
        'lease' => str_repeat('e', 64),
    ]);
    $cache->getStore()->lock($scope->cacheKey($key).':lock', 1, 'y')->forceRelease();

    expect($store->claim($scope, $key, 'fresh-3', $now)->outcome)->toBe(ClaimOutcome::Conflict);
});

it('returns replay before contending for the lock on completed rows', function (): void {
    $cache = new Repository(new ArrayStore);
    $store = databaseIdempotencyStore(cache: $cache);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440010';

    $claim = $store->claim($scope, $key, 'fp', $now);
    $store->complete($claim, new ReplayableResponse(200, [], 'ok'), $now);
    expect($cache->getStore()->lock($scope->cacheKey($key).':lock', 30, 'held')->get())->toBeTrue();

    expect($store->claim($scope, $key, 'fp', $now)->outcome)->toBe(ClaimOutcome::Replay);
});

it('conflicts when only the lock is held and no row exists', function (): void {
    $cache = new Repository(new ArrayStore);
    $store = databaseIdempotencyStore(cache: $cache);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440011';

    expect($cache->getStore()->lock($scope->cacheKey($key).':lock', 30, 'held')->get())->toBeTrue();
    expect($store->claim($scope, $key, 'fp', $now)->outcome)->toBe(ClaimOutcome::Conflict);
});

it('takes over at the exact stale boundary and clears payload columns', function (): void {
    $cache = new Repository(new ArrayStore);
    $store = databaseIdempotencyStore(ttlSeconds: 90, staleAfterSeconds: 5, cache: $cache);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440012';

    $first = $store->claim($scope, $key, 'fp', $now);
    $store->complete($first, new ReplayableResponse(200, ['X' => '1'], 'body'), $now);

    DB::table('idempotency_records')->update([
        'status' => 'in_progress',
        'updated_at' => $now,
        'created_at' => $now,
    ]);
    $cache->getStore()->restoreLock($scope->cacheKey($key).':lock', (string) $first->lease)->forceRelease();

    $boundary = $now->modify('+5 seconds');
    $takeover = $store->claim($scope, $key, 'fp-2', $boundary);
    expect($takeover->outcome)->toBe(ClaimOutcome::New);

    $row = DB::table('idempotency_records')->first();
    expect($row->fingerprint)->toBe('fp-2')
        ->and($row->status)->toBe('in_progress')
        ->and($row->status_code)->toBeNull()
        ->and($row->headers_json)->toBeNull()
        ->and($row->body)->toBeNull()
        ->and($row->expires_at)->not->toBeNull();
});

it('persists null payload columns on the initial in-progress insert', function (): void {
    $store = databaseIdempotencyStore(ttlSeconds: 120);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440013';

    $store->claim($scope, $key, 'fp', $now);
    $row = DB::table('idempotency_records')->first();

    expect($row->status_code)->toBeNull()
        ->and($row->headers_json)->toBeNull()
        ->and($row->body)->toBeNull()
        ->and($row->method)->toBe('POST')
        ->and($row->path)->toBe('/items')
        ->and($row->expires_at)->not->toBeNull();
});

it('releases the scoped lock after complete and abandon', function (): void {
    $cache = new Repository(new ArrayStore);
    $store = databaseIdempotencyStore(cache: $cache);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440014';

    $claim = $store->claim($scope, $key, 'fp', $now);
    $store->complete($claim, new ReplayableResponse(200, [], '{}'), $now);
    expect($cache->getStore()->lock($scope->cacheKey($key).':lock', 1, 'probe')->get())->toBeTrue();

    $otherKey = '550e8400-e29b-41d4-a716-446655440015';
    $again = $store->claim($scope, $otherKey, 'fp', $now);
    $store->abandon($again, $now);
    expect($cache->getStore()->lock($scope->cacheKey($otherKey).':lock', 1, 'probe2')->get())->toBeTrue();
});

it('does not abandon in-progress rows for non-new outcomes even with a matching lease', function (): void {
    $store = databaseIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440016';

    $claim = $store->claim($scope, $key, 'fp', $now);
    $store->abandon(
        new IdempotencyClaim(ClaimOutcome::Replay, null, (string) $claim->lease, $scope, $key),
        $now,
    );

    expect(DB::table('idempotency_records')->count())->toBe(1);
});

it('requires missing-lease failures on complete to use the dedicated message', function (): void {
    $store = databaseIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440017';
    $response = new ReplayableResponse(200, [], '{}');

    try {
        $store->complete(new IdempotencyClaim(ClaimOutcome::New, null, null, $scope, $key), $response, $now);
        expect(false)->toBeTrue();
    } catch (IdempotencyStoreException $exception) {
        expect($exception->getMessage())->toBe('Missing lease on idempotency claim.');
    }

    try {
        $store->complete(new IdempotencyClaim(ClaimOutcome::New, null, '', $scope, $key), $response, $now);
        expect(false)->toBeTrue();
    } catch (IdempotencyStoreException $exception) {
        expect($exception->getMessage())->toBe('Missing lease on idempotency claim.');
    }
});

it('parses datetime objects and invalid timestamp strings when judging staleness', function (): void {
    // Invalid datetime strings are rejected by MySQL/pgsql; SQLite accepts them as text.
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Invalid timestamp string fixtures require SQLite.');
    }

    $cache = new Repository(new ArrayStore);
    $store = databaseIdempotencyStore(staleAfterSeconds: 5, cache: $cache);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:10Z');
    $key = '550e8400-e29b-41d4-a716-446655440018';

    DB::table('idempotency_records')->insert([
        'bucket' => $scope->bucket,
        'key_hash' => hash('sha256', $key),
        'method' => 'POST',
        'path' => '/items',
        'fingerprint' => 'old',
        'lease' => str_repeat('d', 64),
        'status' => 'in_progress',
        'status_code' => null,
        'headers_json' => null,
        'body' => null,
        'created_at' => $now->modify('-1 second'),
        'updated_at' => $now->modify('-1 second'),
        'expires_at' => $now,
    ]);

    expect($store->claim($scope, $key, 'fresh', $now)->outcome)->toBe(ClaimOutcome::Conflict);

    DB::table('idempotency_records')->update([
        'updated_at' => 'not-a-date',
        'created_at' => 'not-a-date',
        'lease' => str_repeat('e', 64),
    ]);
    $cache->getStore()->lock($scope->cacheKey($key).':lock', 1, 'x')->forceRelease();

    expect($store->claim($scope, $key, 'fresh-2', $now)->outcome)->toBe(ClaimOutcome::New);
});

it('replays a completed row discovered after the lock is acquired', function (): void {
    $cache = new Repository(new ArrayStore);
    $completed = (object) [
        'status' => 'completed',
        'fingerprint' => 'fp',
        'headers_json' => '{"X-A":"1"}',
        'status_code' => 200,
        'body' => '{"race":true}',
    ];

    $connection = Mockery::mock(ConnectionInterface::class);
    $builder = Mockery::mock();
    $builder->shouldReceive('where')->andReturnSelf();
    $builder->shouldReceive('first')->andReturn(null, $completed);
    $connection->shouldReceive('table')->andReturn($builder);

    $store = new DatabaseIdempotencyStore(
        $connection,
        $cache,
        'idempotency_records',
        60,
        30,
        5,
    );
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440019';

    $claim = $store->claim($scope, $key, 'fp', $now);
    expect($claim->outcome)->toBe(ClaimOutcome::Replay)
        ->and($claim->replay?->body)->toBe('{"race":true}');

    expect($cache->getStore()->lock($scope->cacheKey($key).':lock', 1, 'probe')->get())->toBeTrue();
});

it('wraps write failures as store exceptions and releases the lock', function (): void {
    $cache = new Repository(new ArrayStore);
    $connection = Mockery::mock(ConnectionInterface::class);
    $builder = Mockery::mock();
    $builder->shouldReceive('where')->andReturnSelf();
    $builder->shouldReceive('first')->andReturn(null);
    $builder->shouldReceive('insert')->once()->andThrow(new QueryException(
        'sqlite',
        'insert',
        [],
        new Exception('boom'),
    ));
    $connection->shouldReceive('table')->andReturn($builder);

    $store = new DatabaseIdempotencyStore(
        $connection,
        $cache,
        'idempotency_records',
        60,
        30,
        5,
    );
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440009';

    expect(fn () => $store->claim($scope, $key, 'fp', $now))
        ->toThrow(IdempotencyStoreException::class);

    $lockKey = $scope->cacheKey($key).':lock';
    expect($cache->getStore()->lock($lockKey, 1, 'probe')->get())->toBeTrue();
});

it('judges expiry in UTC when the application timezone is not UTC', function (): void {
    // Laravel sets the PHP default timezone from app.timezone; stored datetimes are UTC wall-clock.
    $originalTimezone = date_default_timezone_get();
    date_default_timezone_set('Europe/Rome');

    try {
        $store = databaseIdempotencyStore(ttlSeconds: 7200);
        $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $key = '550e8400-e29b-41d4-a716-446655440020';

        $claim = $store->claim($scope, $key, 'fp', $now);
        $store->complete($claim, new ReplayableResponse(201, [], 'created'), $now);

        // 90 minutes in: well inside the 2-hour TTL, so the stored response must replay.
        $retry = $store->claim($scope, $key, 'fp', $now->modify('+90 minutes'));

        expect($retry->outcome)->toBe(ClaimOutcome::Replay);
    } finally {
        date_default_timezone_set($originalTimezone);
    }
});
