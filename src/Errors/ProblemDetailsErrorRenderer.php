<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Errors;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Qiick\HttpBasics\Contracts\ErrorRenderer;
use Qiick\HttpBasics\Idempotency\FleetErrorRenderer;
use Qiick\HttpBasics\Idempotency\IdempotencyRejection;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api Optional RFC 9457 `application/problem+json` renderer.
 *
 * Used for idempotency refusals (`ErrorRenderer`) and, through
 * {@see ExceptionErrorCodeAugmenter}, for every machine-door error response the
 * exception handler renders when `http-basics.errors.format=problem_details`.
 * The fleet `message` becomes `detail`; every other fleet member (`error`,
 * `errors`, `documentation_url`, a product's own keys) stays as an extension
 * member, so a client that reads the fleet shape keeps working.
 */
final class ProblemDetailsErrorRenderer implements ErrorRenderer
{
    public function __construct(
        private FleetErrorRenderer $fleet,
        private ?string $problemTypeBaseUri = null,
        private ?ProblemInstanceResolver $instances = null,
        private ?Translator $translator = null,
    ) {}

    public function render(IdempotencyRejection $rejection, Request $request): JsonResponse
    {
        return $this->fromFleetJson($this->fleet->render($rejection, $request), $rejection->errorCode(), $request);
    }

    /**
     * Rewrites a fleet-shaped error response as problem details, keeping its status
     * and headers (`Retry-After`, `Allow`, …).
     *
     * @param  string  $errorCode  used when the fleet body carries no `error` of its own
     */
    public function fromFleetJson(JsonResponse $fleetResponse, string $errorCode, Request $request): JsonResponse
    {
        $fleetData = $fleetResponse->getData(true);
        /** @var array<string, mixed> $fleetPayload */
        $fleetPayload = is_array($fleetData) ? $fleetData : [];
        $status = $fleetResponse->getStatusCode();
        $error = is_string($fleetPayload['error'] ?? null) ? $fleetPayload['error'] : $errorCode;
        $message = $fleetPayload['message'] ?? null;

        $type = 'about:blank';
        $title = Response::$statusTexts[$status] ?? 'Error';

        // A typed problem has its own title; `about:blank` keeps the HTTP reason phrase (RFC 9457 §4.2.1).
        if ($this->problemTypeBaseUri !== null) {
            // The code is a path segment: a query or fragment on the configured base is dropped.
            $baseUri = explode('#', explode('?', $this->problemTypeBaseUri)[0])[0];
            $type = rtrim($baseUri, '/').'/'.$error;
            $title = $this->translatedTitle($error, $request) ?? $title;
        }

        $problem = [
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'detail' => is_string($message) && $message !== '' ? $message : $title,
        ];

        $instance = $this->instances?->instance($request, $error);

        if ($instance !== null && $instance !== '') {
            $problem['instance'] = $instance;
        }

        unset($fleetPayload['message']);

        // Problem members win over a fleet key of the same name.
        $response = new JsonResponse($problem + ['error' => $error] + $fleetPayload, $status);
        $response->headers = clone $fleetResponse->headers;
        $response->headers->set('Content-Type', 'application/problem+json');

        return $response;
    }

    private function translatedTitle(string $errorCode, Request $request): ?string
    {
        if ($this->translator === null) {
            return null;
        }

        $key = 'http-basics::errors.titles.'.$errorCode;
        $translated = $this->translator->get($key, [], $request->getLocale());

        // A missing catalogue key comes back as the key itself.
        return is_string($translated) && $translated !== $key ? $translated : null;
    }
}
