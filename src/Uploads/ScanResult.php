<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Uploads;

/**
 * @api Malware scan outcome carried on every ValidatedUpload.
 *
 * `not_scanned` is the NullMalwareScanner result — never claim a scan that did not run.
 */
enum ScanResult: string
{
    case NotScanned = 'not_scanned';
    case Clean = 'clean';
    case Infected = 'infected';
    case Failed = 'failed';
}
