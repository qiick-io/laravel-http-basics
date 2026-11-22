<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Contracts;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Qiick\HttpBasics\Idempotency\IdempotencyRejection;

/**
 * @api Renders fleet-shaped JSON for idempotency refusals.
 *
 * Receives the Request so renderers can translate `message` for the active
 * locale. The stable `error` code is never translated.
 */
interface ErrorRenderer
{
    public function render(IdempotencyRejection $rejection, Request $request): JsonResponse;
}
