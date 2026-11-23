<?php

declare(strict_types=1);

/*
 * Red-team finding: when the response cannot be stored AND the seal that should
 * replace it cannot be stored either, a retry after stale_after_seconds must still
 * never run the handler again.
 */

use Illuminate\Cache\ArrayStore;
use Qiick\HttpBasics\Idempotency\CacheIdempotencyStore;
use Qiick\HttpBasics\Idempotency\ClaimOutcome;
use Qiick\HttpBasics\Idempotency\IdempotencyScope;
use Qiick\HttpBasics\Idempotency\IdempotencyStoreException;
use Qiick\HttpBasics\Idempotency\ReplayableResponse;
use Qiick\HttpBasics\Tests\Support\FaultyCacheRepository;

it('keeps a cache key unreplayable when neither the response nor the seal can be written to its record', function (): void {
    $repository = new FaultyCacheRepository(new ArrayStore);
    $store = new CacheIdempotencyStore($repository, ttlSeconds: 3600, lockTtlSeconds: 30, staleAfterSeconds: 5);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $claim = $store->claim($scope, 'double-fault', 'fp', $now, idempotencyContext());

    // The record key refuses every write from here on (an item too large, an evicting node).
    $recordKey = $scope->cacheKey('double-fault');
    $repository->isPutRefused = static fn (string $key): bool => $key === $recordKey;

    expect(fn () => $store->complete($claim, new ReplayableResponse(201, [], 'created'), $now))
        ->toThrow(IdempotencyStoreException::class);

    $retry = $store->claim($scope, 'double-fault', 'fp', $now->modify('+1 hour'), idempotencyContext());

    expect($retry->outcome)->toBe(ClaimOutcome::NotReplayable);
});

it('says so when the cache cannot seal at all', function (): void {
    $repository = new FaultyCacheRepository(new ArrayStore);
    $store = new CacheIdempotencyStore($repository, ttlSeconds: 3600, lockTtlSeconds: 30, staleAfterSeconds: 5);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $claim = $store->claim($scope, 'dead-cache', 'fp', $now, idempotencyContext());
    $repository->isPutRefused = static fn (string $key): bool => true;

    // The store must not claim a seal it could not write.
    expect(fn () => $store->complete($claim, new ReplayableResponse(201, [], 'created'), $now))
        ->toThrow(IdempotencyStoreException::class, 'Failed to persist idempotent response and to seal it unreplayable.');
});

it('seals through the marker when writing the sealed record throws', function (): void {
    $repository = new FaultyCacheRepository(new ArrayStore);
    $store = new CacheIdempotencyStore($repository, ttlSeconds: 3600, lockTtlSeconds: 30, staleAfterSeconds: 5);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $claim = $store->claim($scope, 'throwing-record', 'fp', $now, idempotencyContext());
    $recordKey = $scope->cacheKey('throwing-record');
    $repository->isPutThrowing = static fn (string $key): bool => $key === $recordKey;

    $store->sealUnreplayable($claim, $now);

    expect($store->claim($scope, 'throwing-record', 'fp', $now->modify('+1 hour'), idempotencyContext())->outcome)->toBe(ClaimOutcome::NotReplayable);
});
