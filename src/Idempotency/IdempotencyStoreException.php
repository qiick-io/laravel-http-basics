<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

/**
 * @api Lease or persistence failure inside the idempotency store (fail closed).
 */
final class IdempotencyStoreException extends \RuntimeException {}
