<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Contracts;

/** @api */
interface DefaultLocaleResolver
{
    public function resolve(): string;
}
