<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Qiick\HttpBasics\Security\CspMode;
use Qiick\HttpBasics\Security\CspPolicyBuilder;
use Qiick\HttpBasics\Security\SecurityHeaderBuilder;
use Qiick\HttpBasics\Security\SecurityHeadersPolicy;
use Qiick\HttpBasics\Security\SetSecurityHeadersMiddleware;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function Qiick\HttpBasics\Testing\assertSecurityHeaders;

beforeEach(function (): void {
    $this->app->instance(CspPolicyBuilder::class, new class implements CspPolicyBuilder
    {
        public function build(Request $request): string
        {
            return "default-src 'self'; frame-ancestors 'none'";
        }
    });

    $this->app->instance(SecurityHeadersPolicy::class, new SecurityHeadersPolicy(
        cspMode: CspMode::Enforce,
        machineDoorPatterns: ['api/*', 'mcp', 'mcp/*'],
    ));

    $this->app->forgetInstance(SecurityHeaderBuilder::class);
    $this->app->forgetInstance(SetSecurityHeadersMiddleware::class);

    Route::middleware(SetSecurityHeadersMiddleware::class)->get('/dashboard', function () {
        return response('ok', 200);
    });

    Route::middleware(SetSecurityHeadersMiddleware::class)->get('/api/items', function () {
        return response()->json(['ok' => true]);
    });

    Route::middleware(SetSecurityHeadersMiddleware::class)->get('/dashboard/boom', function () {
        abort(500, 'nope');
    });
});

it('sets defense headers on web responses', function (): void {
    $response = $this->get('/dashboard');

    $response->assertOk();
    assertSecurityHeaders($response->baseResponse);
});

it('sets baseline only on machine doors', function (): void {
    $response = $this->get('/api/items');

    $response->assertOk();
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('Permissions-Policy'))->not->toBeNull()
        ->and($response->headers->get('X-Frame-Options'))->toBeNull()
        ->and($response->headers->get('Content-Security-Policy'))->toBeNull();
});

it('keeps an existing CSP on the response', function (): void {
    Route::middleware(SetSecurityHeadersMiddleware::class)->get('/dashboard/sandbox', function () {
        return response('ok', 200)->header('Content-Security-Policy', "default-src 'none'");
    });

    $response = $this->get('/dashboard/sandbox');

    $response->assertOk();
    expect($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'");
});

it('applies headers to error responses without buffering a body', function (): void {
    $response = $this->get('/dashboard/boom');

    $response->assertStatus(500);
    assertSecurityHeaders($response->baseResponse);
});

it('applies headers to streamed responses without reading the body', function (): void {
    Route::middleware(SetSecurityHeadersMiddleware::class)->get('/dashboard/stream', function () {
        return new StreamedResponse(function (): void {
            echo 'chunk';
        });
    });

    $response = $this->get('/dashboard/stream');

    $response->assertOk();
    assertSecurityHeaders($response->baseResponse);
});

it('does nothing when security is disabled', function (): void {
    $this->app->instance(SecurityHeadersPolicy::class, new SecurityHeadersPolicy(enabled: false));
    $this->app->forgetInstance(SetSecurityHeadersMiddleware::class);

    Route::middleware(SetSecurityHeadersMiddleware::class)->get('/dashboard/off', function () {
        return response('ok', 200);
    });

    $response = $this->get('/dashboard/off');

    $response->assertOk();
    expect($response->headers->get('X-Content-Type-Options'))->toBeNull()
        ->and($response->headers->get('Content-Security-Policy'))->toBeNull();
});
