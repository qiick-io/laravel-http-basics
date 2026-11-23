<?php

declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Qiick\HttpBasics\Contracts\ErrorRenderer;
use Qiick\HttpBasics\Errors\ErrorCode;
use Qiick\HttpBasics\Errors\ExceptionErrorCodeAugmenter;
use Qiick\HttpBasics\Errors\FrameworkErrorCodeResolver;
use Qiick\HttpBasics\Errors\ProblemDetailsErrorRenderer;
use Qiick\HttpBasics\Errors\ProblemInstanceResolver;
use Qiick\HttpBasics\Idempotency\FleetErrorRenderer;
use Qiick\HttpBasics\Idempotency\IdempotencyRejection;
use Qiick\HttpBasics\Locale\MachineDoorClassifier;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

it('renders about:blank problem details with fleet error extension by default', function (): void {
    $renderer = new ProblemDetailsErrorRenderer(new FleetErrorRenderer);
    $rejection = new IdempotencyRejection(ErrorCode::IdempotencyKeyRequired, 400);
    $response = $renderer->render($rejection, Request::create('/api/widgets', 'POST'));

    expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->getData(true))->toMatchArray([
            'type' => 'about:blank',
            'title' => 'Bad Request',
            'status' => 400,
            'error' => 'idempotency_key_required',
        ])
        ->and($response->getData(true)['detail'])->toBeString();
});

it('uses the problem type base URI and optional instance resolver', function (): void {
    $instances = new class implements ProblemInstanceResolver
    {
        public function instance(Request $request, string $errorCode): ?string
        {
            return '/problems/'.$errorCode;
        }
    };

    $translator = new class implements Translator
    {
        public function get($key, array $replace = [], $locale = null): mixed
        {
            return $key === 'http-basics::errors.titles.idempotency_in_progress'
                ? 'Still working'
                : $key;
        }

        public function choice($key, $number, array $replace = [], $locale = null): mixed
        {
            return $key;
        }

        public function getLocale(): string
        {
            return 'en';
        }

        public function setLocale($locale): void {}
    };

    $renderer = new ProblemDetailsErrorRenderer(
        new FleetErrorRenderer,
        'https://example.test/problems/?x=1#frag',
        $instances,
        $translator,
    );
    $rejection = new IdempotencyRejection(ErrorCode::IdempotencyInProgress, 409, ['Retry-After' => '30']);
    $response = $renderer->render($rejection, Request::create('/api/widgets', 'POST'));

    expect($response->getData(true))->toMatchArray([
        'type' => 'https://example.test/problems/idempotency_in_progress',
        'title' => 'Still working',
        'status' => 409,
        'error' => 'idempotency_in_progress',
        'instance' => '/problems/idempotency_in_progress',
    ])
        ->and($response->headers->get('Retry-After'))->toBe('30');

    $trailingSlash = new ProblemDetailsErrorRenderer(
        new FleetErrorRenderer,
        'https://example.test/problems/',
    );
    $slashResponse = $trailingSlash->render(
        new IdempotencyRejection(ErrorCode::IdempotencyKeyRequired, 400),
        Request::create('/api/widgets', 'POST'),
    );

    expect($slashResponse->getData(true)['type'])->toBe('https://example.test/problems/idempotency_key_required');
});

it('binds the problem details renderer when errors.format is problem_details', function (): void {
    config()->set('http-basics.errors.format', 'problem_details');
    $this->app->forgetInstance(ErrorRenderer::class);

    $renderer = app(ErrorRenderer::class);
    expect($renderer)->toBeInstanceOf(ProblemDetailsErrorRenderer::class);

    $response = $renderer->render(
        new IdempotencyRejection(ErrorCode::IdempotencyPayloadMismatch, 422),
        Request::create('/api/widgets', 'POST'),
    );

    expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->getData(true)['error'])->toBe('idempotency_payload_mismatch');
});

it('rewrites augmentor JSON ≥400 as problem details for every framework mapping', function (Throwable $exception, int $status, string $error): void {
    $problems = new ProblemDetailsErrorRenderer(new FleetErrorRenderer);
    $callback = ExceptionErrorCodeAugmenter::callback(
        new FrameworkErrorCodeResolver,
        new MachineDoorClassifier(['api/*']),
        problemDetails: $problems,
    );

    $request = Request::create('/api/widgets', 'GET');
    $fleet = new JsonResponse(['message' => 'fleet-message', 'errors' => ['field' => ['bad']]], $status);
    $response = $callback($fleet, $exception, $request);

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->getData(true))->toMatchArray([
            'type' => 'about:blank',
            'status' => $status,
            'detail' => 'fleet-message',
            'error' => $error,
            'errors' => ['field' => ['bad']],
        ]);
})->with([
    'not_found' => [new NotFoundHttpException('missing'), 404, 'not_found'],
    'method_not_allowed' => [new MethodNotAllowedHttpException(['GET']), 405, 'method_not_allowed'],
    'too_many_requests' => [new TooManyRequestsHttpException, 429, 'too_many_requests'],
]);

it('renders every documented ErrorCode under both fleet and problem_details shapes', function (): void {
    $fleet = new FleetErrorRenderer;
    $problems = new ProblemDetailsErrorRenderer($fleet);
    $request = Request::create('/api/widgets', 'POST');

    foreach (ErrorCode::cases() as $code) {
        if (! str_starts_with($code->value, 'idempotency_') && $code !== ErrorCode::ValidationFailed) {
            $fleetBody = new JsonResponse(['message' => $code->documentation()], 400);
            $asProblem = $problems->fromFleetJson($fleetBody, $code->value, $request);

            expect($asProblem->getData(true)['error'])->toBe($code->value)
                ->and($asProblem->getData(true)['detail'])->toBe($code->documentation())
                ->and($asProblem->headers->get('Content-Type'))->toStartWith('application/problem+json');

            continue;
        }

        $status = match ($code) {
            ErrorCode::IdempotencyInProgress => 409,
            ErrorCode::IdempotencyPayloadMismatch, ErrorCode::ValidationFailed => 422,
            ErrorCode::IdempotencyUnsupportedMedia => 415,
            ErrorCode::IdempotencyUnavailable => 503,
            default => 400,
        };

        $rejection = new IdempotencyRejection($code, $status);
        $fleetResponse = $fleet->render($rejection, $request);
        $problemResponse = $problems->render($rejection, $request);

        expect($fleetResponse->getData(true)['error'])->toBe($code->value)
            ->and($problemResponse->getData(true)['error'])->toBe($code->value)
            ->and($problemResponse->getData(true)['detail'])->toBe($fleetResponse->getData(true)['message'])
            ->and($problemResponse->headers->get('Content-Type'))->toStartWith('application/problem+json');
    }
});

it('rewrites from status 400 and leaves anything below it alone', function (): void {
    $callback = ExceptionErrorCodeAugmenter::callback(
        new FrameworkErrorCodeResolver,
        new MachineDoorClassifier(['api/*']),
        new ProblemDetailsErrorRenderer(new FleetErrorRenderer),
    );
    $request = Request::create('/api/widgets');

    $belowErrors = $callback(new JsonResponse(['message' => 'odd'], 399), new RuntimeException, $request);
    $firstError = $callback(new JsonResponse(['message' => 'bad'], 400), new RuntimeException, $request);
    $plainBelowErrors = $callback(new Response('odd', 399), new RuntimeException, $request);

    expect($belowErrors->headers->get('Content-Type'))->toBe('application/json')
        ->and($belowErrors->getContent())->toBe('{"message":"odd"}')
        ->and($plainBelowErrors->getContent())->toBe('odd')
        ->and($firstError->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($firstError->getData(true)['status'])->toBe(400);
});

it('omits an empty instance', function (?string $instance): void {
    $renderer = new ProblemDetailsErrorRenderer(new FleetErrorRenderer, instances: new readonly class($instance) implements ProblemInstanceResolver
    {
        public function __construct(private ?string $instance) {}

        public function instance(Request $request, string $errorCode): ?string
        {
            return $this->instance;
        }
    });

    $problem = $renderer->fromFleetJson(new JsonResponse(['message' => 'bad'], 400), 'http_error', Request::create('/api/widgets'))->getData(true);

    expect($problem)->not->toHaveKey('instance');
})->with(['null' => [null], 'empty string' => ['']]);
