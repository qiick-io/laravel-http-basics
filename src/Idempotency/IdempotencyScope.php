<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

/**
 * @api Identifies one idempotency namespace (bucket + method + path).
 */
final readonly class IdempotencyScope
{
    public function __construct(
        public string $bucket,
        public string $method,
        public string $path,
    ) {}

    public function cacheKey(string $key): string
    {
        return self::cacheKeyFromHash($this->bucket, hash('sha256', $key));
    }

    /** @internal Reconstructs a cache key from the irreversible hash in a database row. */
    public static function cacheKeyFromHash(string $bucket, string $keyHash): string
    {
        return 'http-basics:v1:'.$bucket.':'.$keyHash;
    }
}
