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

    $claim = $store->claim($scope, $key, 'fp-1', $now, idempotencyContext());
    expect($claim->outcome)->toBe(ClaimOutcome::New)
        ->and(is_string($claim->lease))->toBeTrue()
        ->and(strlen((string) $claim->lease))->toBe(64);

    $store->complete($claim, new ReplayableResponse(200, ['Content-Type' => 'application/json'], '{}'), $now);

    $replay = $store->claim($scope, $key, 'fp-1', $now, idempotencyContext());
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

    $claim = $store->claim($scope, $key, 'fp-1', $now, idempotencyContext());
    $store->complete($claim, new ReplayableResponse(200, [], 'ok'), $now);

    expect($store->claim($scope, $key, 'fp-2', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::Mismatch);
});

it('conflicts while the lock is held and takes over after the record goes stale', function (): void {
    [$store, $repository] = cacheIdempotencyStore(staleAfterSeconds: 5);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440002';

    $first = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    expect($first->outcome)->toBe(ClaimOutcome::New);
    expect($store->claim($scope, $key, 'fp', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::Conflict);

    $recordKey = $scope->cacheKey($key);
    $lockKey = $recordKey.':lock';
    $repository->getStore()->restoreLock($lockKey, (string) $first->lease)->forceRelease();

    $freshConflict = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    expect($freshConflict->outcome)->toBe(ClaimOutcome::Conflict);

    $staleNow = $now->modify('+10 seconds');
    $takeover = $store->claim($scope, $key, 'fp-takeover', $staleNow, idempotencyContext());
    expect($takeover->outcome)->toBe(ClaimOutcome::New)
        ->and($takeover->lease)->not->toBe($first->lease);
});

it('ignores non-array cache payloads when claiming', function (): void {
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440003';

    $repository->put($scope->cacheKey($key), 'not-an-array', 60);

    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
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

    $replay = $store->claim($scope, $key, 'fp', $now, idempotencyContext());

    expect($replay->outcome)->toBe(ClaimOutcome::Replay)
        ->and($replay->replay?->status)->toBe(201)
        ->and($replay->replay?->body)->toBe('')
        ->and($replay->replay?->headers)->toBe([
            'X-Text' => 'ok',
            'X-List' => 'a, b, ',
        ]);
});

it('defaults headers and body when completed payloads omit them', function (): void {
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440005';

    $repository->put($scope->cacheKey($key), [
        'fingerprint' => 'fp',
        'status' => 'completed',
        'status_code' => 204,
        'headers' => 'not-array',
        'body' => null,
    ], 60);

    $replay = $store->claim($scope, $key, 'fp', $now, idempotencyContext());

    expect($replay->outcome)->toBe(ClaimOutcome::Replay)
        ->and($replay->replay?->status)->toBe(204)
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

    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
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

    $claim = $store->claim($scope, $key, 'fp-keep', $now, idempotencyContext());
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

    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    $store->abandon(new IdempotencyClaim(ClaimOutcome::New, null, 'wrong', $scope, $key), $now);
    expect($repository->get($scope->cacheKey($key)))->not->toBeNull();

    $store->abandon($claim, $now);
    expect($repository->get($scope->cacheKey($key)))->toBeNull();

    $again = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    expect($again->outcome)->toBe(ClaimOutcome::New);
});

it('holds the lock on the scoped :lock key so a wrong lock name does not conflict', function (): void {
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440009';

    $wrongLock = $repository->getStore()->lock($scope->cacheKey($key), 30, 'foreign');
    expect($wrongLock->get())->toBeTrue();

    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    expect($claim->outcome)->toBe(ClaimOutcome::New);

    $rightLock = $repository->getStore()->lock($scope->cacheKey($key).':lock', 30, 'blocker');
    expect($rightLock->get())->toBeFalse();
});

it('returns replay before contending for the lock on completed records', function (): void {
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440010';

    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    $store->complete($claim, new ReplayableResponse(200, [], 'ok'), $now);

    expect($repository->getStore()->lock($scope->cacheKey($key).':lock', 30, 'held')->get())->toBeTrue();

    $replay = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    expect($replay->outcome)->toBe(ClaimOutcome::Replay);
});

it('conflicts when only the lock is held and no record exists', function (): void {
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440011';

    expect($repository->getStore()->lock($scope->cacheKey($key).':lock', 30, 'held')->get())->toBeTrue();
    expect($store->claim($scope, $key, 'fp', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::Conflict);
});

it('takes over at the exact stale boundary and records expires_at', function (): void {
    [$store, $repository] = cacheIdempotencyStore(ttlSeconds: 90, staleAfterSeconds: 5);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440012';

    $first = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    $repository->getStore()->restoreLock($scope->cacheKey($key).':lock', (string) $first->lease)->forceRelease();

    $boundary = $now->modify('+5 seconds');
    $takeover = $store->claim($scope, $key, 'fp-2', $boundary, idempotencyContext());
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

    expect($store->claim($scope, $key, 'fp', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::New);

    $repository->put($scope->cacheKey($key), [
        'fingerprint' => 'fp',
        'lease' => 'old',
        'status' => 'in_progress',
        'started_at' => new stdClass,
    ], 60);
    $repository->getStore()->lock($scope->cacheKey($key).':lock', 1, 'x')->forceRelease();

    expect($store->claim($scope, $key, 'fp', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::New);
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

    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    $store->complete($claim, new ReplayableResponse(200, [], '{}'), $now);
    expect($repository->getStore()->lock($scope->cacheKey($key).':lock', 1, 'probe')->get())->toBeTrue();

    $again = $store->claim($scope, '550e8400-e29b-41d4-a716-446655440016', 'fp', $now, idempotencyContext());
    $store->abandon($again, $now);
    expect($repository->getStore()->lock($scope->cacheKey('550e8400-e29b-41d4-a716-446655440016').':lock', 1, 'probe2')->get())->toBeTrue();
});

it('does not abandon in-progress rows for non-new outcomes even with a matching lease', function (): void {
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440017';

    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
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
            // The sealed-key marker is read first and is not part of the race.
            if (str_ends_with($key, ':sealed')) {
                return $this->inner->get($key);
            }

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

    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    expect($claim->outcome)->toBe(ClaimOutcome::Replay)
        ->and($claim->replay?->body)->toBe('{"race":true}');

    expect($repository->getStore()->lock($scope->cacheKey($key).':lock', 1, 'probe')->get())->toBeTrue();
});

it('preserves fingerprint coalesce and lease match guards on complete', function (): void {
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440019';

    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
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

it('seals unreplayable on complete() put failure so retries never re-run as New', function (): void {
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

    $store = new CacheIdempotencyStore($repository, 3600, 30, 5);
    $scope = new IdempotencyScope('bucket', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440302';
    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    $repository->shouldFailCompletedBodyPuts = true;

    expect(fn () => $store->complete(
        $claim,
        new ReplayableResponse(201, [], '{"ok":true}'),
        $now,
    ))->toThrow(IdempotencyStoreException::class);

    $again = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    expect($again->outcome)->toBe(ClaimOutcome::NotReplayable);
});

it('seals unreplayable when complete() put returns false', function (): void {
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
                return false;
            }

            return parent::put($key, $value, $seconds);
        }
    };

    $store = new CacheIdempotencyStore($repository, 3600, 30, 5);
    $scope = new IdempotencyScope('bucket', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440303';
    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    $repository->shouldFailCompletedBodyPuts = true;

    expect(fn () => $store->complete(
        $claim,
        new ReplayableResponse(201, [], '{"ok":true}'),
        $now,
    ))->toThrow(IdempotencyStoreException::class);

    $again = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    expect($again->outcome)->toBe(ClaimOutcome::NotReplayable);
});

it('seals unreplayable completed rows so retries never re-run as New', function (): void {
    [$store, $repository] = cacheIdempotencyStore(ttlSeconds: 120);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440020';

    $claim = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    $store->sealUnreplayable($claim, $now);

    /** @var array<string, mixed> $record */
    $record = $repository->get($scope->cacheKey($key));
    expect($record['status'])->toBe('completed')
        ->and($record['body'])->toBeNull()
        ->and($record['status_code'])->toBeNull()
        ->and($record['headers'])->toBeNull()
        ->and($record['expires_at'])->toBe($now->getTimestamp() + 120);

    $again = $store->claim($scope, $key, 'fp', $now, idempotencyContext());
    expect($again->outcome)->toBe(ClaimOutcome::NotReplayable);

    expect(fn () => $store->sealUnreplayable(
        new IdempotencyClaim(ClaimOutcome::Replay, null, 'lease', $scope, $key),
        $now,
    ))->toThrow(IdempotencyStoreException::class);

    expect(fn () => $store->sealUnreplayable(
        new IdempotencyClaim(ClaimOutcome::New, null, null, $scope, $key),
        $now,
    ))->toThrow(IdempotencyStoreException::class);

    $fresh = $store->claim($scope, '550e8400-e29b-41d4-a716-446655440021', 'fp', $now, idempotencyContext());
    expect(fn () => $store->sealUnreplayable(
        new IdempotencyClaim(ClaimOutcome::New, null, 'wrong-lease', $scope, '550e8400-e29b-41d4-a716-446655440021'),
        $now,
    ))->toThrow(IdempotencyStoreException::class);
    expect($fresh->outcome)->toBe(ClaimOutcome::New);
});

it('lets a completed record past expires_at be claimed again, but not one at expires_at', function (): void {
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $completedRecord = static fn (int $expiresAt): array => [
        'fingerprint' => 'fp',
        'status' => 'completed',
        'status_code' => 201,
        'headers' => [],
        'body' => '{}',
        'expires_at' => $expiresAt,
    ];

    $repository->put($scope->cacheKey('expired'), $completedRecord($now->getTimestamp() - 1), 60);
    $repository->put($scope->cacheKey('boundary'), $completedRecord($now->getTimestamp()), 60);

    expect($store->claim($scope, 'expired', 'fp', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::New)
        ->and($store->claim($scope, 'boundary', 'fp', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::Replay);
});

it('never takes over a live in-progress claim because its expires_at passed', function (): void {
    [$store, $repository] = cacheIdempotencyStore(staleAfterSeconds: 60);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

    $repository->put($scope->cacheKey('running'), [
        'fingerprint' => 'fp',
        'lease' => 'other-worker',
        'status' => 'in_progress',
        'started_at' => $now->getTimestamp() - 10,
        'expires_at' => $now->getTimestamp() - 1,
    ], 60);

    expect($store->claim($scope, 'running', 'fp', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::Conflict);
});

it('takes over an in-progress claim with no readable start time', function (): void {
    [$store, $repository] = cacheIdempotencyStore(staleAfterSeconds: 60);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

    $repository->put($scope->cacheKey('orphan'), ['fingerprint' => 'fp', 'lease' => 'gone', 'status' => 'in_progress'], 60);

    expect($store->claim($scope, 'orphan', 'fp', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::New);
});

it('stores a sealed record without a response and releases the claim lock', function (): void {
    [$store, $repository] = cacheIdempotencyStore(ttlSeconds: 3600);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $claim = $store->claim($scope, 'sealed', 'fp', $now, idempotencyContext());

    $store->sealUnreplayable($claim, $now->modify('+5 seconds'));

    expect($repository->get($scope->cacheKey('sealed')))->toBe([
        'fingerprint' => 'fp',
        'lease' => $claim->lease,
        'status' => 'completed',
        'status_code' => null,
        'headers' => null,
        'body' => null,
        'started_at' => $now->getTimestamp(),
        'expires_at' => $now->getTimestamp() + 5 + 3600,
    ])
        ->and($repository->getStore()->lock($scope->cacheKey('sealed').':lock', 30, 'next')->get())->toBeTrue()
        ->and($store->claim($scope, 'sealed', 'fp', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::NotReplayable);
});

it('stores a completed record with its response and start time', function (): void {
    [$store, $repository] = cacheIdempotencyStore(ttlSeconds: 3600);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $claim = $store->claim($scope, 'done', 'fp', $now, idempotencyContext());

    $store->complete($claim, new ReplayableResponse(201, ['Location' => '/items/1'], '{"id":1}'), $now->modify('+2 seconds'));

    expect($repository->get($scope->cacheKey('done')))->toBe([
        'fingerprint' => 'fp',
        'lease' => $claim->lease,
        'status' => 'completed',
        'status_code' => 201,
        'headers' => ['Location' => '/items/1'],
        'body' => '{"id":1}',
        'started_at' => $now->getTimestamp(),
        'expires_at' => $now->getTimestamp() + 2 + 3600,
    ]);
});

it('leaves a marker alone when abandon carries another lease or none', function (?string $lease): void {
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $store->claim($scope, 'held', 'fp', $now, idempotencyContext());

    $store->abandon(new IdempotencyClaim(ClaimOutcome::New, null, $lease, $scope, 'held'), $now);

    expect($repository->get($scope->cacheKey('held')))->toBeArray();
})->with(['foreign lease' => ['someone-else'], 'empty lease' => [''], 'no lease' => [null]]);

it('replays a numeric-string status code and joins scalar header lists', function (): void {
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

    $repository->put($scope->cacheKey('legacy'), [
        'fingerprint' => 'fp',
        'status' => 'completed',
        'status_code' => '201',
        'headers' => ['X-Versions' => [1, 2]],
        'body' => '{}',
    ], 60);

    $replay = $store->claim($scope, 'legacy', 'fp', $now, idempotencyContext())->replay;

    expect($replay?->status)->toBe(201)
        ->and($replay?->headers)->toBe(['X-Versions' => '1, 2']);
});

it('never replays a completed record whose status code is unreadable', function (mixed $statusCode): void {
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');

    $repository->put($scope->cacheKey('corrupt'), [
        'fingerprint' => 'fp',
        'status' => 'completed',
        'status_code' => $statusCode,
        'headers' => [],
        'body' => 'stored',
    ], 60);

    // The handler already ran; replaying a guessed 200 or re-running it would both lie.
    expect($store->claim($scope, 'corrupt', 'fp', new DateTimeImmutable('2026-01-01T00:00:00Z'), idempotencyContext())->outcome)
        ->toBe(ClaimOutcome::NotReplayable);
})->with(['not numeric' => ['abc'], 'missing' => [null], 'not a scalar' => [new stdClass]]);

it('marks a fresh claim as in progress in the cache', function (): void {
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');

    $store->claim($scope, 'fresh', 'fp', new DateTimeImmutable('2026-01-01T00:00:00Z'), idempotencyContext());

    expect($repository->get($scope->cacheKey('fresh'))['status'] ?? null)->toBe('in_progress');
});

it('versions every record it writes (S10)', function (): void {
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');

    $claim = $store->claim($scope, 'k1', 'fp', $now, idempotencyContext());
    $inProgress = $repository->get($scope->cacheKey('k1'))['v'] ?? null;
    $store->complete($claim, new ReplayableResponse(201, [], 'done'), $now);
    $completed = $repository->get($scope->cacheKey('k1'))['v'] ?? null;
    $store->sealUnreplayable($store->claim($scope, 'k2', 'fp', $now, idempotencyContext()), $now);
    $sealed = $repository->get($scope->cacheKey('k2'))['v'] ?? null;

    expect([$inProgress, $completed, $sealed])->toBe([1, 1, 1]);
});

it('reads an unversioned record as version 1', function (): void {
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $repository->put($scope->cacheKey('legacy'), [
        'fingerprint' => 'fp', 'status' => 'completed', 'status_code' => 201, 'headers' => [], 'body' => 'done',
    ], 60);

    expect($store->claim($scope, 'legacy', 'fp', new DateTimeImmutable, idempotencyContext())->outcome)->toBe(ClaimOutcome::Replay);
});

it('never replays nor re-runs a record of a payload version it does not know', function (mixed $version): void {
    // A newer package wrote it (or a rollback happened): the handler ran, the shape is unknown.
    [$store, $repository] = cacheIdempotencyStore();
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $repository->put($scope->cacheKey('future'), [
        'v' => $version, 'fingerprint' => 'fp', 'status' => 'completed', 'status_code' => 201, 'headers' => [], 'body' => 'done',
    ], 60);

    expect($store->claim($scope, 'future', 'fp', new DateTimeImmutable, idempotencyContext())->outcome)->toBe(ClaimOutcome::NotReplayable);
})->with([2, 0, '1']);
