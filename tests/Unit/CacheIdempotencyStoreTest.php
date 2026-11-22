<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Qiick\HttpBasics\Idempotency\CacheIdempotencyStore;
use Qiick\HttpBasics\Idempotency\ClaimOutcome;
use Qiick\HttpBasics\Idempotency\IdempotencyClaim;
use Qiick\HttpBasics\Idempotency\IdempotencyScope;
use Qiick\HttpBasics\Idempotency\IdempotencyStoreException;
use Qiick\HttpBasics\Idempotency\ReplayableResponse;

/**
 * @return array{0: CacheIdempotencyStore, 1: Repository}
 */
function cacheIdempotencyStore(int $ttlSeconds = 3600, int $lockTtlSeconds = 30, int $staleAfterSeconds = 5): array
{
    $repository = new Repository(new ArrayStore);
    $store = new CacheIdempotencyStore($repository, $ttlSeconds, $lockTtlSeconds, $staleAfterSeconds);

    return [$store, $repository];
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

    expect(fn () => new CacheIdempotencyStore(new Repository($storeWithoutLocks), 60, 30, 5))
        ->toThrow(InvalidArgumentException::class);
});

it('stores and replays through the cache driver', function (): void {
    [$store] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440000';

    $claim = $store->claim($scope, $key, 'fp-1', $now);
    expect($claim->outcome)->toBe(ClaimOutcome::New)
        ->and(is_string($claim->lease))->toBeTrue()
        ->and(strlen((string) $claim->lease))->toBe(64);

    $store->complete($claim, new ReplayableResponse(200, ['Content-Type' => 'application/json'], '{}'), $now);

    $replay = $store->claim($scope, $key, 'fp-1', $now);
    expect($replay->outcome)->toBe(ClaimOutcome::Replay)
        ->and($replay->replay?->status)->toBe(200)
        ->and($replay->replay?->body)->toBe('{}')
        ->and($replay->replay?->headers)->toBe(['Content-Type' => 'application/json']);
});

it('returns mismatch for a completed record with a different fingerprint', function (): void {
    [$store] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440001';

    $claim = $store->claim($scope, $key, 'fp-1', $now);
    $store->complete($claim, new ReplayableResponse(200, [], 'ok'), $now);

    expect($store->claim($scope, $key, 'fp-2', $now)->outcome)->toBe(ClaimOutcome::Mismatch);
});

it('conflicts while the lock is held and takes over after the record goes stale', function (): void {
    [$store, $repository] = cacheIdempotencyStore(staleAfterSeconds: 5);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440002';

    $first = $store->claim($scope, $key, 'fp', $now);
    expect($first->outcome)->toBe(ClaimOutcome::New);
    expect($store->claim($scope, $key, 'fp', $now)->outcome)->toBe(ClaimOutcome::Conflict);

    $recordKey = $scope->cacheKey($key);
    $lockKey = $recordKey.':lock';
    $repository->getStore()->restoreLock($lockKey, (string) $first->lease)->forceRelease();

    $freshConflict = $store->claim($scope, $key, 'fp', $now);
    expect($freshConflict->outcome)->toBe(ClaimOutcome::Conflict);

    $staleNow = $now->modify('+10 seconds');
    $takeover = $store->claim($scope, $key, 'fp-takeover', $staleNow);
    expect($takeover->outcome)->toBe(ClaimOutcome::New)
        ->and($takeover->lease)->not->toBe($first->lease);
});

it('ignores non-array cache payloads when claiming', function (): void {
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440003';

    $repository->put($scope->cacheKey($key), 'not-an-array', 60);

    $claim = $store->claim($scope, $key, 'fp', $now);
    expect($claim->outcome)->toBe(ClaimOutcome::New);
});

it('replays completed records that store numeric strings and array headers', function (): void {
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440004';

    $repository->put($scope->cacheKey($key), [
        'fingerprint' => 'fp',
        'lease' => 'lease',
        'status' => 'completed',
        'status_code' => '201',
        'headers' => [
            12 => 'skip-me',
            'X-Text' => 'ok',
            'X-List' => ['a', 'b', new stdClass],
            'X-Object' => new stdClass,
        ],
        'body' => ['not-a-string'],
        'started_at' => '100',
        'expires_at' => $now->getTimestamp() + 3600,
    ], 60);

    $replay = $store->claim($scope, $key, 'fp', $now);

    expect($replay->outcome)->toBe(ClaimOutcome::Replay)
        ->and($replay->replay?->status)->toBe(201)
        ->and($replay->replay?->body)->toBe('')
        ->and($replay->replay?->headers)->toBe([
            'X-Text' => 'ok',
            'X-List' => 'a, b, ',
        ]);
});

it('defaults status code and headers when completed payloads omit them', function (): void {
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440005';

    $repository->put($scope->cacheKey($key), [
        'fingerprint' => 'fp',
        'status' => 'completed',
        'status_code' => new stdClass,
        'headers' => 'not-array',
        'body' => null,
    ], 60);

    $replay = $store->claim($scope, $key, 'fp', $now);

    expect($replay->outcome)->toBe(ClaimOutcome::Replay)
        ->and($replay->replay?->status)->toBe(200)
        ->and($replay->replay?->headers)->toBe([])
        ->and($replay->replay?->body)->toBe('');
});

it('rejects completion unless the claim is new with a matching lease', function (): void {
    [$store] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440006';
    $response = new ReplayableResponse(200, [], '{}');

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

    expect(fn () => $store->complete(
        new IdempotencyClaim(ClaimOutcome::New, null, null, $scope, $key),
        $response,
        $now,
    ))->toThrow(IdempotencyStoreException::class);

    $claim = $store->claim($scope, $key, 'fp', $now);
    $store->complete($claim, $response, $now);

    expect(fn () => $store->complete(
        new IdempotencyClaim(ClaimOutcome::New, null, 'other-lease', $scope, $key),
        $response,
        $now,
    ))->toThrow(IdempotencyStoreException::class);
});

it('preserves fingerprint and started_at when completing', function (): void {
    [$store, $repository] = cacheIdempotencyStore(ttlSeconds: 120);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440007';

    $claim = $store->claim($scope, $key, 'fp-keep', $now);
    $completeAt = $now->modify('+3 seconds');
    $store->complete($claim, new ReplayableResponse(201, ['X-A' => '1'], '{"ok":true}'), $completeAt);

    /** @var array<string, mixed> $record */
    $record = $repository->get($scope->cacheKey($key));

    expect($record['fingerprint'])->toBe('fp-keep')
        ->and($record['status'])->toBe('completed')
        ->and($record['status_code'])->toBe(201)
        ->and($record['body'])->toBe('{"ok":true}')
        ->and($record['started_at'])->toBe($now->getTimestamp())
        ->and($record['expires_at'])->toBe($completeAt->getTimestamp() + 120);
});

it('abandons only new claims with a matching in-progress lease', function (): void {
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440008';

    $store->abandon(new IdempotencyClaim(ClaimOutcome::Replay, null, 'lease', $scope, $key), $now);
    $store->abandon(new IdempotencyClaim(ClaimOutcome::New, null, '', $scope, $key), $now);
    $store->abandon(new IdempotencyClaim(ClaimOutcome::New, null, null, $scope, $key), $now);

    $claim = $store->claim($scope, $key, 'fp', $now);
    $store->abandon(new IdempotencyClaim(ClaimOutcome::New, null, 'wrong', $scope, $key), $now);
    expect($repository->get($scope->cacheKey($key)))->not->toBeNull();

    $store->abandon($claim, $now);
    expect($repository->get($scope->cacheKey($key)))->toBeNull();

    $again = $store->claim($scope, $key, 'fp', $now);
    expect($again->outcome)->toBe(ClaimOutcome::New);
});

it('holds the lock on the scoped :lock key so a wrong lock name does not conflict', function (): void {
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440009';

    $wrongLock = $repository->getStore()->lock($scope->cacheKey($key), 30, 'foreign');
    expect($wrongLock->get())->toBeTrue();

    $claim = $store->claim($scope, $key, 'fp', $now);
    expect($claim->outcome)->toBe(ClaimOutcome::New);

    $rightLock = $repository->getStore()->lock($scope->cacheKey($key).':lock', 30, 'blocker');
    expect($rightLock->get())->toBeFalse();
});

it('returns replay before contending for the lock on completed records', function (): void {
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440010';

    $claim = $store->claim($scope, $key, 'fp', $now);
    $store->complete($claim, new ReplayableResponse(200, [], 'ok'), $now);

    expect($repository->getStore()->lock($scope->cacheKey($key).':lock', 30, 'held')->get())->toBeTrue();

    $replay = $store->claim($scope, $key, 'fp', $now);
    expect($replay->outcome)->toBe(ClaimOutcome::Replay);
});

it('conflicts when only the lock is held and no record exists', function (): void {
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440011';

    expect($repository->getStore()->lock($scope->cacheKey($key).':lock', 30, 'held')->get())->toBeTrue();
    expect($store->claim($scope, $key, 'fp', $now)->outcome)->toBe(ClaimOutcome::Conflict);
});

it('takes over at the exact stale boundary and records expires_at', function (): void {
    [$store, $repository] = cacheIdempotencyStore(ttlSeconds: 90, staleAfterSeconds: 5);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440012';

    $first = $store->claim($scope, $key, 'fp', $now);
    $repository->getStore()->restoreLock($scope->cacheKey($key).':lock', (string) $first->lease)->forceRelease();

    $boundary = $now->modify('+5 seconds');
    $takeover = $store->claim($scope, $key, 'fp-2', $boundary);
    expect($takeover->outcome)->toBe(ClaimOutcome::New);

    /** @var array<string, mixed> $record */
    $record = $repository->get($scope->cacheKey($key));
    expect($record['expires_at'])->toBe($boundary->getTimestamp() + 90)
        ->and(array_key_exists('expires_at', $record))->toBeTrue();
});

it('treats missing started_at as zero when judging freshness', function (): void {
    [$store, $repository] = cacheIdempotencyStore(staleAfterSeconds: 5);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('@5');
    $key = '550e8400-e29b-41d4-a716-446655440013';

    $repository->put($scope->cacheKey($key), [
        'fingerprint' => 'fp',
        'lease' => 'old',
        'status' => 'in_progress',
    ], 60);

    expect($store->claim($scope, $key, 'fp', $now)->outcome)->toBe(ClaimOutcome::New);

    $repository->put($scope->cacheKey($key), [
        'fingerprint' => 'fp',
        'lease' => 'old',
        'status' => 'in_progress',
        'started_at' => new stdClass,
    ], 60);
    $repository->getStore()->lock($scope->cacheKey($key).':lock', 1, 'x')->forceRelease();

    expect($store->claim($scope, $key, 'fp', $now)->outcome)->toBe(ClaimOutcome::New);
});

it('requires an empty-string lease to fail closed on complete with the missing-lease message', function (): void {
    [$store] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440014';

    try {
        $store->complete(
            new IdempotencyClaim(ClaimOutcome::New, null, null, $scope, $key),
            new ReplayableResponse(200, [], '{}'),
            $now,
        );
        expect(false)->toBeTrue();
    } catch (IdempotencyStoreException $exception) {
        expect($exception->getMessage())->toBe('Missing lease on idempotency claim.');
    }

    try {
        $store->complete(
            new IdempotencyClaim(ClaimOutcome::New, null, '', $scope, $key),
            new ReplayableResponse(200, [], '{}'),
            $now,
        );
        expect(false)->toBeTrue();
    } catch (IdempotencyStoreException $exception) {
        expect($exception->getMessage())->toBe('Missing lease on idempotency claim.');
    }
});

it('releases the scoped lock after complete and abandon', function (): void {
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440015';

    $claim = $store->claim($scope, $key, 'fp', $now);
    $store->complete($claim, new ReplayableResponse(200, [], '{}'), $now);
    expect($repository->getStore()->lock($scope->cacheKey($key).':lock', 1, 'probe')->get())->toBeTrue();

    $again = $store->claim($scope, '550e8400-e29b-41d4-a716-446655440016', 'fp', $now);
    $store->abandon($again, $now);
    expect($repository->getStore()->lock($scope->cacheKey('550e8400-e29b-41d4-a716-446655440016').':lock', 1, 'probe2')->get())->toBeTrue();
});

it('does not abandon in-progress rows for non-new outcomes even with a matching lease', function (): void {
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440017';

    $claim = $store->claim($scope, $key, 'fp', $now);
    $store->abandon(
        new IdempotencyClaim(ClaimOutcome::Replay, null, (string) $claim->lease, $scope, $key),
        $now,
    );

    expect($repository->get($scope->cacheKey($key)))->not->toBeNull();
});

it('replays a completed record discovered after the lock is acquired', function (): void {
    $inner = new ArrayStore;
    $storeBackend = new class($inner) implements LockProvider, Store
    {
        private int $recordGets = 0;

        public function __construct(private ArrayStore $inner) {}

        public function get($key): mixed
        {
            $this->recordGets++;

            if ($this->recordGets === 1) {
                return null;
            }

            if ($this->recordGets === 2) {
                return [
                    'fingerprint' => 'fp',
                    'lease' => 'other',
                    'status' => 'completed',
                    'status_code' => 200,
                    'headers' => ['X-A' => '1'],
                    'body' => '{"race":true}',
                ];
            }

            return $this->inner->get($key);
        }

        /** @param  array<int, string>  $keys */
        public function many(array $keys): array
        {
            return $this->inner->many($keys);
        }

        public function put($key, $value, $seconds): bool
        {
            return $this->inner->put($key, $value, $seconds);
        }

        /** @param  array<string, mixed>  $values */
        public function putMany(array $values, $seconds): bool
        {
            return $this->inner->putMany($values, $seconds);
        }

        public function increment($key, $value = 1): int|bool
        {
            return $this->inner->increment($key, $value);
        }

        public function decrement($key, $value = 1): int|bool
        {
            return $this->inner->decrement($key, $value);
        }

        public function forever($key, $value): bool
        {
            return $this->inner->forever($key, $value);
        }

        public function touch($key, $seconds): bool
        {
            return $this->inner->touch($key, $seconds);
        }

        public function forget($key): bool
        {
            return $this->inner->forget($key);
        }

        public function flush(): bool
        {
            return $this->inner->flush();
        }

        public function getPrefix(): string
        {
            return $this->inner->getPrefix();
        }

        public function lock($name, $seconds = 0, $owner = null): Lock
        {
            return $this->inner->lock($name, $seconds, $owner);
        }

        public function restoreLock($name, $owner): Lock
        {
            return $this->inner->restoreLock($name, $owner);
        }
    };

    $repository = new Repository($storeBackend);
    $store = new CacheIdempotencyStore($repository, 60, 30, 5);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440018';

    $claim = $store->claim($scope, $key, 'fp', $now);
    expect($claim->outcome)->toBe(ClaimOutcome::Replay)
        ->and($claim->replay?->body)->toBe('{"race":true}');

    expect($repository->getStore()->lock($scope->cacheKey($key).':lock', 1, 'probe')->get())->toBeTrue();
});

it('preserves fingerprint coalesce and lease match guards on complete', function (): void {
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440019';

    $claim = $store->claim($scope, $key, 'fp', $now);
    $repository->put($scope->cacheKey($key), [
        'lease' => $claim->lease,
        'status' => 'in_progress',
        'started_at' => $now->getTimestamp(),
    ], 60);

    $store->complete($claim, new ReplayableResponse(200, [], '{}'), $now);

    /** @var array<string, mixed> $record */
    $record = $repository->get($scope->cacheKey($key));
    expect($record['fingerprint'])->toBe('')
        ->and($record['lease'])->toBe($claim->lease);
});
