<?php

declare(strict_types=1);

use Qiick\HttpBasics\Idempotency\IdempotencyContext;
use Qiick\HttpBasics\Idempotency\IdempotencyKeyFormat;

/**
 * The plainest claim context, for tests that only care about the key and fingerprint.
 */
function idempotencyContext(): IdempotencyContext
{
    return new IdempotencyContext(IdempotencyKeyFormat::Uuid);
}
