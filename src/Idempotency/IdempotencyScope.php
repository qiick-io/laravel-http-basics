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
        return 'http-basics:v1:'.$this->bucket.':'.hash('sha256', $key);
    }
}
