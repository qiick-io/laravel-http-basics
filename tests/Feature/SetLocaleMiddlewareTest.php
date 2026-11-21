<?php

declare(strict_types=1);

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

it('does not add Vary when the locale did not come from the header', function (): void {
    // Call without Accept-Language (Symfony defaults would otherwise negotiate en).
    $response = $this->call('GET', '/api/ping', server: [
        'HTTP_ACCEPT_LANGUAGE' => '',
    ]);

    $response->assertOk();
    assertContentLanguage($response->baseResponse, 'en');
    expect($response->headers->get('Vary'))->toBeNull();
});

it('ignores Accept-Language on web routes', function (): void {
    $response = $this->get('/dashboard', ['Accept-Language' => 'de']);

    $response->assertOk();
    assertContentLanguage($response->baseResponse, 'en');
    expect($response->headers->get('Vary'))->toBeNull();
});

it('carries Content-Language on error responses', function (): void {
    $response = $this->get('/api/boom', ['Accept-Language' => 'fr']);

    $response->assertStatus(429);
    assertContentLanguage($response->baseResponse, 'fr');
    assertVaryAcceptLanguage($response->baseResponse);
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
    expect($response->headers->get('Vary'))->toBeNull();
});
