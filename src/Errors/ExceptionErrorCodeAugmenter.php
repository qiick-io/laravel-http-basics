<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Errors;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Qiick\HttpBasics\Locale\MachineDoorClassifier;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api Builds the respond() callback that augments machine-door JSON ≥400 with `error`.
 *
 * With a {@see ProblemDetailsErrorRenderer} (bound from config when
 * `http-basics.errors.format=problem_details`), every machine-door error response
 * becomes RFC 9457 problem details, a non-JSON one (an HTML error page) included.
 * Product bootstrap:
 * `$exceptions->respond(fn ($response, $exception, $request) => app(ExceptionErrorCodeAugmenter::class)->respond($response, $exception, $request));`
 */
final class ExceptionErrorCodeAugmenter
{
    public function __construct(
        private ErrorCodeResolver $resolver,
        private MachineDoorClassifier $machineDoors,
        private ErrorCodeAugmentor $augmentor = new ErrorCodeAugmentor,
        private ?ProblemDetailsErrorRenderer $problemDetails = null,
    ) {}

    public static function callback(
        ErrorCodeResolver $resolver,
        MachineDoorClassifier $machineDoors,
        ?ProblemDetailsErrorRenderer $problemDetails = null,
    ): \Closure {
        return (new self($resolver, $machineDoors, problemDetails: $problemDetails))->respond(...);
    }

    public function respond(Response $response, \Throwable $exception, Request $request): Response
    {
        if (! $this->machineDoors->isMachineDoor($request) || $response->getStatusCode() < 400) {
            return $response;
        }

        $errorCode = $this->resolver->forException($exception);

        if ($response instanceof JsonResponse) {
            $fleetResponse = $this->augmentor->augment($response, $errorCode);

            return $this->problemDetails?->fromFleetJson($fleetResponse, $errorCode, $request) ?? $fleetResponse;
        }

        if ($this->problemDetails === null) {
            return $response;
        }

        // An HTML or plain-text error page keeps only its status and headers.
        $bodylessResponse = new JsonResponse([], $response->getStatusCode(), $this->headersWithoutBodyFraming($response));

        return $this->problemDetails->fromFleetJson($bodylessResponse, $errorCode, $request);
    }

    /**
     * @return array<string, list<string|null>>
     */
    private function headersWithoutBodyFraming(Response $response): array
    {
        $headers = $response->headers->all();
        unset($headers['content-type'], $headers['content-length']);

        return $headers;
    }
}
