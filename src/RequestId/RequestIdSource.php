<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\RequestId;

/** @api */
enum RequestIdSource: string
{
    case Generated = 'generated';
    case TrustedProxy = 'trusted_proxy';
    case Client = 'client'; // only when accept_from=any
}
