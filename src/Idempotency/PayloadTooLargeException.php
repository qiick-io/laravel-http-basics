<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

/**
 * @api Request body exceeds configured max_body_bytes (before idempotency store).
 */
final class PayloadTooLargeException extends \RuntimeException {}
