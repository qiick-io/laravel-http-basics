<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Qiick\HttpBasics\Idempotency\CacheIdempotencyStore;
use Qiick\HttpBasics\Idempotency\ClaimOutcome;
use Qiick\HttpBasics\Idempotency\IdempotencyScope;

/**
 * Race coverage for B1: while the first owner still holds the store lock, a
 * concurrent claim must stay Conflict. After the lock is gone and stale_after
 * elapses without a complete, takeover is New (crash recovery). Route
 * max_handler_seconds that meet or exceed lock TTL are refused by the
 * middleware (see coverage tests), so a long-running handler cannot be
 * configured to outlive the lock.
 */
it('keeps a concurrent claim in Conflict while the lock is held', function (): void {
    $repository = new Repository(new ArrayStore);
    $store = new CacheIdempotencyStore($repository, 3600, lockTtlSeconds: 2, staleAfterSeconds: 1);
    $scope = new IdempotencyScope('bucket', 'POST', '/race');
    $key = '550e8400-e29b-41d4-a716-446655440401';
    $claimedAt = new DateTimeImmutable('2026-01-01T00:00:00Z');

    $first = $store->claim($scope, $key, 'fp', $claimedAt, idempotencyContext());
    $racing = $store->claim($scope, $key, 'fp', $claimedAt->modify('+1 second'), idempotencyContext());

    expect($first->outcome)->toBe(ClaimOutcome::New)
        ->and($racing->outcome)->toBe(ClaimOutcome::Conflict);
});

it('allows stale takeover only after the lock is gone and stale_after elapses', function (): void {
    $repository = new Repository(new ArrayStore);
    $staleAfterSeconds = 1;
    $store = new CacheIdempotencyStore($repository, 3600, lockTtlSeconds: 2, staleAfterSeconds: $staleAfterSeconds);
    $scope = new IdempotencyScope('bucket', 'POST', '/race');
    $key = '550e8400-e29b-41d4-a716-446655440402';
    $claimedAt = new DateTimeImmutable('2026-01-01T00:00:00Z');

    $first = $store->claim($scope, $key, 'fp', $claimedAt, idempotencyContext());
    expect($first->outcome)->toBe(ClaimOutcome::New);

    // Simulate lock expiry without waiting on wall clock (ArrayStore locks are real-time).
    $repository->getStore()->restoreLock($scope->cacheKey($key).':lock', (string) $first->lease)->forceRelease();

    // Lock gone but marker still fresh: second worker must not execute.
    $duringBudget = $store->claim($scope, $key, 'fp', $claimedAt->modify('+'.$staleAfterSeconds.' seconds')->modify('-1 second'), idempotencyContext());
    expect($duringBudget->outcome)->toBe(ClaimOutcome::Conflict);

    // Past stale_after without complete: crash-recovery takeover.
    $afterStale = $store->claim(
        $scope,
        $key,
        'fp',
        $claimedAt->modify('+'.($staleAfterSeconds + 1).' seconds'),
        idempotencyContext(),
    );
    expect($afterStale->outcome)->toBe(ClaimOutcome::New);
});
