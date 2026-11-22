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
 * Product bootstrap:
 * `$binder->onRespond(ExceptionErrorCodeAugmenter::callback($resolver, $doors));`
 */
final class ExceptionErrorCodeAugmenter
{
    public function __construct(
        private ErrorCodeResolver $resolver,
        private MachineDoorClassifier $machineDoors,
        private ErrorCodeAugmentor $augmentor = new ErrorCodeAugmentor,
    ) {}

    public static function callback(
        ErrorCodeResolver $resolver,
        MachineDoorClassifier $machineDoors,
        ?ErrorCodeAugmentor $augmentor = null,
    ): \Closure {
        $instance = new self($resolver, $machineDoors, $augmentor ?? new ErrorCodeAugmentor);

        return $instance->respond(...);
    }

    public function respond(Response $response, \Throwable $exception, Request $request): Response
    {
        if (! $this->machineDoors->isMachineDoor($request)) {
            return $response;
        }

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        return $this->augmentor->augment(
            $response,
            $this->resolver->forException($exception),
        );
    }
}
