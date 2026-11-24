<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Vite;
use Qiick\HttpBasics\Security\ViteFriendlyCspPolicyBuilder;

/**
 * Stand-in for Laravel 13+ Vite: exposes `devServerUrl()`.
 */
final class ViteWithDevServerUrl
{
    public function __construct(private mixed $url) {}

    public function devServerUrl(): mixed
    {
        return $this->url;
    }
}

/**
 * Stand-in for Laravel 12 Vite: no `devServerUrl()` method.
 */
final class ViteWithoutDevServerUrl
{
    public function isRunningHot(): bool
    {
        return true;
    }
}

it('includes the Vite hot origin when devServerUrl exists (Laravel 13+)', function (): void {
    Facade::setFacadeApplication(app());
    Vite::swap(new ViteWithDevServerUrl('http://127.0.0.1:5173'));

    $policy = (new ViteFriendlyCspPolicyBuilder)->build(Request::create('/dashboard', 'GET'));

    expect($policy)->toContain('http://127.0.0.1:5173')
        ->and($policy)->toContain("frame-ancestors 'none'");

    Vite::clearResolvedInstance();
});

it('skips the hot origin when Vite has no devServerUrl (Laravel 12)', function (): void {
    Facade::setFacadeApplication(app());
    Vite::swap(new ViteWithoutDevServerUrl);

    $policy = (new ViteFriendlyCspPolicyBuilder)->build(Request::create('/dashboard', 'GET'));

    expect($policy)->not->toContain('5173')
        ->and($policy)->toContain("script-src 'self' 'unsafe-inline' 'unsafe-eval'")
        ->and($policy)->toContain("frame-ancestors 'none'");

    Vite::clearResolvedInstance();
});

it('skips blank or non-http Vite hot urls', function (): void {
    Facade::setFacadeApplication(app());
    Vite::swap(new ViteWithDevServerUrl(''));

    $blank = (new ViteFriendlyCspPolicyBuilder)->build(Request::create('/dashboard', 'GET'));
    expect($blank)->toContain("script-src 'self' 'unsafe-inline' 'unsafe-eval'");

    Vite::swap(new ViteWithDevServerUrl('ftp://vite.test:5173'));
    $ftp = (new ViteFriendlyCspPolicyBuilder)->build(Request::create('/dashboard', 'GET'));
    expect($ftp)->not->toContain('ftp://');

    Vite::clearResolvedInstance();
});

function viteCspWith(mixed $devServerUrl): string
{
    Facade::setFacadeApplication(app());
    Vite::swap(new ViteWithDevServerUrl($devServerUrl));

    try {
        return (new ViteFriendlyCspPolicyBuilder)->build(Request::create('/dashboard', 'GET'));
    } finally {
        Vite::clearResolvedInstance();
    }
}

it('builds the exact default policy without a dev server', function (): void {
    expect(viteCspWith(null))->toBe(
        "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self' ws: wss: http: https:; "
        ."media-src 'self' blob:; worker-src 'self' blob:; frame-ancestors 'none'; base-uri 'self'; "
        ."form-action 'self'; object-src 'none'",
    );
});

it('adds the dev server origin to script, style, image and font sources only', function (): void {
    expect(viteCspWith('https://vite.test:5173/@vite/client'))->toBe(
        "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://vite.test:5173; "
        ."style-src 'self' 'unsafe-inline' https://vite.test:5173; img-src 'self' data: blob: https: https://vite.test:5173; "
        ."font-src 'self' data: https://vite.test:5173; connect-src 'self' ws: wss: http: https:; "
        ."media-src 'self' blob:; worker-src 'self' blob:; frame-ancestors 'none'; base-uri 'self'; "
        ."form-action 'self'; object-src 'none'",
    );
});

it('uses scheme and host alone when the dev server URL has no port', function (): void {
    expect(viteCspWith('http://vite.test'))->toContain("script-src 'self' 'unsafe-inline' 'unsafe-eval' http://vite.test;");
});

it('adds no origin for a dev server URL without a host or with another scheme', function (mixed $url): void {
    expect(viteCspWith($url))->toContain("script-src 'self' 'unsafe-inline' 'unsafe-eval';");
})->with([
    'no host' => ['http:just-a-path'],
    'another scheme' => ['ws://vite.test:5173'],
    'malformed' => ['http:///'],
    'not a string' => [5173],
]);

it('adds no origin when no Vite is bound', function (): void {
    Facade::setFacadeApplication(app());
    Vite::clearResolvedInstance();
    app()->offsetUnset(Illuminate\Foundation\Vite::class);

    expect((new ViteFriendlyCspPolicyBuilder)->build(Request::create('/dashboard', 'GET')))
        ->toContain("script-src 'self' 'unsafe-inline' 'unsafe-eval';");
});
