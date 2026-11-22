<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

/**
 * @api Supported Idempotency-Key wire formats.
 */
enum IdempotencyKeyFormat: string
{
    case Uuid = 'uuid';
    case SfString = 'sf-string';
}
