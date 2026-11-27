<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\RequestId;

/**
 * @api Validated request correlation id.
 */
final readonly class RequestId
{
    public const string HEADER = 'X-Request-Id';

    /**
     * Letters, digits and `-_.:`, 8–128 chars, and at least one alphanumeric
     * so a punctuation-only token cannot forge a log line.
     */
    public const string PATTERN = '/\A(?=[A-Za-z0-9\-_.:]{8,128}$)(?=.*[A-Za-z0-9])[A-Za-z0-9\-_.:]+\z/D';

    public function __construct(
        public string $value,
        public RequestIdSource $source,
    ) {}

    /**
     * @param  list<string|null>  $headerValues  Exactly one matching value; duplicates → null.
     */
    public static function tryFromHeaderValues(array $headerValues, string $pattern = self::PATTERN): ?self
    {
        $candidates = [];

        foreach ($headerValues as $value) {
            if (! is_string($value)) {
                continue;
            }

            // Custom patterns may contain alternatives. Accept only a whole-header match.
            if (preg_match($pattern, $value, $matches) !== 1 || $matches[0] !== $value) {
                return null;
            }

            $candidates[] = $value;
        }

        if (count($candidates) !== 1) {
            return null;
        }

        return new self($candidates[0], RequestIdSource::Client);
    }

    /**
     * @return array{X-Request-Id: string}
     */
    public function outboundHeaders(): array
    {
        return [self::HEADER => $this->value];
    }
}
