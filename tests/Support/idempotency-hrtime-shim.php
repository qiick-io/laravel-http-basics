<?php

declare(strict_types=1);

/*
 * HandleIdempotencyMiddleware calls hrtime() unqualified, so this namespaced function
 * wins over the global one. It must load before the middleware first runs: PHP caches
 * the fallback to the global function per call site. Loaded from tests/Pest.php.
 */

namespace Qiick\HttpBasics\Idempotency;

use Qiick\HttpBasics\Tests\Support\FakeHighResolutionClock;

function hrtime(bool $asNumber = false): array|int|float|false
{
    return FakeHighResolutionClock::next() ?? \hrtime($asNumber);
}
