<?php

declare(strict_types=1);

/**
 * Golden wire shapes of `http-basics.errors.format=problem_details`, rendered by
 * Laravel's real exception handler with the package's bound
 * ExceptionErrorCodeAugmenter as its respond() callback. Each case compares the
 * status, the listed headers and the exact body, member order included, with
 * `golden/problem-details/<case>.json`.
 */

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Qiick\HttpBasics\Errors\ExceptionErrorCodeAugmenter;
use Qiick\HttpBasics\Errors\MachineDoorJson;
use Qiick\HttpBasics\Errors\ProblemInstanceResolver;
use Qiick\HttpBasics\Idempotency\HandleIdempotencyMiddleware;

/**
 * Wires the handler the way the README tells a product to.
 */
function problemDetailsApp(): void
{
    config()->set('app.debug', false);
    config()->set('http-basics.errors.format', 'problem_details');

    $handler = app(ExceptionHandler::class);
    $handler->shouldRenderJsonWhen(MachineDoorJson::whenCallback(['api/*']));
    $handler->respondUsing(static fn ($response, Throwable $exception, Request $request) => app(ExceptionErrorCodeAugmenter::class)->respond($response, $exception, $request));

    Route::get('api/validation', static fn () => throw ValidationException::withMessages(['email' => ['The email field is required.']]));
    Route::get('api/unauthenticated', static fn () => throw new AuthenticationException);
    Route::get('api/forbidden', static fn () => throw new AuthorizationException);
    Route::get('api/widgets/7', static fn () => throw (new ModelNotFoundException)->setModel('Widget', [7]));
    Route::get('api/items', static fn () => 'ok');
    Route::get('api/throttled', static fn () => throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => '60']));
    Route::get('api/teapot', static fn () => abort(418));
    Route::get('api/crash', static fn () => throw new RuntimeException('database password in a stack trace'));
    // A product exception that renders its own fleet body, with its own code.
    Route::get('api/locked', static fn () => throw new class extends RuntimeException
    {
        public function render(): JsonResponse
        {
            return response()->json(['message' => 'Widget 42 is locked.', 'error' => 'widget_locked', 'widget_id' => 42], 423);
        }
    });
    Route::get('mcp/legacy', static fn () => abort(410, '', ['X-Trace-Id' => 'trace-410']));
    Route::post('api/orders', static fn () => 'created')->middleware(HandleIdempotencyMiddleware::class);
    Route::get('web/teapot', static fn () => abort(418));
}

function assertProblemGolden(TestResponse $response, string $goldenCase): void
{
    /** @var array{status: int, headers: array<string, string>, body: array<string, mixed>} $golden */
    $golden = json_decode((string) file_get_contents(__DIR__."/golden/problem-details/{$goldenCase}.json"), true, flags: JSON_THROW_ON_ERROR);

    expect($response->getStatusCode())->toBe($golden['status']);

    foreach ($golden['headers'] as $headerName => $headerValue) {
        expect($response->headers->get($headerName))->toBe($headerValue);
    }

    expect(json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR))->toBe($golden['body']);
}

it('renders every exception as problem details on a machine door', function (string $method, string $uri, string $goldenCase): void {
    problemDetailsApp();

    assertProblemGolden($this->json($method, $uri), $goldenCase);
})->with([
    'validation' => ['GET', 'api/validation', 'validation-failed'],
    'authentication' => ['GET', 'api/unauthenticated', 'unauthenticated'],
    'authorization' => ['GET', 'api/forbidden', 'forbidden'],
    'unknown route' => ['GET', 'api/missing', 'not-found'],
    'missing model' => ['GET', 'api/widgets/7', 'model-not-found'],
    'wrong method' => ['POST', 'api/items', 'method-not-allowed'],
    'throttled' => ['GET', 'api/throttled', 'too-many-requests'],
    'other client error' => ['GET', 'api/teapot', 'http-error'],
    'unexpected exception' => ['GET', 'api/crash', 'server-error'],
    'product response with its own code' => ['GET', 'api/locked', 'product-error-code'],
    'idempotency refusal' => ['POST', 'api/orders', 'idempotency-key-required'],
]);

it('turns an HTML error page on a machine door into problem details', function (): void {
    problemDetailsApp();

    assertProblemGolden($this->get('mcp/legacy', ['Accept' => 'text/html']), 'html-error-page');
});

it('uses the typed problem base URI and the bound instance resolver', function (): void {
    config()->set('http-basics.errors.problem_type_base_uri', 'https://errors.example.test/problems/');
    app()->instance(ProblemInstanceResolver::class, new class implements ProblemInstanceResolver
    {
        public function instance(Request $request, string $errorCode): ?string
        {
            return 'urn:request:'.$request->path();
        }
    });
    problemDetailsApp();

    assertProblemGolden($this->getJson('api/missing'), 'typed-not-found');
});

it('leaves non-machine-door responses in their framework shape', function (): void {
    problemDetailsApp();

    $response = $this->getJson('web/teapot');

    expect($response->getStatusCode())->toBe(418)
        ->and($response->headers->get('Content-Type'))->toStartWith('text/html')
        ->and($this->getJson('api/items')->getContent())->toBe('ok');
});

it('keeps the fleet shape when the format is fleet', function (): void {
    problemDetailsApp();
    config()->set('http-basics.errors.format', 'fleet');
    app()->forgetInstance(ExceptionErrorCodeAugmenter::class);

    $response = $this->getJson('api/teapot');

    expect($response->headers->get('Content-Type'))->toBe('application/json')
        ->and($response->json())->toBe(['message' => '', 'error' => 'http_error'])
        ->and($this->get('mcp/legacy', ['Accept' => 'text/html'])->headers->get('Content-Type'))->toStartWith('text/html');
});
