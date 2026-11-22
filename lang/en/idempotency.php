<?php

declare(strict_types=1);

/*
 * Translated fleet `message` values for idempotency refusals.
 * The stable `error` code is never translated.
 */

return [
    'idempotency_key_required' => 'Idempotency-Key header is required.',
    'idempotency_key_invalid' => 'Idempotency-Key header is invalid.',
    'idempotency_payload_mismatch' => 'Request payload does not match the original idempotent request.',
    'idempotency_in_progress' => 'An idempotent request with this key is already in progress.',
    'idempotency_not_replayable' => 'The idempotent request completed but cannot be replayed.',
    'idempotency_unavailable' => 'Idempotency store is temporarily unavailable.',
    'idempotency_unsupported_media' => 'Idempotent routes require Content-Type: application/json.',
    'validation_failed' => 'The given data was invalid.',
];
