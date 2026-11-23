<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Qiick\HttpBasics\Idempotency\IdempotencyScope;
use Qiick\HttpBasics\Idempotency\IdempotencyStoreException;
use Qiick\HttpBasics\Idempotency\SealedKeyMarker;
use Qiick\HttpBasics\Tests\Support\FaultyCacheRepository;

it('marks one key of one scope for the configured TTL', function (): void {
    $repository = new FaultyCacheRepository(new ArrayStore);
    $marker = new SealedKeyMarker($repository, 120);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');

    expect($marker->mark($scope, 'k1'))->toBeTrue()
        ->and($marker->isMarked($scope, 'k1'))->toBeTrue()
        ->and($marker->isMarked($scope, 'k2'))->toBeFalse()
        ->and($marker->isMarked(new IdempotencyScope('bucket-b', 'POST', '/items'), 'k1'))->toBeFalse()
        // Its own key, next to the record and the lock.
        ->and($repository->get($scope->cacheKey('k1')))->toBeNull()
        ->and($repository->get($scope->cacheKey('k1').':sealed'))->toBeTrue();

    $this->travel(121)->seconds();

    expect($marker->isMarked($scope, 'k1'))->toBeFalse();
});

it('ignores a cached value that is not the marker', function (): void {
    $repository = new FaultyCacheRepository(new ArrayStore);
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');
    $repository->put($scope->cacheKey('k1').':sealed', 'yes', 60);

    expect((new SealedKeyMarker($repository, 60))->isMarked($scope, 'k1'))->toBeFalse();
});

it('reports a marker the cache refused or failed to write', function (): void {
    $refusing = new FaultyCacheRepository(new ArrayStore);
    $refusing->isPutRefused = static fn (string $key): bool => true;
    $throwing = new FaultyCacheRepository(new ArrayStore);
    $throwing->isPutThrowing = static fn (string $key): bool => true;
    $scope = new IdempotencyScope('bucket-a', 'POST', '/items');

    expect((new SealedKeyMarker($refusing, 60))->mark($scope, 'k1'))->toBeFalse()
        ->and((new SealedKeyMarker($throwing, 60))->mark($scope, 'k1'))->toBeFalse();
});

it('turns a cache read failure into a store failure', function (): void {
    $repository = new FaultyCacheRepository(new ArrayStore);
    $repository->isGetThrowing = true;

    expect(fn () => (new SealedKeyMarker($repository, 60))->isMarked(new IdempotencyScope('bucket-a', 'POST', '/items'), 'k1'))
        ->toThrow(IdempotencyStoreException::class, 'Failed to read idempotency seal.');
});
