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
