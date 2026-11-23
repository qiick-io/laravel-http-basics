<?php

declare(strict_types=1);

/*
 * Translated fleet `message` values for idempotency refusals.
 * The stable `error` code is never translated.
 */

return [
    'idempotency_key_required' => 'L\'header Idempotency-Key è obbligatorio.',
    'idempotency_key_invalid' => 'L\'header Idempotency-Key non è valido.',
    'idempotency_payload_mismatch' => 'Il payload della richiesta non corrisponde alla richiesta idempotente originale.',
    'idempotency_in_progress' => 'Una richiesta idempotente con questa chiave è già in corso.',
    'idempotency_not_replayable' => 'La richiesta idempotente è completata ma non può essere riprodotta.',
    'idempotency_unavailable' => 'Lo store di idempotenza non è temporaneamente disponibile.',
    'idempotency_unsupported_media' => 'Le route idempotenti richiedono Content-Type: application/json oppure application/*+json.',
    'validation_failed' => 'I dati forniti non sono validi.',
];
