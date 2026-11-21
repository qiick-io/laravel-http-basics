<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Testing;

use Qiick\HttpBasics\Contracts\IdGenerator;

/** @api Deterministic id generator for tests. */
final class FixedIdGenerator implements IdGenerator
{
    public function __construct(
        private string $value = 'generated-request-id',
    ) {}

    public function generate(): string
    {
        return $this->value;
    }
}
