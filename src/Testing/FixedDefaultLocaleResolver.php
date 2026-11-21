<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Testing;

use Qiick\HttpBasics\Contracts\DefaultLocaleResolver;

/** @api */
final class FixedDefaultLocaleResolver implements DefaultLocaleResolver
{
    public function __construct(
        private string $locale = 'en',
    ) {}

    public function resolve(): string
    {
        return $this->locale;
    }
}
