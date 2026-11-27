<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\RequestId;

/** @api */
enum RequestIdAcceptFrom: string
{
    case TrustedProxy = 'trusted_proxy'; // default: keep inbound only if TrustedProxyDetector says so
    case Any = 'any';                   // partner APIs; document spoof risk
    case Never = 'never';               // always generate
}
