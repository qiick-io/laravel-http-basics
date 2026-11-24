<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

/**
 * @api Validates client Idempotency-Key values for the configured wire format.
 */
final class IdempotencyKeyValidator
{
    private const string UUID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i';

    /*
     * The bare form: visible ASCII without the quote, backslash, comma or semicolon that
     * would need quoting in a structured field. Kept for clients that send the key unquoted.
     */
    private const string SF_STRING_PATTERN = '/\A[\x21\x23-\x27\x2A\x2B\x2D\x2E\x30-\x39\x3A\x3C-\x5B\x5D-\x7E]+\z/';

    /*
     * RFC 9651 §3.3.3 sf-string: DQUOTE *( unescaped / "\\" ( DQUOTE / "\\" ) ) DQUOTE,
     * where unescaped is %x20-21 / %x23-5B / %x5D-7E.
     */
    private const string QUOTED_SF_STRING_PATTERN = '/\A"((?:[\x20\x21\x23-\x5B\x5D-\x7E]|\\\\["\\\\])*)"\z/';

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
        return $this->canonicalKey($key) !== null;
    }

    /**
     * The key as stored and compared, or null when the header value is not a valid key.
     * In sf-string mode the draft's quoted form (`"8e03…"`, draft-ietf-httpapi-idempotency-key-header-09)
     * is unquoted and unescaped, so `"abc"` and `abc` are the same key.
     */
    public function canonicalKey(?string $headerValue): ?string
    {
        // An empty key fails every pattern below.
        if ($headerValue === null) {
            return null;
        }

        $key = match ($this->format) {
            IdempotencyKeyFormat::Uuid => preg_match(self::UUID_PATTERN, $headerValue) === 1 ? $headerValue : null,
            IdempotencyKeyFormat::SfString => $this->sfStringKey($headerValue),
        };

        return $key !== null && $key !== '' && strlen($key) <= $this->maxLength ? $key : null;
    }

    private function sfStringKey(string $headerValue): ?string
    {
        if (preg_match(self::QUOTED_SF_STRING_PATTERN, $headerValue, $matches) === 1) {
            return preg_replace('/\\\\(.)/', '$1', $matches[1]);
        }

        return preg_match(self::SF_STRING_PATTERN, $headerValue) === 1 ? $headerValue : null;
    }
}
