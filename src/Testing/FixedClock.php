<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Testing;

use DateTimeImmutable;

/**
 * Fixed wall clock for idempotency stale/takeover tests.
 */
final class FixedClock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify('+'.$seconds.' seconds');
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
