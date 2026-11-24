<?php

declare(strict_types=1);

/*
 * Review of #21: a lock failure (Redis down mid-request) must surface as a store
 * failure (503), and failing to release a lock after the work is done must not
 * turn a finished request into an error: the lock runs out on its own.
 */

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\HttpBasics\Contracts\IdempotencyStore;
use Qiick\HttpBasics\Idempotency\CacheIdempotencyStore;
use Qiick\HttpBasics\Idempotency\ClaimOutcome;
use Qiick\HttpBasics\Idempotency\DatabaseIdempotencyStore;
use Qiick\HttpBasics\Idempotency\IdempotencyScope;
use Qiick\HttpBasics\Idempotency\IdempotencyStoreException;
use Qiick\HttpBasics\Idempotency\ReplayableResponse;
use Qiick\HttpBasics\Idempotency\SealedKeyMarker;
use Qiick\HttpBasics\Tests\Support\FaultyLockStore;

beforeEach(function (): void {
    Schema::dropIfExists('idempotency_records');
    /** @var Migration $migration */
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_22_000001_create_idempotency_records_table.php';
    $migration->up();
});

/**
 * @return array{IdempotencyStore, FaultyLockStore}
 */
function lockOutageStore(string $kind): array
{
    $locks = new FaultyLockStore;
    $cache = new Repository($locks);
    $store = $kind === 'cache'
        ? new CacheIdempotencyStore($cache, 3600, 30, 5)
        : new DatabaseIdempotencyStore(DB::connection(), $cache, 'idempotency_records', 3600, 30, 5);

    return [$store, $locks];
}

it('turns a lock that cannot be taken into a store failure', function (string $kind): void {
    [$store, $locks] = lockOutageStore($kind);
    $locks->isAcquireThrowing = true;

    expect(fn () => $store->claim(new IdempotencyScope('b', 'POST', '/x'), 'k', 'fp', new DateTimeImmutable, idempotencyContext()))
        ->toThrow(IdempotencyStoreException::class, 'Failed to acquire idempotency lock.');
})->with(['cache', 'database']);

it('finishes a claim even when its lock cannot be released', function (string $kind, string $finish): void {
    [$store, $locks] = lockOutageStore($kind);
    $scope = new IdempotencyScope('b', 'POST', '/x');
    $now = new DateTimeImmutable;
    $claim = $store->claim($scope, 'k', 'fp', $now, idempotencyContext());
    $locks->isReleaseThrowing = true;

    match ($finish) {
        'complete' => $store->complete($claim, new ReplayableResponse(201, [], 'done'), $now),
        'seal' => $store->sealUnreplayable($claim, $now),
        'abandon' => $store->abandon($claim, $now),
    };

    // The unreleased lock runs out after lock_ttl_seconds.
    $locks->isReleaseThrowing = false;
    $this->travel(31)->seconds();
    $outcome = $store->claim($scope, 'k', 'fp', $now->modify('+1 minute'), idempotencyContext())->outcome;

    expect($outcome)->toBe(match ($finish) {
        'complete' => ClaimOutcome::Replay,
        'seal' => ClaimOutcome::NotReplayable,
        'abandon' => ClaimOutcome::New,
    });
})->with(['cache', 'database'])->with(['complete', 'seal', 'abandon']);

it('re-checks the sealed marker once it holds the lock', function (string $kind): void {
    // Another request seals the key between the first look and the lock.
    $cache = new class(new ArrayStore) extends Repository
    {
        private int $sealedReads = 0;

        public function get($key, $default = null): mixed
        {
            if (is_string($key) && str_ends_with($key, ':sealed')) {
                return ++$this->sealedReads > 1;
            }

            return parent::get($key, $default);
        }
    };
    $store = $kind === 'cache'
        ? new CacheIdempotencyStore($cache, 3600, 30, 5)
        : new DatabaseIdempotencyStore(DB::connection(), $cache, 'idempotency_records', 3600, 30, 5);
    $scope = new IdempotencyScope('b', 'POST', '/x');

    expect($store->claim($scope, 'k', 'fp', new DateTimeImmutable, idempotencyContext())->outcome)->toBe(ClaimOutcome::NotReplayable)
        // The lock is released for whoever comes next.
        ->and($cache->getStore()->lock($scope->cacheKey('k').':lock', 1, 'probe')->get())->toBeTrue();
})->with(['cache', 'database']);

it('releases the lock when the sealed marker cannot be read under it', function (string $kind): void {
    $cache = new class(new ArrayStore) extends Repository
    {
        private int $sealedReads = 0;

        public function get($key, $default = null): mixed
        {
            if (is_string($key) && str_ends_with($key, ':sealed') && ++$this->sealedReads > 1) {
                throw new RuntimeException('cache read failed');
            }

            return parent::get($key, $default);
        }
    };
    $store = $kind === 'cache'
        ? new CacheIdempotencyStore($cache, 3600, 30, 5)
        : new DatabaseIdempotencyStore(DB::connection(), $cache, 'idempotency_records', 3600, 30, 5);
    $scope = new IdempotencyScope('b', 'POST', '/x');

    expect(fn () => $store->claim($scope, 'k', 'fp', new DateTimeImmutable, idempotencyContext()))->toThrow(IdempotencyStoreException::class)
        ->and($cache->getStore()->lock($scope->cacheKey('k').':lock', 1, 'probe')->get())->toBeTrue();
})->with(['cache', 'database']);

it('answers a sealed key at once, even while another request holds its lock', function (string $kind): void {
    $cache = new Repository(new ArrayStore);
    $store = $kind === 'cache'
        ? new CacheIdempotencyStore($cache, 3600, 30, 5)
        : new DatabaseIdempotencyStore(DB::connection(), $cache, 'idempotency_records', 3600, 30, 5);
    $scope = new IdempotencyScope('b', 'POST', '/x');
    (new SealedKeyMarker($cache, 3600))->mark($scope, 'k');
    $cache->getStore()->lock($scope->cacheKey('k').':lock', 30, 'other-request')->get();

    expect($store->claim($scope, 'k', 'fp', new DateTimeImmutable, idempotencyContext())->outcome)->toBe(ClaimOutcome::NotReplayable);
})->with(['cache', 'database']);
