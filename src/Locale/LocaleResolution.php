<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Locale;

/**
 * @api Outcome of locale negotiation for one request.
 *
 * `$didReadAcceptLanguage` is true when a machine door saw a string
 * Accept-Language header (even if it did not win). Middleware uses it for Vary.
 */
final readonly class LocaleResolution
{
    public function __construct(
        public string $locale,
        public LocaleSource $source,
        public bool $didReadAcceptLanguage = false,
    ) {}
}
