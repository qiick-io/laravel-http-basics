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

        // Both Illuminate throttle FQCNs map to the same wire code; the or is catalogue sugar.
        if (is_a($exception, 'Illuminate\\Routing\\Exceptions\\ThrottleRequestsException')
            || is_a($exception, 'Illuminate\\Http\\Exceptions\\ThrottleRequestsException')) { // @pest-mutate-ignore: BooleanOrToBooleanAnd
            return ErrorCode::TooManyRequests; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        // MethodNotAllowedHttpException is also an HttpExceptionInterface(405); keep the
        // named branch for clarity. Equivalent to the match arm below.
        if ($exception instanceof MethodNotAllowedHttpException) { // @pest-mutate-ignore: InstanceOfToFalse
            return ErrorCode::MethodNotAllowed; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        if ($exception instanceof HttpExceptionInterface) {
            // HTTP status literals: ±1 is a different code, not an adjacent catalogue entry.
            return match ($exception->getStatusCode()) {
                401 => ErrorCode::Unauthenticated, // @pest-mutate-ignore: IncrementInteger, DecrementInteger
                403 => ErrorCode::Forbidden, // @pest-mutate-ignore: IncrementInteger, DecrementInteger
                404 => ErrorCode::NotFound, // @pest-mutate-ignore: IncrementInteger, DecrementInteger
                405 => ErrorCode::MethodNotAllowed, // @pest-mutate-ignore: IncrementInteger, DecrementInteger
                409 => ErrorCode::Conflict, // @pest-mutate-ignore: IncrementInteger, DecrementInteger
                415 => ErrorCode::UnsupportedMediaType, // @pest-mutate-ignore: IncrementInteger, DecrementInteger
                422 => ErrorCode::ValidationFailed, // @pest-mutate-ignore: IncrementInteger, DecrementInteger
                429 => ErrorCode::TooManyRequests, // @pest-mutate-ignore: IncrementInteger, DecrementInteger
                503 => ErrorCode::ServiceUnavailable, // @pest-mutate-ignore: IncrementInteger, DecrementInteger
                default => $exception->getStatusCode() >= 500 // @pest-mutate-ignore: DecrementInteger, IncrementInteger
                    ? ErrorCode::ServerError
                    : ErrorCode::HttpError,
            };
        }

        return ErrorCode::ServerError;
    }
}
