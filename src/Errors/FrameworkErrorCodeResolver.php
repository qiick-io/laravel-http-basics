<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Errors;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

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

        if (is_a($exception, 'Illuminate\\Routing\\Exceptions\\ThrottleRequestsException')
            || is_a($exception, 'Illuminate\\Http\\Exceptions\\ThrottleRequestsException')) {
            return ErrorCode::TooManyRequests;
        }

        if ($exception instanceof MethodNotAllowedHttpException) {
            return ErrorCode::MethodNotAllowed;
        }

        if ($exception instanceof HttpExceptionInterface) {
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
