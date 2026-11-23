<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

use Illuminate\Cache\Repository;

/**
 * Last-resort seal for a key whose handler ran but whose record could not be
 * written, neither with the response nor as a sealed record.
 *
 * Without it the record stays "in progress", goes stale, and a retry takes it
 * over and runs the handler a second time. A small separate cache entry often
 * still lands when the record write failed (a database outage behind a working
 * cache, a response too large for one cache item), and claim() checks it first.
 */
final class SealedKeyMarker
{
    private const string SUFFIX = ':sealed';

    public function __construct(
        private Repository $cache,
        private int $ttlSeconds,
    ) {}

    /**
     * @return bool whether the marker was stored
     */
    public function mark(IdempotencyScope $scope, string $key): bool
    {
        try {
            return $this->cache->put($scope->cacheKey($key).self::SUFFIX, true, $this->ttlSeconds);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @throws IdempotencyStoreException when the cache cannot be read
     */
    public function isMarked(IdempotencyScope $scope, string $key): bool
    {
        try {
            return $this->cache->get($scope->cacheKey($key).self::SUFFIX) === true;
        } catch (\Throwable $exception) {
            throw new IdempotencyStoreException('Failed to read idempotency seal.', previous: $exception);
        }
    }
}
