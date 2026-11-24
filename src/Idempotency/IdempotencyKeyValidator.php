<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

/**
 * Validates client Idempotency-Key values for the configured wire format.
 */
final class IdempotencyKeyValidator
{
    private const string UUID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i';

    /*
     * RFC 9651 sf-string: visible ASCII without CTL/DEL; no leading/trailing SP/HTAB.
     * ponytail: full structured-field parser is not required for v1 key validation.
     */
    private const string SF_STRING_PATTERN = '/\A[\x21\x23-\x27\x2A\x2B\x2D\x2E\x30-\x39\x3A\x3C-\x5B\x5D-\x7E]+\z/';

    public function __construct(
        private IdempotencyKeyFormat $format,
        private int $maxLength = 128,
    ) {}

    public function format(): IdempotencyKeyFormat
    {
        return $this->format;
    }

    public function isValid(?string $key): bool
    {
        // An empty key fails both patterns below.
        if ($key === null) {
            return false;
        }

        if (strlen($key) > $this->maxLength) {
            return false;
        }

        return match ($this->format) {
            IdempotencyKeyFormat::Uuid => preg_match(self::UUID_PATTERN, $key) === 1,
            IdempotencyKeyFormat::SfString => preg_match(self::SF_STRING_PATTERN, $key) === 1,
        };
    }
}
