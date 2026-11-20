<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\RequestId;

use Illuminate\Http\Request;
use Qiick\HttpBasics\Contracts\TrustedProxyDetector;

/**
 * Trusts the connection only when Symfony/Laravel TrustProxies marks REMOTE_ADDR
 * as a configured proxy hop ({@see Request::isFromTrustedProxy()}).
 */
final class SymfonyTrustedProxyDetector implements TrustedProxyDetector
{
    public function isTrusted(Request $request): bool
    {
        return $request->isFromTrustedProxy();
    }
}
