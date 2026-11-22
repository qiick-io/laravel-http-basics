<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Uploads;

/**
 * @api How declared Content-Type vs sniffed MIME are reconciled.
 */
enum MismatchMode: string
{
    case Reject = 'reject';
    case TrustSniffed = 'trust_sniffed';
}
