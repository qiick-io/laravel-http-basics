<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Contracts;

use Illuminate\Http\Request;

/**
 * @api Whether the current request arrives via a trusted proxy hop.
 */
interface TrustedProxyDetector
{
    /** True only when Request::ip() / TrustProxies configuration trusts the connection. */
    public function isTrusted(Request $request): bool;
}
