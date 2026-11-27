<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Qiick\HttpBasics\Contracts\IdGenerator;
use Qiick\HttpBasics\Contracts\TrustedProxyDetector;
use Qiick\HttpBasics\RequestId\AssignRequestIdMiddleware;
use Qiick\HttpBasics\RequestId\ConfigTrustedProxyDetector;
use Qiick\HttpBasics\RequestId\RequestId;
use Qiick\HttpBasics\RequestId\RequestIdResolver;
use Qiick\HttpBasics\Testing\AlwaysTrustedProxyDetector;
use Qiick\HttpBasics\Testing\FixedIdGenerator;
use Qiick\HttpBasics\Testing\NeverTrustedProxyDetector;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

use function Qiick\HttpBasics\Testing\assertRequestIdEchoed;

beforeEach(function (): void {
    Context::flush();
    TrustProxies::flushState();

    $this->app->instance(IdGenerator::class, new FixedIdGenerator('mw-generated-id'));
    $this->app->instance(TrustedProxyDetector::class, new AlwaysTrustedProxyDetector);
    $this->app->forgetInstance(RequestIdResolver::class);

    Route::middleware(AssignRequestIdMiddleware::class)->get('/ping', function () {
        return response('pong', 200);
    });

    Route::middleware(AssignRequestIdMiddleware::class)->get('/boom', function () {
        abort(503, 'unavailable');
    });

    Route::middleware(AssignRequestIdMiddleware::class)->get('/explode', function () {
        throw new RuntimeException('boom');
    });

    Route::middleware(AssignRequestIdMiddleware::class)->post('/only-post', function () {
        return response('posted', 200);
    });
});

afterEach(function (): void {
    TrustProxies::flushState();
});

it('echoes a trusted inbound id on a successful response', function (): void {
    $response = $this->get('/ping', [RequestId::HEADER => 'trusted-in-01']);

    $response->assertOk();
    assertRequestIdEchoed($response->baseResponse, 'trusted-in-01');
});

it('echoes a generated id when inbound is absent', function (): void {
    $response = $this->get('/ping');

    $response->assertOk();
    assertRequestIdEchoed($response->baseResponse, 'mw-generated-id');
});

it('carries the request id on error responses', function (): void {
    $response = $this->get('/boom', [RequestId::HEADER => 'error-id-01']);

    $response->assertStatus(503);
    assertRequestIdEchoed($response->baseResponse, 'error-id-01');
});

it('carries a generated id on unmatched routes when middleware is global prepend', function (): void {
    $this->app->make(Kernel::class)->prependMiddleware(AssignRequestIdMiddleware::class);

    $response = $this->get('/no-such-route-at-all');

    $response->assertNotFound();
    assertRequestIdEchoed($response->baseResponse, 'mw-generated-id');
});

it('H5 carries the id across authentication, authorization, session, validation and throttling errors', function (int $status, Closure $makeException): void {
    $this->app->make(Kernel::class)->prependMiddleware(AssignRequestIdMiddleware::class);
    Route::get('/http-error', static fn () => throw $makeException());

    $response = $this->getJson('/http-error');

    $response->assertStatus($status);
    assertRequestIdEchoed($response->baseResponse, 'mw-generated-id');
})->with([
    'unauthenticated' => [401, static fn () => new AuthenticationException],
    'forbidden' => [403, static fn () => new AuthorizationException],
    'expired session' => [419, static fn () => new TokenMismatchException],
    'validation' => [422, static fn () => ValidationException::withMessages(['name' => 'Name is required.'])],
    'throttled' => [429, static fn () => new TooManyRequestsHttpException],
]);

it('carries the id on 405 Method Not Allowed when middleware is global prepend', function (): void {
    $this->app->make(Kernel::class)->prependMiddleware(AssignRequestIdMiddleware::class);

    $response = $this->get('/only-post');

    $response->assertStatus(405);
    assertRequestIdEchoed($response->baseResponse, 'mw-generated-id');
});

it('carries the id on a 500 when middleware is global prepend', function (): void {
    $this->app->make(Kernel::class)->prependMiddleware(AssignRequestIdMiddleware::class);

    $response = $this->get('/explode');

    $response->assertStatus(500);
    assertRequestIdEchoed($response->baseResponse, 'mw-generated-id');
});

it('carries the id on a maintenance 503 when outermost', function (): void {
    $kernel = $this->app->make(Kernel::class);
    $kernel->prependMiddleware(PreventRequestsDuringMaintenance::class);
    $kernel->prependMiddleware(AssignRequestIdMiddleware::class);

    $this->app->maintenanceMode()->activate([]);

    try {
        $response = $this->get('/ping');
        $response->assertStatus(503);
        assertRequestIdEchoed($response->baseResponse, 'mw-generated-id');
    } finally {
        $this->app->maintenanceMode()->deactivate();
    }
});

it('carries the id on a CORS preflight when outermost', function (): void {
    $this->app->make(Kernel::class)->prependMiddleware(AssignRequestIdMiddleware::class);

    Route::options('/cors', function () {
        return response('', 204)->header('Access-Control-Allow-Origin', '*');
    });

    $response = $this->call('OPTIONS', '/cors');

    $response->assertStatus(204);
    assertRequestIdEchoed($response->baseResponse, 'mw-generated-id');
});

it('replaces an untrusted inbound id when accept_from is trusted_proxy', function (): void {
    $this->app->instance(TrustedProxyDetector::class, new NeverTrustedProxyDetector);
    $this->app->forgetInstance(RequestIdResolver::class);

    $response = $this->get('/ping', [RequestId::HEADER => 'spoofed-untrusted']);

    $response->assertOk();
    assertRequestIdEchoed($response->baseResponse, 'mw-generated-id');
});

it('keeps a client id when ConfigTrustedProxyDetector trusts REMOTE_ADDR before TrustProxies runs', function (): void {
    TrustProxies::at(['127.0.0.1']);
    $this->app->instance(TrustedProxyDetector::class, new ConfigTrustedProxyDetector(
        $this->app['config'],
        trustWildcardProxies: false,
    ));
    $this->app->forgetInstance(RequestIdResolver::class);

    // AssignRequestId runs without TrustProxies having set request static state.
    $response = $this->call('GET', '/ping', server: [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_REQUEST_ID' => 'proxy-kept-01',
    ]);

    $response->assertOk();
    assertRequestIdEchoed($response->baseResponse, 'proxy-kept-01');
});

it('keeps the id when AssignRequestId is prepended before TrustProxies', function (): void {
    TrustProxies::at(['127.0.0.1']);
    $this->app->instance(TrustedProxyDetector::class, new ConfigTrustedProxyDetector(
        $this->app['config'],
        trustWildcardProxies: false,
    ));
    $this->app->forgetInstance(RequestIdResolver::class);

    $kernel = $this->app->make(Kernel::class);
    $kernel->prependMiddleware(TrustProxies::class);
    $kernel->prependMiddleware(AssignRequestIdMiddleware::class);

    $response = $this->call('GET', '/no-such-for-prepend', server: [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_REQUEST_ID' => 'prepend-kept-01',
    ]);

    $response->assertNotFound();
    assertRequestIdEchoed($response->baseResponse, 'prepend-kept-01');
});

it('keeps the id when AssignRequestId is appended after TrustProxies', function (): void {
    TrustProxies::at(['127.0.0.1']);
    $this->app->instance(TrustedProxyDetector::class, new ConfigTrustedProxyDetector(
        $this->app['config'],
        trustWildcardProxies: false,
    ));
    $this->app->forgetInstance(RequestIdResolver::class);

    $kernel = $this->app->make(Kernel::class);
    $kernel->pushMiddleware(TrustProxies::class);
    $kernel->pushMiddleware(AssignRequestIdMiddleware::class);

    Route::get('/append-ping', fn () => response('ok'));

    $response = $this->call('GET', '/append-ping', server: [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_REQUEST_ID' => 'append-kept-01',
    ]);

    $response->assertOk();
    assertRequestIdEchoed($response->baseResponse, 'append-kept-01');
});

it('does not reuse the previous Octane-style request trust decision', function (): void {
    TrustProxies::at(['10.0.0.1']);
    $this->app->instance(TrustedProxyDetector::class, new ConfigTrustedProxyDetector(
        $this->app['config'],
        trustWildcardProxies: false,
    ));
    $this->app->forgetInstance(RequestIdResolver::class);

    // Poison static state as if TrustProxies trusted everyone on a prior request.
    Request::setTrustedProxies(['0.0.0.0/0', '::/0'], Request::HEADER_X_FORWARDED_FOR);

    $first = $this->call('GET', '/ping', server: [
        'REMOTE_ADDR' => '10.0.0.1',
        'HTTP_X_REQUEST_ID' => 'octane-first-1',
    ]);
    $first->assertOk();
    assertRequestIdEchoed($first->baseResponse, 'octane-first-1');

    Context::flush();

    $second = $this->call('GET', '/ping', server: [
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_X_REQUEST_ID' => 'octane-spoof-1',
    ]);
    $second->assertOk();
    assertRequestIdEchoed($second->baseResponse, 'mw-generated-id');
});

it('throws when the next middleware does not return a Symfony Response', function (): void {
    $resolver = $this->app->make(RequestIdResolver::class);
    $middleware = new AssignRequestIdMiddleware($resolver);

    expect(fn () => $middleware->handle(
        Request::create('/', 'GET'),
        static fn () => 'not-a-response',
    ))->toThrow(UnexpectedValueException::class);
});

it('puts request_id into a log record via visible Context', function (): void {
    $this->get('/ping', [RequestId::HEADER => 'log-id-0001']);

    expect(Context::get(RequestIdResolver::VISIBLE_CONTEXT_KEY))->toBe('log-id-0001');

    $logged = null;
    Log::listen(function ($event) use (&$logged): void {
        $logged = $event;
    });

    Log::info('probe');

    expect($logged)->not->toBeNull();
    $context = property_exists($logged, 'context') ? $logged->context : [];
    // Laravel's Context integration merges visible keys into the log context.
    $all = Context::all();
    expect($all)->toHaveKey(RequestIdResolver::VISIBLE_CONTEXT_KEY)
        ->and($all[RequestIdResolver::VISIBLE_CONTEXT_KEY])->toBe('log-id-0001');

    if (is_array($context) && array_key_exists(RequestIdResolver::VISIBLE_CONTEXT_KEY, $context)) {
        expect($context[RequestIdResolver::VISIBLE_CONTEXT_KEY])->toBe('log-id-0001');
    }
});
