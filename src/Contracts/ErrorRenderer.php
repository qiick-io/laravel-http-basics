<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Contracts;

use Illuminate\Http\JsonResponse;
use Qiick\HttpBasics\Idempotency\IdempotencyRejection;

/**
 * @api Renders fleet-shaped JSON for idempotency refusals.
 */
interface ErrorRenderer
{
    public function render(IdempotencyRejection $rejection): JsonResponse;
}
