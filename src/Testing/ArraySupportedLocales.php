<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Testing;

use Qiick\HttpBasics\Contracts\SupportedLocales;

/** @api */
final class ArraySupportedLocales implements SupportedLocales
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
