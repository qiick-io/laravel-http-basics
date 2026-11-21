<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Qiick\HttpBasics\Locale\LocaleNegotiator;
use Qiick\HttpBasics\Locale\LocaleSource;
use Qiick\HttpBasics\Locale\MachineDoorClassifier;
use Qiick\HttpBasics\Testing\ArraySupportedLocales;
use Qiick\HttpBasics\Testing\FixedDefaultLocaleResolver;

function makeNegotiator(
    array $supported = ['en', 'de', 'fr'],
    string $default = 'en',
    array $doors = ['api/*', 'mcp', 'mcp/*'],
    string $fallback = 'en',
    int $maxHeaderBytes = 1024,
    int $maxLanguages = 20,
): LocaleNegotiator {
    return new LocaleNegotiator(
        new ArraySupportedLocales($supported),
        new FixedDefaultLocaleResolver($default),
        new MachineDoorClassifier($doors),
        'locale',
        $fallback,
        $maxHeaderBytes,
        $maxLanguages,
    );
}

function machineRequest(string $path = '/api/items', array $headers = []): Request
{
    $request = Request::create($path, 'GET');
    // Symfony Request::create injects a default Accept-Language; drop it unless
    // the test sets one explicitly so "absent header" cases are real.
    $request->headers->remove('Accept-Language');
    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    return $request;
}

function webRequest(array $headers = []): Request
{
    $request = Request::create('/dashboard', 'GET');
    $request->headers->remove('Accept-Language');
    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    return $request;
}

it('honours q-values and ignores q=0 on machine doors', function (): void {
    $negotiator = makeNegotiator();
    $request = machineRequest(headers: ['Accept-Language' => 'de;q=0,en;q=0.9']);

    $resolution = $negotiator->resolve($request);

    expect($resolution->locale)->toBe('en')
        ->and($resolution->source)->toBe(LocaleSource::Header);
});

it('keeps the highest q when a tag is duplicated', function (): void {
    $negotiator = makeNegotiator(['en', 'de']);
    $request = machineRequest(headers: ['Accept-Language' => 'de;q=0.2,en;q=0.5,de;q=0.9']);

    expect($negotiator->resolve($request)->locale)->toBe('de');
});

it('treats an oversize Accept-Language as absent', function (): void {
    $negotiator = makeNegotiator(maxHeaderBytes: 32);
    $request = machineRequest(headers: [
        'Accept-Language' => 'de,'.str_repeat('x', 100),
    ]);

    $resolution = $negotiator->resolve($request);

    expect($resolution->source)->not->toBe(LocaleSource::Header)
        ->and($resolution->locale)->toBe('en');
});

it('still accepts an Accept-Language of exactly maxHeaderBytes', function (): void {
    $header = 'de';
    $negotiator = makeNegotiator(['en', 'de'], maxHeaderBytes: strlen($header));
    $request = machineRequest(headers: ['Accept-Language' => $header]);

    expect($negotiator->resolve($request)->locale)->toBe('de');
});

it('treats too many language ranges as absent', function (): void {
    $ranges = [];
    for ($i = 0; $i < 25; $i++) {
        $ranges[] = 'xx'.$i.';q=0.5';
    }
    $negotiator = makeNegotiator(supported: ['en', 'de'], maxLanguages: 20);
    $request = machineRequest(headers: ['Accept-Language' => implode(',', $ranges).',de']);

    $resolution = $negotiator->resolve($request);

    expect($resolution->source)->toBe(LocaleSource::Default);
});

it('still accepts exactly maxLanguages ranges', function (): void {
    $ranges = [];
    for ($i = 0; $i < 19; $i++) {
        $ranges[] = 'xx'.$i.';q=0.1';
    }
    $ranges[] = 'de;q=0.9';
    $negotiator = makeNegotiator(supported: ['en', 'de'], maxLanguages: 20);
    $request = machineRequest(headers: ['Accept-Language' => implode(',', $ranges)]);

    expect($negotiator->resolve($request)->locale)->toBe('de');
});

it('matches de-DE to available de and rejects case-folded EN', function (): void {
    $negotiator = makeNegotiator(['en', 'de']);

    $de = $negotiator->resolve(machineRequest(headers: ['Accept-Language' => 'de-DE']));
    expect($de->locale)->toBe('de')->and($de->source)->toBe(LocaleSource::Header);

    $enUpper = $negotiator->resolve(machineRequest(headers: ['Accept-Language' => 'EN']));
    expect($enUpper->source)->not->toBe(LocaleSource::Header);
});

it('normalises underscores both ways', function (): void {
    $fromTag = makeNegotiator(['en', 'de_DE']);
    expect($fromTag->resolve(machineRequest(headers: ['Accept-Language' => 'de-DE']))->locale)
        ->toBe('de_DE');

    $fromAvailable = makeNegotiator(['en', 'de']);
    expect($fromAvailable->resolve(machineRequest(headers: ['Accept-Language' => 'de_DE']))->locale)
        ->toBe('de');
});

it('matches when the available locale is more specific than the tag', function (): void {
    $negotiator = makeNegotiator(['en', 'de-DE']);

    expect($negotiator->resolve(machineRequest(headers: ['Accept-Language' => 'de']))->locale)
        ->toBe('de-DE');
});

it('does not let * outrank an explicit language', function (): void {
    $negotiator = makeNegotiator(['en', 'de']);
    $request = machineRequest(headers: ['Accept-Language' => '*,de;q=0.1']);

    $resolution = $negotiator->resolve($request);

    expect($resolution->locale)->toBe('de');
});

it('uses the first supported locale when only * remains', function (): void {
    $negotiator = makeNegotiator(['fr', 'en']);
    $request = machineRequest(headers: ['Accept-Language' => '*']);

    expect($negotiator->resolve($request)->locale)->toBe('fr');
});

it('drops malformed and out-of-range q-values', function (string $header, string $expected): void {
    $negotiator = makeNegotiator(['en', 'de']);
    $request = machineRequest(headers: ['Accept-Language' => $header]);

    expect($negotiator->resolve($request)->locale)->toBe($expected);
})->with([
    'non-numeric' => ['de;q=abc,en;q=0.8', 'en'],
    'empty q' => ['de;q=,en;q=0.8', 'en'],
    'above one' => ['de;q=1.5,en;q=0.8', 'en'],
    'below zero' => ['de;q=-0.1,en;q=0.8', 'en'],
]);

it('prefers Accept-Language over a later principal on machine doors', function (): void {
    $negotiator = makeNegotiator(['en', 'de']);
    $request = machineRequest(headers: ['Accept-Language' => 'de']);
    $negotiator->resolve($request);

    $negotiator->applyPrincipalLocale($request, 'en');

    expect($negotiator->current($request)->locale)->toBe('de')
        ->and($negotiator->current($request)->source)->toBe(LocaleSource::Header);
});

it('applies principal locale when Accept-Language did not win', function (): void {
    $negotiator = makeNegotiator(['en', 'de', 'fr']);
    $request = machineRequest();
    $negotiator->resolve($request);

    $negotiator->applyPrincipalLocale($request, 'fr');

    expect($negotiator->current($request)->locale)->toBe('fr')
        ->and($negotiator->current($request)->source)->toBe(LocaleSource::Principal);
});

it('ignores empty or unsupported principal locales', function (?string $principal): void {
    $negotiator = makeNegotiator(['en', 'de']);
    $request = machineRequest();
    $negotiator->resolve($request);

    $negotiator->applyPrincipalLocale($request, $principal);

    expect($negotiator->current($request)->locale)->toBe('en')
        ->and($negotiator->current($request)->source)->toBe(LocaleSource::Default);
})->with([
    'null' => [null],
    'empty' => [''],
    'unsupported' => ['xx'],
]);

it('applies principal when the stored attribute is not a LocaleResolution', function (): void {
    $negotiator = makeNegotiator(['en', 'fr']);
    $request = machineRequest();
    $request->attributes->set(LocaleNegotiator::ATTRIBUTE, 'garbage');

    $negotiator->applyPrincipalLocale($request, 'fr');

    expect($negotiator->current($request)->locale)->toBe('fr')
        ->and($negotiator->current($request)->source)->toBe(LocaleSource::Principal);
});

it('ignores a non-string user locale on web requests', function (): void {
    $negotiator = makeNegotiator(['en', 'de']);
    $request = webRequest();
    $request->setUserResolver(fn () => (object) ['locale' => 123]);

    expect($negotiator->resolve($request)->source)->toBe(LocaleSource::Default);
});

it('ignores Accept-Language on web requests', function (): void {
    $negotiator = makeNegotiator(['en', 'de']);
    $request = webRequest(headers: ['Accept-Language' => 'de']);

    $resolution = $negotiator->resolve($request);

    expect($resolution->locale)->toBe('en')
        ->and($resolution->source)->toBe(LocaleSource::Default);
});

it('uses the authenticated user locale on web requests', function (): void {
    $negotiator = makeNegotiator(['en', 'de']);
    $request = webRequest();
    $request->setUserResolver(fn () => (object) ['locale' => 'de']);

    $resolution = $negotiator->resolve($request);

    expect($resolution->locale)->toBe('de')
        ->and($resolution->source)->toBe(LocaleSource::Principal);
});

it('uses the locale cookie on web requests', function (): void {
    $negotiator = makeNegotiator(['en', 'de']);
    $request = webRequest();
    $request->cookies->set('locale', 'de');

    $resolution = $negotiator->resolve($request);

    expect($resolution->locale)->toBe('de')
        ->and($resolution->source)->toBe(LocaleSource::Cookie);
});

it('falls back when the configured default is not supported', function (): void {
    $negotiator = makeNegotiator(
        supported: ['de', 'en'],
        default: 'xx',
        fallback: 'de',
    );

    $resolution = $negotiator->resolve(machineRequest());

    // Must return fallback `de`, not fall through to `en`.
    expect($resolution->locale)->toBe('de')
        ->and($resolution->source)->toBe(LocaleSource::Fallback);
});

it('falls through to en then the first supported locale', function (): void {
    $toEn = makeNegotiator(supported: ['fr', 'en'], default: 'xx', fallback: 'yy');
    expect($toEn->resolve(machineRequest())->locale)->toBe('en');

    $toFirst = makeNegotiator(supported: ['fr', 'de'], default: 'xx', fallback: 'yy');
    expect($toFirst->resolve(machineRequest())->locale)->toBe('fr');
});

it('does not treat a bare language letter as a prefix of a longer tag', function (): void {
    $negotiator = makeNegotiator(['en', 'fr']);
    $request = machineRequest(headers: ['Accept-Language' => 'f']);

    expect($negotiator->resolve($request)->source)->not->toBe(LocaleSource::Header);
});

it('current() re-resolves when no attribute is stored yet', function (): void {
    $negotiator = makeNegotiator(['en', 'de']);
    $request = machineRequest(headers: ['Accept-Language' => 'de']);

    expect($negotiator->current($request)->locale)->toBe('de');
});

it('does not treat den as a prefix of de without a hyphen boundary', function (): void {
    $negotiator = makeNegotiator(['en', 'de']);
    $request = machineRequest(headers: ['Accept-Language' => 'den']);

    expect($negotiator->resolve($request)->source)->not->toBe(LocaleSource::Header);
});

it('orders by q so a later higher-q tag wins over insertion order', function (): void {
    $negotiator = makeNegotiator(['en', 'de', 'fr']);
    $request = machineRequest(headers: ['Accept-Language' => 'en;q=0.2,fr;q=0.9,de;q=0.5']);

    expect($negotiator->resolve($request)->locale)->toBe('fr');
});

it('trims language range parts before matching', function (): void {
    $negotiator = makeNegotiator(['en', 'de']);
    $request = machineRequest(headers: ['Accept-Language' => ' de ;q=0.8 ']);

    expect($negotiator->resolve($request)->locale)->toBe('de');
});

it('skips empty segments between commas without stopping the parse', function (): void {
    $negotiator = makeNegotiator(['en', 'de']);
    $request = machineRequest(headers: ['Accept-Language' => ',,de,,']);

    expect($negotiator->resolve($request)->locale)->toBe('de');
});

it('ignores a q=0 range while keeping a later positive q', function (): void {
    $negotiator = makeNegotiator(['en', 'de']);
    $request = machineRequest(headers: ['Accept-Language' => 'de;q=0,en;q=0.5']);

    expect($negotiator->resolve($request)->locale)->toBe('en');
});

it('returns null for q= with no value and keeps the next range', function (): void {
    $negotiator = makeNegotiator(['en', 'de']);
    $request = machineRequest(headers: ['Accept-Language' => 'de;q=,en']);

    expect($negotiator->resolve($request)->locale)->toBe('en');
});

it('accepts q=1.0 exactly and rejects q above one', function (): void {
    $ok = makeNegotiator(['en', 'de']);
    expect($ok->resolve(machineRequest(headers: ['Accept-Language' => 'de;q=1.0']))->locale)->toBe('de');

    $bad = makeNegotiator(['en', 'de']);
    expect($bad->resolve(machineRequest(headers: ['Accept-Language' => 'de;q=1.01,en;q=0.5']))->locale)->toBe('en');
});

it('falls back when supported locales are empty', function (): void {
    $negotiator = makeNegotiator(supported: [], default: 'xx', fallback: 'zz');

    $resolution = $negotiator->resolve(machineRequest());

    expect($resolution->locale)->toBe('zz')
        ->and($resolution->source)->toBe(LocaleSource::Fallback);
});

it('ignores Accept-Language when the supported list is empty', function (): void {
    $negotiator = makeNegotiator(supported: [], default: 'en', fallback: 'en');
    $request = machineRequest(headers: ['Accept-Language' => 'de']);

    expect($negotiator->resolve($request)->source)->toBe(LocaleSource::Fallback);
});

it('ignores a non-object user resolver on web requests', function (): void {
    $negotiator = makeNegotiator(['en', 'de']);
    $request = webRequest();
    $request->setUserResolver(fn () => 'not-an-object');

    expect($negotiator->resolve($request)->source)->toBe(LocaleSource::Default);
});

it('ignores an empty-string user locale on web requests', function (): void {
    $negotiator = makeNegotiator(['en', 'de']);
    $request = webRequest();
    $request->setUserResolver(fn () => (object) ['locale' => '']);

    expect($negotiator->resolve($request)->source)->toBe(LocaleSource::Default);
});

it('drops a lone q=0 range instead of treating it as a match', function (): void {
    $negotiator = makeNegotiator(['en', 'de']);
    $request = machineRequest(headers: ['Accept-Language' => 'de;q=0']);

    expect($negotiator->resolve($request)->source)->not->toBe(LocaleSource::Header);
});

it('skips a bare semicolon range and keeps the next tag', function (): void {
    $negotiator = makeNegotiator(['en', 'de']);
    $request = machineRequest(headers: ['Accept-Language' => ';q=0.5,de']);

    expect($negotiator->resolve($request)->locale)->toBe('de');
});

it('rejects a dotted non-numeric q that would cast to a valid float', function (): void {
    $negotiator = makeNegotiator(['en', 'de']);
    $request = machineRequest(headers: ['Accept-Language' => 'de;q=1.0.0,en;q=0.5']);

    expect($negotiator->resolve($request)->locale)->toBe('en');
});

it('ignores a star-only Accept-Language when nothing is supported', function (): void {
    $negotiator = makeNegotiator(supported: [], default: 'en', fallback: 'en');
    $request = machineRequest(headers: ['Accept-Language' => '*']);

    expect($negotiator->resolve($request)->source)->toBe(LocaleSource::Fallback);
});
