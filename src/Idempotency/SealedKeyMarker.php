<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

use Illuminate\Cache\Repository;

/**
 * @internal Last-resort seal for a key whose handler ran but whose record could not be
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

    public static function key(string $bucket, string $keyHash): string
    {
        return IdempotencyScope::cacheKeyFromHash($bucket, $keyHash).self::SUFFIX;
    }

    /**
     * @return bool whether the marker was stored
     */
    public function mark(IdempotencyScope $scope, string $key): bool
    {
        try {
            return $this->cache->put(self::key($scope->bucket, hash('sha256', $key)), true, $this->ttlSeconds);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @phpstan-impure another request may mark the key between two reads
     *
     * @throws IdempotencyStoreException when the cache cannot be read
     */
    public function isMarked(IdempotencyScope $scope, string $key): bool
    {
        try {
            return $this->cache->get(self::key($scope->bucket, hash('sha256', $key))) === true;
        } catch (\Throwable $exception) {
            throw new IdempotencyStoreException('Failed to read idempotency seal.', previous: $exception);
        }
    }
}
