<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Errors;

use Qiick\HttpBasics\Idempotency\IdempotencyRejection;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * @api Default register mapping from common framework exceptions to {@see ErrorCode}.
 *
 * Products may wrap or replace this resolver; it never invents product-specific codes.
 * Optional Illuminate exception classes are matched by FQCN so the package stays
 * Illuminate-http-only at runtime.
 */
final class FrameworkErrorCodeResolver implements ErrorCodeResolver
{
    public function forException(\Throwable $exception): string
    {
        return $this->resolve($exception)->value;
    }

    public function resolve(\Throwable $exception): ErrorCode
    {
        // A package exception already carries its precise code (idempotency_in_progress, not conflict).
        if ($exception instanceof IdempotencyRejection) {
            return $exception->fleetErrorCode();
        }

        if (is_a($exception, 'Illuminate\\Validation\\ValidationException')) {
            return ErrorCode::ValidationFailed;
        }

        if (is_a($exception, 'Illuminate\\Auth\\AuthenticationException')) {
            return ErrorCode::Unauthenticated;
        }

        if (is_a($exception, 'Illuminate\\Auth\\Access\\AuthorizationException')) {
            return ErrorCode::Forbidden;
        }

        if (is_a($exception, 'Illuminate\\Database\\Eloquent\\ModelNotFoundException')) {
            return ErrorCode::NotFound;
        }

        if ($exception instanceof HttpExceptionInterface) {
            // Throttling (429) and MethodNotAllowedHttpException (405) are HTTP exceptions, so they land here.
            return match ($exception->getStatusCode()) {
                401 => ErrorCode::Unauthenticated,
                403 => ErrorCode::Forbidden,
                404 => ErrorCode::NotFound,
                405 => ErrorCode::MethodNotAllowed,
                409 => ErrorCode::Conflict,
                415 => ErrorCode::UnsupportedMediaType,
                422 => ErrorCode::ValidationFailed,
                429 => ErrorCode::TooManyRequests,
                503 => ErrorCode::ServiceUnavailable,
                default => $exception->getStatusCode() >= 500
                    ? ErrorCode::ServerError
                    : ErrorCode::HttpError,
            };
        }

        return ErrorCode::ServerError;
    }
}
