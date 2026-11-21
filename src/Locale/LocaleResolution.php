<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Locale;

/** @api */
final readonly class LocaleResolution
{
    public function __construct(
        public string $locale,
        public LocaleSource $source,
    ) {}
}
