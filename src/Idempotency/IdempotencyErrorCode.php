<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

/**
 * @api Stable wire codes for idempotency refusals (fleet `error` field).
 */
enum IdempotencyErrorCode: string
{
    case KeyRequired = 'idempotency_key_required';
    case KeyInvalid = 'idempotency_key_invalid';
    case PayloadMismatch = 'idempotency_payload_mismatch';
    case InProgress = 'idempotency_in_progress';
    case Unavailable = 'idempotency_unavailable';
    case UnsupportedMedia = 'idempotency_unsupported_media';
}
