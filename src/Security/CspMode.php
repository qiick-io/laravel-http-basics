<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Security;

/**
 * @api How Content-Security-Policy is emitted. `both` is an explicit opt-in;
 * products that are not ready for CSP (e.g. missing nonces) use `off`.
 */
enum CspMode: string
{
    case Enforce = 'enforce';
    case ReportOnly = 'report-only';
    case Off = 'off';
    case Both = 'both';
}
