<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Events;

use Qiick\HttpBasics\Erasure\ErasureReport;

/** @api Announces a committed erasure run with counts only. */
final readonly class SubjectErasedEvent
{
    public function __construct(public ErasureReport $report) {}
}
