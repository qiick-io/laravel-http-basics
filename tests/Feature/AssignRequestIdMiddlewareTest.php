<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Qiick\HttpBasics\Contracts\IdGenerator;
use Qiick\HttpBasics\Contracts\TrustedProxyDetector;
use Qiick\HttpBasics\RequestId\AssignRequestIdMiddleware;
use Qiick\HttpBasics\RequestId\RequestId;
use Qiick\HttpBasics\RequestId\RequestIdResolver;
use Qiick\HttpBasics\Testing\AlwaysTrustedProxyDetector;
use Qiick\HttpBasics\Testing\FixedIdGenerator;
use Qiick\HttpBasics\Testing\NeverTrustedProxyDetector;

use function Qiick\HttpBasics\Testing\assertRequestIdEchoed;

beforeEach(function (): void {
    Context::flush();

    $this->app->instance(IdGenerator::class, new FixedIdGenerator('mw-generated-id'));
    $this->app->instance(TrustedProxyDetector::class, new AlwaysTrustedProxyDetector);

    // Re-resolve so the middleware picks up the test doubles.
    $this->app->forgetInstance(RequestIdResolver::class);

    Route::middleware(AssignRequestIdMiddleware::class)->get('/ping', function () {
        return response('pong', 200);
    });

    Route::middleware(AssignRequestIdMiddleware::class)->get('/boom', function () {
        abort(503, 'unavailable');
    });
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

it('carries a generated id on unmatched routes when middleware is global', function (): void {
    // Kernel-style: push as global so 404 still gets the header.
    $this->app->make(Kernel::class)
        ->pushMiddleware(AssignRequestIdMiddleware::class);

    $response = $this->get('/no-such-route-at-all');

    $response->assertNotFound();
    assertRequestIdEchoed($response->baseResponse, 'mw-generated-id');
});

it('replaces an untrusted inbound id when accept_from is trusted_proxy', function (): void {
    $this->app->instance(TrustedProxyDetector::class, new NeverTrustedProxyDetector);
    $this->app->forgetInstance(RequestIdResolver::class);

    $response = $this->get('/ping', [RequestId::HEADER => 'spoofed-untrusted']);

    $response->assertOk();
    assertRequestIdEchoed($response->baseResponse, 'mw-generated-id');
});

it('does not put the request id into visible Context', function (): void {
    $this->get('/ping', [RequestId::HEADER => 'hidden-only-01']);

    expect(Context::has('http_basics.request_id'))->toBeFalse()
        ->and(Context::getHidden('http_basics.request_id'))->toBe('hidden-only-01');
});
