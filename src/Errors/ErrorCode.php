<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Errors;

/**
 * @api Single backed enum of fleet `error` wire codes (framework + package).
 *
 * Products may add their own codes through a custom {@see ErrorCodeResolver};
 * every code this package emits is a case here and is listed in
 * `docs/error-codes.md`. A catalogue test fails when either drifts.
 */
enum ErrorCode: string
{
    case ValidationFailed = 'validation_failed';
    case Unauthenticated = 'unauthenticated';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case TooManyRequests = 'too_many_requests';
    case Conflict = 'conflict';
    case UnsupportedMediaType = 'unsupported_media_type';
    case ServiceUnavailable = 'service_unavailable';
    case HttpError = 'http_error';
    case ServerError = 'server_error';

    case IdempotencyKeyRequired = 'idempotency_key_required';
    case IdempotencyKeyInvalid = 'idempotency_key_invalid';
    case IdempotencyPayloadMismatch = 'idempotency_payload_mismatch';
    case IdempotencyInProgress = 'idempotency_in_progress';
    case IdempotencyNotReplayable = 'idempotency_not_replayable';
    case IdempotencyUnavailable = 'idempotency_unavailable';
    case IdempotencyUnsupportedMedia = 'idempotency_unsupported_media';

    /**
     * Short English description for the docs table (never sent as `message`).
     */
    public function documentation(): string
    {
        return match ($this) {
            self::ValidationFailed => 'Request body or fields failed validation.',
            self::Unauthenticated => 'Authentication is required or the credential is invalid.',
            self::Forbidden => 'The caller is authenticated but not allowed to perform this action.',
            self::NotFound => 'The requested resource or route does not exist.',
            self::MethodNotAllowed => 'The HTTP method is not allowed for this route.',
            self::TooManyRequests => 'The caller exceeded a rate limit.',
            self::Conflict => 'The request conflicts with the current resource state.',
            self::UnsupportedMediaType => 'The request Content-Type is not supported.',
            self::ServiceUnavailable => 'The service is temporarily unavailable.',
            self::HttpError => 'Other client HTTP error without a more specific fleet code.',
            self::ServerError => 'Unexpected server failure.',
            self::IdempotencyKeyRequired => 'Idempotency-Key header is required on this route.',
            self::IdempotencyKeyInvalid => 'Idempotency-Key header failed format validation.',
            self::IdempotencyPayloadMismatch => 'Payload fingerprint does not match the original claim.',
            self::IdempotencyInProgress => 'Another request with this key is still in progress.',
            self::IdempotencyNotReplayable => 'The prior response cannot be replayed.',
            self::IdempotencyUnavailable => 'The idempotency store could not be reached.',
            self::IdempotencyUnsupportedMedia => 'Idempotent routes require application/json or application/*+json.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $code): string => $code->value,
            self::cases(),
        );
    }
}
