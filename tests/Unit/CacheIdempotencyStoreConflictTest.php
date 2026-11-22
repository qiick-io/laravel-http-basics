<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Qiick\HttpBasics\Idempotency\CacheIdempotencyStore;
use Qiick\HttpBasics\Idempotency\ClaimOutcome;
use Qiick\HttpBasics\Idempotency\IdempotencyClaim;
use Qiick\HttpBasics\Idempotency\IdempotencyScope;
use Qiick\HttpBasics\Idempotency\IdempotencyStoreException;
use Qiick\HttpBasics\Idempotency\ReplayableResponse;

it('conflicts when the cache lock is already held', function (): void {
    $store = new CacheIdempotencyStore(new Repository(new ArrayStore), 3600, 30, 5);
    $scope = new IdempotencyScope('bucket', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440011';

    $first = $store->claim($scope, $key, 'fp', $now);
    $second = $store->claim($scope, $key, 'fp', $now);

    expect($first->outcome)->toBe(ClaimOutcome::New)
        ->and($second->outcome)->toBe(ClaimOutcome::Conflict);
});

it('rejects completion when the lease token drifts', function (): void {
    $store = new CacheIdempotencyStore(new Repository(new ArrayStore), 3600, 30, 5);
    $scope = new IdempotencyScope('bucket', 'POST', '/items');
    $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $key = '550e8400-e29b-41d4-a716-446655440012';
    $claim = $store->claim($scope, $key, 'fp', $now);

    $tampered = new IdempotencyClaim(
        ClaimOutcome::New,
        null,
        'broken-lease',
        $scope,
        $key,
    );

    $store->complete($tampered, new ReplayableResponse(200, [], '{}'), $now);
})->throws(IdempotencyStoreException::class);
