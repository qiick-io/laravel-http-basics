<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as FrameworkTrustProxies;

/**
 * Stands in for a product's conventional `App\Http\Middleware\TrustProxies`.
 * Its proxies come from a static the test sets and resets, so loading it never
 * changes another test's trust decision.
 */
class TrustProxies extends FrameworkTrustProxies
{
    /** @var list<string>|string|null */
    public static array|string|null $testProxies = null;

    public function __construct()
    {
        $this->proxies = self::$testProxies;
    }
}
