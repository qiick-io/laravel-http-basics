<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\RequestId;

use Illuminate\Support\Str;
use Qiick\HttpBasics\Contracts\IdGenerator;

/** @internal Default CSPRNG-backed UUID v7 generator. */
final class UuidIdGenerator implements IdGenerator
{
    public function generate(): string
    {
        return (string) Str::uuid7();
    }
}
