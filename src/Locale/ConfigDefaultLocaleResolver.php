<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Locale;

use Qiick\HttpBasics\Contracts\DefaultLocaleResolver;

/** Default locale from a fixed config/app value. */
final class ConfigDefaultLocaleResolver implements DefaultLocaleResolver
{
    public function __construct(
        private string $locale,
    ) {}

    public function resolve(): string
    {
        return $this->locale;
    }
}
