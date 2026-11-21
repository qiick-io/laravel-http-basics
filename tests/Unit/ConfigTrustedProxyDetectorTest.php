<?php

declare(strict_types=1);

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Qiick\HttpBasics\RequestId\ConfigTrustedProxyDetector;

beforeEach(function (): void {
    TrustProxies::flushState();
});

afterEach(function (): void {
    TrustProxies::flushState();
});

it('trusts REMOTE_ADDR when TrustProxies::at lists that proxy', function (): void {
    TrustProxies::at(['10.0.0.1']);

    $detector = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: false);
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.0.0.1']);

    expect($detector->isTrusted($request))->toBeTrue();
});

it('rejects a client when REMOTE_ADDR is not in TrustProxies::at', function (): void {
    TrustProxies::at(['10.0.0.1']);

    $detector = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: false);
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']);

    expect($detector->isTrusted($request))->toBeFalse();
});

it('does not trust wildcard proxies by default', function (): void {
    TrustProxies::at('*');

    // Constructor default must stay false (kills FalseToTrue on the default arg).
    $detector = new ConfigTrustedProxyDetector(Config::getFacadeRoot());
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']);

    expect($detector->isTrusted($request))->toBeFalse();
});

it('trusts wildcard proxies only when explicitly enabled', function (): void {
    TrustProxies::at('*');

    $detector = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: true);
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']);

    expect($detector->isTrusted($request))->toBeTrue();
});

it('reads trustedproxy.proxies when TrustProxies::at is unset', function (): void {
    Config::set('trustedproxy.proxies', ['10.0.0.5']);

    $detector = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: false);
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.0.0.5']);

    expect($detector->isTrusted($request))->toBeTrue();
});

it('does not depend on Request::isFromTrustedProxy static state', function (): void {
    // Poison static trusted-proxy state as if a previous Octane request ran TrustProxies.
    Request::setTrustedProxies(['0.0.0.0/0', '::/0'], Request::HEADER_X_FORWARDED_FOR);

    TrustProxies::at(['10.0.0.1']);
    $detector = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: false);

    $first = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.0.0.1']);
    $second = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']);

    expect($detector->isTrusted($first))->toBeTrue()
        ->and($detector->isTrusted($second))->toBeFalse()
        // Static state would have said true for both under the poisoned catch-all.
        ->and($second->isFromTrustedProxy())->toBeTrue();
});

it('rejects empty REMOTE_ADDR', function (): void {
    TrustProxies::at(['10.0.0.1']);
    $detector = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: false);
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '']);

    expect($detector->isTrusted($request))->toBeFalse();
});

it('rejects a missing REMOTE_ADDR without type-erroring', function (): void {
    TrustProxies::at(['10.0.0.1']);
    $detector = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: false);
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.0.0.1']);
    $request->server->remove('REMOTE_ADDR');

    expect($detector->isTrusted($request))->toBeFalse();
});

it('rejects when no proxies are configured', function (): void {
    $detector = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: false);
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.0.0.1']);

    expect($detector->isTrusted($request))->toBeFalse();
});

it('parses comma-separated TrustProxies::at strings', function (): void {
    TrustProxies::at('10.0.0.1, 10.0.0.2');
    $detector = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: false);

    expect($detector->isTrusted(Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.0.0.2'])))->toBeTrue()
        ->and($detector->isTrusted(Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.0.0.9'])))->toBeFalse();
});

it('ignores blank entries in a comma-separated proxy string', function (): void {
    TrustProxies::at('10.0.0.1, , 10.0.0.2');
    $detector = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: false);

    expect($detector->isTrusted(Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.0.0.2'])))->toBeTrue();
});

it('ignores blank and non-string entries in a proxy array', function (): void {
    TrustProxies::at(['10.0.0.1', '', 123, null, '10.0.0.2']);
    $detector = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: false);

    expect($detector->isTrusted(Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.0.0.2'])))->toBeTrue()
        ->and($detector->isTrusted(Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.0.0.9'])))->toBeFalse();
});

it('resolves the REMOTE_ADDR token in the trusted list', function (): void {
    TrustProxies::at(['REMOTE_ADDR']);
    $detector = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: false);
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '198.51.100.4']);

    expect($detector->isTrusted($request))->toBeTrue();
});

it('does not trust double-star wildcard without the opt-in', function (): void {
    TrustProxies::at('**');
    $detector = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: false);
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']);

    expect($detector->isTrusted($request))->toBeFalse();
});

it('rejects a non-string non-array trustedproxy.proxies value', function (): void {
    Config::set('trustedproxy.proxies', 123);
    $detector = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: false);
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.0.0.1']);

    expect($detector->isTrusted($request))->toBeFalse();
});

it('rejects an empty proxy list after filtering blanks', function (): void {
    TrustProxies::at(['', '']);
    $detector = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: false);
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.0.0.1']);

    expect($detector->isTrusted($request))->toBeFalse();
});

it('rejects an empty TrustProxies::at string', function (): void {
    TrustProxies::at('');
    $detector = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: false);
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.0.0.1']);

    expect($detector->isTrusted($request))->toBeFalse();
});
