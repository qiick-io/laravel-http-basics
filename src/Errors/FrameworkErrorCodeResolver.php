<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Errors;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * @api Default register mapping from common framework exceptions to fleet codes.
 *
 * Products may wrap or replace this resolver; it never invents product-specific codes.
 * Optional Illuminate exception classes are matched by FQCN so the package stays
 * Illuminate-http-only at runtime.
 */
final class FrameworkErrorCodeResolver implements ErrorCodeResolver
{
    public function forException(\Throwable $exception): string
    {
        if (is_a($exception, 'Illuminate\\Validation\\ValidationException')) {
            return 'validation_failed';
        }

        if (is_a($exception, 'Illuminate\\Auth\\AuthenticationException')) {
            return 'unauthenticated';
        }

        if (is_a($exception, 'Illuminate\\Auth\\Access\\AuthorizationException')) {
            return 'forbidden';
        }

        if (is_a($exception, 'Illuminate\\Database\\Eloquent\\ModelNotFoundException')) {
            return 'not_found';
        }

        if ($exception instanceof HttpExceptionInterface) {
            return match ($exception->getStatusCode()) {
                401 => 'unauthenticated',
                403 => 'forbidden',
                404 => 'not_found',
                405 => 'method_not_allowed',
                409 => 'conflict',
                415 => 'unsupported_media_type',
                422 => 'validation_failed',
                429 => 'too_many_requests',
                503 => 'service_unavailable',
                default => $exception->getStatusCode() >= 500 ? 'server_error' : 'http_error',
            };
        }

        return 'server_error';
    }
}
