<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

use Illuminate\Http\JsonResponse;
use Qiick\HttpBasics\Contracts\ErrorRenderer;

/**
 * @api Default fleet JSON shape for idempotency refusals (`message` + `error`).
 */
final class FleetErrorRenderer implements ErrorRenderer
{
    public function __construct(
        private ?string $documentationUrl = null,
    ) {}

    public function render(IdempotencyRejection $rejection): JsonResponse
    {
        $payload = [
            'message' => $this->messageFor($rejection->idempotencyErrorCode()),
            'error' => $rejection->errorCode(),
        ];

        if ($this->documentationUrl !== null && $this->documentationUrl !== '') {
            $payload['documentation_url'] = $this->documentationUrl;
        }

        return new JsonResponse(
            $payload,
            $rejection->getStatusCode(),
            $rejection->getHeaders(),
        );
    }

    private function messageFor(IdempotencyErrorCode $code): string
    {
        return match ($code) {
            IdempotencyErrorCode::KeyRequired => 'Idempotency-Key header is required.',
            IdempotencyErrorCode::KeyInvalid => 'Idempotency-Key header is invalid.',
            IdempotencyErrorCode::PayloadMismatch => 'Request payload does not match the original idempotent request.',
            IdempotencyErrorCode::InProgress => 'An idempotent request with this key is already in progress.',
            IdempotencyErrorCode::Unavailable => 'Idempotency store is temporarily unavailable.',
            IdempotencyErrorCode::UnsupportedMedia => 'Idempotent routes require Content-Type: application/json.',
        };
    }
}
