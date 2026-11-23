<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

use Illuminate\Support\Str;

/**
 * @api What a store may record about a claim beyond its key and fingerprint.
 *
 * Part of the store contract from 1.0 (standards review M5), so stores can fill
 * the record's audit columns without a breaking interface change later. Each
 * value is checked here against what the reference schema can hold, so a store
 * never fails on a write the middleware could have refused up front.
 */
final readonly class IdempotencyContext
{
    /**
     * @param  int  $fingerprintVersion  1 = method, path, query, canonical JSON body and
     *                                   the served locale
     * @param  array<string, mixed>  $metadata  namespaced keys (`request.content_digest`);
     *                                          never secrets, never `tracestate`
     *
     * @throws \InvalidArgumentException when a value does not fit the record
     */
    public function __construct(
        public IdempotencyKeyFormat $keyFormat,
        public int $fingerprintVersion = 1,
        public ?string $requestId = null,
        public ?string $traceId = null,
        public ?string $credentialPublicId = null,
        public array $metadata = [],
    ) {
        if ($fingerprintVersion < 1) {
            throw new \InvalidArgumentException('The fingerprint version starts at 1.');
        }

        if ($requestId !== null && strlen($requestId) > 128) {
            throw new \InvalidArgumentException('The request id holds at most 128 characters.');
        }

        // W3C Trace Context: 32 lowercase hex digits, never all zeros.
        if ($traceId !== null && (preg_match('/\A[0-9a-f]{32}\z/', $traceId) !== 1 || $traceId === str_repeat('0', 32))) {
            throw new \InvalidArgumentException('The trace id must be a W3C trace-id.');
        }

        if ($credentialPublicId !== null && ! Str::isUuid($credentialPublicId)) {
            throw new \InvalidArgumentException('The credential public id must be a UUID.');
        }

        try {
            json_encode($metadata, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The metadata must be encodable as JSON.', previous: $exception);
        }
    }
}
