<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

use Illuminate\Support\Str;
use Qiick\HttpBasics\RequestId\RequestId;

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
    /** Canonical UUID for an organisation-scoped claim. */
    public ?string $organisationId;

    /**
     * @param  array<string, mixed>  $metadata  namespaced keys (`upload`, `request.content_digest`)
     *                                          holding plain values, at most 4 KiB of JSON;
     *                                          never secrets, never `tracestate`
     *
     * @throws \InvalidArgumentException when a value does not fit the record
     */
    public function __construct(
        public IdempotencyKeyFormat $keyFormat,
        public int $fingerprintVersion = Fingerprinter::VERSION,
        public ?string $requestId = null,
        public ?string $traceId = null,
        public ?string $credentialPublicId = null,
        public array $metadata = [],
        public ?string $userId = null,
        ?string $organisationId = null,
    ) {
        if ($fingerprintVersion < 1) {
            throw new \InvalidArgumentException('The fingerprint version starts at 1.');
        }

        if ($requestId !== null && preg_match(RequestId::PATTERN, $requestId) !== 1) {
            throw new \InvalidArgumentException('The request id must match the request-id pattern (at most 128 characters).');
        }

        // W3C Trace Context: 32 lowercase hex digits, never all zeros.
        if ($traceId !== null && (preg_match('/\A[0-9a-f]{32}\z/', $traceId) !== 1 || $traceId === str_repeat('0', 32))) {
            throw new \InvalidArgumentException('The trace id must be a W3C trace-id.');
        }

        if ($credentialPublicId !== null && ! Str::isUuid($credentialPublicId)) {
            throw new \InvalidArgumentException('The credential public id must be a UUID.');
        }

        if ($userId !== null && (trim($userId) === '' || mb_strlen($userId) > 255)) {
            throw new \InvalidArgumentException('Idempotency ownership identifiers must contain 1 to 255 characters.');
        }

        if ($organisationId !== null && ! Str::isUuid($organisationId)) {
            throw new \InvalidArgumentException('The organisation id must be a UUID.');
        }

        $this->organisationId = $organisationId === null ? null : strtolower($organisationId);

        self::assertStorableMetadata($metadata);
    }

    /**
     * Metadata lands in an audit row: chosen keys and plain values only. An object
     * (a model, say) would serialise fields nobody chose to record.
     *
     * @param  array<mixed>  $metadata
     */
    private static function assertStorableMetadata(array $metadata): void
    {
        foreach (array_keys($metadata) as $key) {
            if (! is_string($key) || preg_match('/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*\z/', $key) !== 1) {
                throw new \InvalidArgumentException('The metadata keys must be namespaced names such as request.content_digest.');
            }
        }

        self::assertPlainValues($metadata);

        try {
            $json = json_encode($metadata, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The metadata must be encodable as JSON.', previous: $exception);
        }

        if (strlen($json) > 4096) {
            throw new \InvalidArgumentException('The metadata encodes to at most 4096 bytes of JSON.');
        }
    }

    /**
     * Below the top level: lists, or maps keyed by plain names (`file_name`); never
     * objects.
     *
     * @param  array<mixed>  $values
     */
    private static function assertPlainValues(array $values): void
    {
        foreach ($values as $value) {
            if (is_object($value)) {
                throw new \InvalidArgumentException('The metadata holds plain values only, never objects.');
            }

            if (! is_array($value)) {
                continue;
            }

            if (! array_is_list($value)) {
                foreach (array_keys($value) as $key) {
                    if (! is_string($key) || preg_match('/\A[a-z][a-z0-9_]*\z/', $key) !== 1) {
                        throw new \InvalidArgumentException('The nested metadata keys must be plain names such as file_name.');
                    }
                }
            }

            self::assertPlainValues($value);
        }
    }
}
