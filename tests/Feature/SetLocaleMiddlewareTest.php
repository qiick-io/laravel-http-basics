<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Qiick\HttpBasics\Contracts\DefaultLocaleResolver;
use Qiick\HttpBasics\Contracts\SupportedLocales;
use Qiick\HttpBasics\Locale\LocaleNegotiator;
use Qiick\HttpBasics\Locale\MachineDoorClassifier;
use Qiick\HttpBasics\Locale\SetLocaleMiddleware;
use Qiick\HttpBasics\Testing\ArraySupportedLocales;
use Qiick\HttpBasics\Testing\FixedDefaultLocaleResolver;

use function Qiick\HttpBasics\Testing\assertContentLanguage;
use function Qiick\HttpBasics\Testing\assertVaryAcceptLanguage;

beforeEach(function (): void {
    $this->app->instance(SupportedLocales::class, new ArraySupportedLocales(['en', 'de', 'fr']));
    $this->app->instance(DefaultLocaleResolver::class, new FixedDefaultLocaleResolver('en'));
    $this->app->forgetInstance(LocaleNegotiator::class);
    $this->app->forgetInstance(MachineDoorClassifier::class);

    Route::middleware(SetLocaleMiddleware::class)->get('/api/ping', function () {
        return response('pong', 200);
    });

    Route::middleware(SetLocaleMiddleware::class)->get('/api', function () {
        return response('api-root', 200);
    });

    Route::middleware(SetLocaleMiddleware::class)->get('/dashboard', function () {
        return response('ok', 200);
    });

    Route::middleware(SetLocaleMiddleware::class)->get('/api/boom', function () {
        abort(429, 'slow down');
    });
});

it('sets Content-Language and Vary from Accept-Language on machine doors', function (): void {
    $response = $this->get('/api/ping', ['Accept-Language' => 'de']);

    $response->assertOk();
    expect(App::getLocale())->toBe('de');
    assertContentLanguage($response->baseResponse, 'de');
    assertVaryAcceptLanguage($response->baseResponse);
});

it('adds Vary when Accept-Language was read even if it did not win', function (): void {
    $response = $this->get('/api/ping', ['Accept-Language' => 'xx']);

    $response->assertOk();
    assertContentLanguage($response->baseResponse, 'en');
    assertVaryAcceptLanguage($response->baseResponse);
});

it('adds Vary when an empty Accept-Language header was still read', function (): void {
    $response = $this->call('GET', '/api/ping', server: [
        'HTTP_ACCEPT_LANGUAGE' => '',
    ]);

    $response->assertOk();
    assertContentLanguage($response->baseResponse, 'en');
    assertVaryAcceptLanguage($response->baseResponse);
});

it('ignores Accept-Language on web routes and does not Vary', function (): void {
    $response = $this->get('/dashboard', ['Accept-Language' => 'de']);

    $response->assertOk();
    assertContentLanguage($response->baseResponse, 'en');
    expect($response->headers->get('Vary'))->toBeNull();
});

it('honours an encrypted locale cookie after EncryptCookies on the web stack', function (): void {
    Route::middleware([EncryptCookies::class, SetLocaleMiddleware::class])
        ->get('/web/cookie', function () {
            return response('ok', 200);
        });

    // withCookie encrypts by default; EncryptCookies decrypts before SetLocale runs.
    $response = $this->withCookie('locale', 'de')->get('/web/cookie');

    $response->assertOk();
    assertContentLanguage($response->baseResponse, 'de');
    expect(App::getLocale())->toBe('de');
});

it('negotiates Accept-Language on /api itself', function (): void {
    $response = $this->get('/api', ['Accept-Language' => 'fr']);

    $response->assertOk();
    assertContentLanguage($response->baseResponse, 'fr');
    assertVaryAcceptLanguage($response->baseResponse);
});

it('carries Content-Language on error responses', function (): void {
    $response = $this->get('/api/boom', ['Accept-Language' => 'fr']);

    $response->assertStatus(429);
    assertContentLanguage($response->baseResponse, 'fr');
    assertVaryAcceptLanguage($response->baseResponse);
});

it('carries Content-Language on a maintenance 503 when prepended globally', function (): void {
    $kernel = $this->app->make(Kernel::class);
    $kernel->prependMiddleware(PreventRequestsDuringMaintenance::class);
    $kernel->prependMiddleware(SetLocaleMiddleware::class);

    $this->app->maintenanceMode()->activate([]);

    try {
        $response = $this->get('/dashboard');
        $response->assertStatus(503);
        assertContentLanguage($response->baseResponse, 'en');
    } finally {
        $this->app->maintenanceMode()->deactivate();
    }
});

it('preserves an existing Vary token when appending Accept-Language', function (): void {
    Route::middleware(SetLocaleMiddleware::class)->get('/api/vary', function () {
        return response('ok', 200)->header('Vary', 'Origin');
    });

    $response = $this->get('/api/vary', ['Accept-Language' => 'de']);

    $response->assertOk();
    expect($response->headers->get('Vary'))->toBe('Origin, Accept-Language');
});

it('applies principal locale inside $next when header did not win', function (): void {
    Route::middleware(SetLocaleMiddleware::class)->get('/api/keyed', function () {
        app(LocaleNegotiator::class)->applyPrincipalLocale(request(), 'fr');
        App::setLocale('fr');

        return response('ok', 200);
    });

    $response = $this->call('GET', '/api/keyed', server: [
        'HTTP_ACCEPT_LANGUAGE' => '',
    ]);

    $response->assertOk();
    assertContentLanguage($response->baseResponse, 'fr');
    // Empty Accept-Language was still read → Vary under the amended rule.
    assertVaryAcceptLanguage($response->baseResponse);
});
