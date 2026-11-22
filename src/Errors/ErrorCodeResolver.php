<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Errors;

/**
 * @api Maps a throwable to a stable fleet `error` code for JSON ≥400 responses.
 *
 * Products keep their own catalogues by binding a custom implementation.
 */
interface ErrorCodeResolver
{
    public function forException(\Throwable $exception): string;
}
