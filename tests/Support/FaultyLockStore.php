<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Tests\Support;

use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Lock;

/**
 * Array cache store whose locks fail on demand, as a Redis outage does mid-request.
 */
final class FaultyLockStore extends ArrayStore
{
    public bool $isAcquireThrowing = false;

    public bool $isReleaseThrowing = false;

    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        if ($this->isAcquireThrowing) {
            throw new \RuntimeException('lock acquire failed');
        }

        if ($this->isReleaseThrowing) {
            return new class($this, $name, $seconds, $owner) extends ArrayLock
            {
                public function release(): bool
                {
                    throw new \RuntimeException('lock release failed');
                }
            };
        }

        return parent::lock($name, $seconds, $owner);
    }

    public function restoreLock($name, $owner): Lock
    {
        if ($this->isReleaseThrowing) {
            throw new \RuntimeException('lock release failed');
        }

        return parent::restoreLock($name, $owner);
    }
}
