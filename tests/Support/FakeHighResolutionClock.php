<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Tests\Support;

/**
 * Queued nanosecond readings for the `hrtime()` shim in idempotency-hrtime-shim.php.
 * Empty queue means the real clock, so every other test is unaffected.
 */
final class FakeHighResolutionClock
{
    /** @var list<int> */
    private static array $readings = [];

    public static function queue(int ...$nanoseconds): void
    {
        self::$readings = array_values($nanoseconds);
    }

    public static function next(): ?int
    {
        return array_shift(self::$readings);
    }

    public static function reset(): void
    {
        self::$readings = [];
    }
}
