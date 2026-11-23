<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Tests\Support;

use Illuminate\Cache\Repository;

/**
 * Cache repository that fails on demand, to drive the idempotency stores through
 * outages: a put() that returns false for chosen keys, a put() or get() that throws.
 */
final class FaultyCacheRepository extends Repository
{
    /** @var (\Closure(string): bool)|null */
    public ?\Closure $isPutRefused = null;

    /** @var (\Closure(string): bool)|null */
    public ?\Closure $isPutThrowing = null;

    public bool $isGetThrowing = false;

    public function get($key, $default = null): mixed
    {
        if ($this->isGetThrowing) {
            throw new \RuntimeException('cache read failed');
        }

        return parent::get($key, $default);
    }

    public function put($key, $value, $ttl = null)
    {
        if ($this->isPutThrowing !== null && is_string($key) && ($this->isPutThrowing)($key)) {
            throw new \RuntimeException('cache write failed');
        }

        if ($this->isPutRefused !== null && is_string($key) && ($this->isPutRefused)($key)) {
            return false;
        }

        return parent::put($key, $value, $ttl);
    }
}
