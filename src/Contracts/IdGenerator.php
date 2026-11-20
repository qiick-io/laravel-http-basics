<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Contracts;

/** @api */
interface IdGenerator
{
    public function generate(): string;
}
