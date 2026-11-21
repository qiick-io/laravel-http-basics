<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Testing;

use Illuminate\Http\Request;
use Qiick\HttpBasics\Contracts\TrustedProxyDetector;

/** @api Test double: no connection is a trusted proxy hop. */
final class NeverTrustedProxyDetector implements TrustedProxyDetector
{
    public function isTrusted(Request $request): bool
    {
        return false;
    }
}
