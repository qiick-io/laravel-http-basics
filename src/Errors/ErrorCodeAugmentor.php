<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Errors;

use Illuminate\Http\JsonResponse;

/**
 * @api Adds a top-level `error` member to JSON responses with status ≥400.
 *
 * Never overwrites an existing `error` key. Non-JSON and 2xx/3xx bodies are untouched.
 */
final class ErrorCodeAugmentor
{
    public function augment(JsonResponse $response, string $errorCode): JsonResponse
    {
        if ($response->getStatusCode() < 400) {
            return $response;
        }

        /** @var mixed $payload */
        $payload = $response->getData(true);

        if (! is_array($payload)) {
            return $response;
        }

        if (array_key_exists('error', $payload)) {
            return $response;
        }

        $payload['error'] = $errorCode;
        $response->setData($payload);

        return $response;
    }
}
