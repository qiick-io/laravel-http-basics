<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Contracts;

/** @api */
interface SupportedLocales
{
    /** @return list<string> */
    public function all(): array;
}
