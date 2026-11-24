<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Qiick\HttpBasics\Locale\LocaleNegotiator;
use Qiick\HttpBasics\Locale\MachineDoorClassifier;
use Qiick\HttpBasics\Locale\SetLocaleMiddleware;
use Qiick\HttpBasics\Testing\ArraySupportedLocales;
use Qiick\HttpBasics\Testing\FixedDefaultLocaleResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * @param  list<string>  $supported
 */
function setLocaleNegotiator(array $supported = ['en', 'de']): LocaleNegotiator
{
    return new LocaleNegotiator(
        new ArraySupportedLocales($supported),
        new FixedDefaultLocaleResolver('en'),
        new MachineDoorClassifier(['api/*']),
        'locale',
        'en',
        1024,
        20,
    );
}

/**
 * @param  array<string, string>  $headers
 */
function setLocaleApiRequest(array $headers = []): Request
{
    $request = Request::create('/api/items', 'GET');
    $request->headers->remove('Accept-Language');

    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    return $request;
}

it('sets the negotiated locale before the handler runs', function (): void {
    $middleware = new SetLocaleMiddleware(setLocaleNegotiator(['en', 'de']));
    $seenByHandler = null;

    $middleware->handle(setLocaleApiRequest(headers: ['Accept-Language' => 'de']), static function () use (&$seenByHandler): Response {
        $seenByHandler = App::getLocale();

        return new Response;
    });

    expect($seenByHandler)->toBe('de');
});

it('leaves the app on the final locale when the handler applied the principal one', function (): void {
    $negotiator = setLocaleNegotiator(['en', 'de', 'fr']);
    $middleware = new SetLocaleMiddleware($negotiator);
    $request = setLocaleApiRequest();

    $response = $middleware->handle($request, static function () use ($negotiator, $request): Response {
        App::setLocale('en');
        $negotiator->applyPrincipalLocale($request, 'fr');

        return new Response;
    });

    expect(App::getLocale())->toBe('fr')
        ->and($response->headers->get('Content-Language'))->toBe('fr');
});

it('refuses a handler that does not return a Symfony response', function (): void {
    $middleware = new SetLocaleMiddleware(setLocaleNegotiator());

    expect(fn () => $middleware->handle(setLocaleApiRequest(), static fn (): string => 'not a response'))
        ->toThrow(UnexpectedValueException::class);
});

it('appends Accept-Language to Vary once, whatever is already there', function (?string $existingVary, string $expected): void {
    $middleware = new SetLocaleMiddleware(setLocaleNegotiator(['en', 'de']));

    $response = $middleware->handle(setLocaleApiRequest(headers: ['Accept-Language' => 'de']), static function () use ($existingVary): Response {
        $response = new Response;

        if ($existingVary !== null) {
            $response->headers->set('Vary', $existingVary);
        }

        return $response;
    });

    expect($response->headers->get('Vary'))->toBe($expected);
})->with([
    'no Vary' => [null, 'Accept-Language'],
    'an empty Vary' => ['', 'Accept-Language'],
    'a token sorting after it' => ['Origin', 'Origin, Accept-Language'],
    'a token sorting before it' => ['Accept', 'Accept, Accept-Language'],
    'already there, padded and lower-case' => ['Origin , accept-language', 'Origin, accept-language'],
]);

it('keeps every Vary line when it appends Accept-Language', function (): void {
    // A shared cache keyed on one line only would serve a Cookie-bound variant to everyone.
    $middleware = new SetLocaleMiddleware(setLocaleNegotiator(['en', 'de']));

    $response = $middleware->handle(setLocaleApiRequest(headers: ['Accept-Language' => 'de']), static function (): Response {
        $response = new Response;
        $response->headers->set('Vary', 'Origin');
        $response->headers->set('Vary', ['Cookie', 'origin, ACCEPT-LANGUAGE'], false);

        return $response;
    });

    expect($response->headers->all('vary'))->toBe(['origin, Cookie, ACCEPT-LANGUAGE']);
});
