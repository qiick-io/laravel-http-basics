<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Locale;

use Qiick\HttpBasics\Contracts\SupportedLocales;

/** Supported locales from an explicit list (config / testing). */
final class ConfigSupportedLocales implements SupportedLocales
{
    /**
     * @param  list<string>  $locales
     */
    public function __construct(
        private array $locales,
    ) {}

    public function all(): array
    {
        return $this->locales;
    }
}
