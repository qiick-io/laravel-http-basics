<?php

declare(strict_types=1);

/*
 * Red-team finding 3: every cache failure inside CacheIdempotencyStore must surface
 * as IdempotencyStoreException (a 503 with Retry-After), and a marker the cache did
 * not store must never let the handler run.
 */

use Illuminate\Cache\ArrayStore;
use Qiick\HttpBasics\Idempotency\CacheIdempotencyStore;
use Qiick\HttpBasics\Idempotency\ClaimOutcome;
use Qiick\HttpBasics\Idempotency\IdempotencyClaim;
use Qiick\HttpBasics\Idempotency\IdempotencyScope;
use Qiick\HttpBasics\Idempotency\IdempotencyStoreException;
use Qiick\HttpBasics\Idempotency\ReplayableResponse;
use Qiick\HttpBasics\Tests\Support\FaultyCacheRepository;

/**
 * @return array{CacheIdempotencyStore, FaultyCacheRepository, IdempotencyScope}
 */
function outageCacheStore(): array
{
    $repository = new FaultyCacheRepository(new ArrayStore);

    return [
        new CacheIdempotencyStore($repository, ttlSeconds: 3600, lockTtlSeconds: 30, staleAfterSeconds: 5),
        $repository,
        new IdempotencyScope('bucket-a', 'POST', '/items'),
    ];
}

function isLockFree(FaultyCacheRepository $repository, IdempotencyScope $scope, string $key): bool
{
    return $repository->getStore()->lock($scope->cacheKey($key).':lock', 1, 'probe')->get();
}

it('turns a failed record read in claim() into a store failure', function (): void {
    [$store, $repository, $scope] = outageCacheStore();
    $repository->isGetThrowing = true;

    expect(fn () => $store->claim($scope, 'k', 'fp', new DateTimeImmutable, idempotencyContext()))->toThrow(IdempotencyStoreException::class);
});

it('never answers New when the in-progress marker was not stored', function (Closure $breakWrites): void {
    [$store, $repository, $scope] = outageCacheStore();
    $breakWrites($repository);

    expect(fn () => $store->claim($scope, 'k', 'fp', new DateTimeImmutable, idempotencyContext()))
        ->toThrow(IdempotencyStoreException::class, 'Failed to write idempotency marker.')
        ->and($repository->get($scope->cacheKey('k')))->toBeNull()
        ->and(isLockFree($repository, $scope, 'k'))->toBeTrue();
})->with([
    'refused' => [static function (FaultyCacheRepository $repository): void {
        $repository->isPutRefused = static fn (string $key): bool => true;
    }],
    'thrown' => [static function (FaultyCacheRepository $repository): void {
        $repository->isPutThrowing = static fn (string $key): bool => true;
    }],
]);

it('turns a failed record read in complete() and sealUnreplayable() into a store failure', function (): void {
    [$store, $repository, $scope] = outageCacheStore();
    $now = new DateTimeImmutable;
    $claim = $store->claim($scope, 'k', 'fp', $now, idempotencyContext());
    $repository->isGetThrowing = true;

    expect(fn () => $store->complete($claim, new ReplayableResponse(201, [], 'ok'), $now))->toThrow(IdempotencyStoreException::class)
        ->and(fn () => $store->sealUnreplayable($claim, $now))->toThrow(IdempotencyStoreException::class);
});

it('abandons on a best-effort basis when the cache fails, still freeing the lock', function (): void {
    [$store, $repository, $scope] = outageCacheStore();
    $now = new DateTimeImmutable;
    $claim = $store->claim($scope, 'k', 'fp', $now, idempotencyContext());
    $repository->isGetThrowing = true;

    $store->abandon($claim, $now);

    expect(isLockFree($repository, $scope, 'k'))->toBeTrue();
});

it('still abandons normally once the cache works', function (): void {
    [$store, $repository, $scope] = outageCacheStore();
    $now = new DateTimeImmutable;
    $claim = $store->claim($scope, 'k', 'fp', $now, idempotencyContext());

    $store->abandon($claim, $now);

    // A New claim right away means the marker is gone and the lock is free.
    expect($repository->get($scope->cacheKey('k')))->toBeNull()
        ->and($store->claim($scope, 'k', 'fp', $now, idempotencyContext())->outcome)->toBe(ClaimOutcome::New);
});

it('leaves a foreign marker and its lock alone on abandon', function (): void {
    [$store, $repository, $scope] = outageCacheStore();
    $now = new DateTimeImmutable;
    $store->claim($scope, 'k', 'fp', $now, idempotencyContext());

    $store->abandon(new IdempotencyClaim(ClaimOutcome::New, null, 'someone-else', $scope, 'k'), $now);

    expect($repository->get($scope->cacheKey('k')))->toBeArray()
        ->and(isLockFree($repository, $scope, 'k'))->toBeFalse();
});

it('keeps the cache error as the cause of a failed marker write', function (): void {
    [$store, $repository, $scope] = outageCacheStore();
    $repository->isPutThrowing = static fn (string $key): bool => true;

    try {
        $store->claim($scope, 'k', 'fp', new DateTimeImmutable, idempotencyContext());
        $this->fail('The marker write failure must be reported.');
    } catch (IdempotencyStoreException $exception) {
        expect($exception->getPrevious()?->getMessage())->toBe('cache write failed');
    }
});
