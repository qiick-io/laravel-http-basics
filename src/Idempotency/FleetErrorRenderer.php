<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Qiick\HttpBasics\Contracts\ErrorRenderer;

/**
 * @api Default fleet JSON shape for idempotency refusals (`message` + `error`).
 *
 * `message` is translated through the package lang files; `error` stays a
 * stable snake_case code.
 */
final class FleetErrorRenderer implements ErrorRenderer
{
    public function __construct(
        private ?string $documentationUrl = null,
        private ?Translator $translator = null,
    ) {}

    public function render(IdempotencyRejection $rejection, Request $request): JsonResponse
    {
        $payload = [
            'message' => $this->messageFor($rejection->idempotencyErrorCode(), $request),
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

    private function messageFor(IdempotencyErrorCode $code, Request $request): string
    {
        $key = 'http-basics::idempotency.'.$code->value;
        $locale = $request->getLocale();

        if ($this->translator !== null) {
            $translated = $this->translator->get($key, [], $locale);

            if (is_string($translated) && $translated !== $key) {
                return $translated;
            }
        }

        return $this->fallbackMessage($code);
    }

    private function fallbackMessage(IdempotencyErrorCode $code): string
    {
        return match ($code) {
            IdempotencyErrorCode::KeyRequired => 'Idempotency-Key header is required.',
            IdempotencyErrorCode::KeyInvalid => 'Idempotency-Key header is invalid.',
            IdempotencyErrorCode::PayloadMismatch => 'Request payload does not match the original idempotent request.',
            IdempotencyErrorCode::InProgress => 'An idempotent request with this key is already in progress.',
            IdempotencyErrorCode::NotReplayable => 'The idempotent request completed but cannot be replayed.',
            IdempotencyErrorCode::Unavailable => 'Idempotency store is temporarily unavailable.',
            IdempotencyErrorCode::UnsupportedMedia => 'Idempotent routes require Content-Type: application/json.',
            IdempotencyErrorCode::InvalidJson => 'The given data was invalid.',
        };
    }
}
