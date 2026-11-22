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

it('falls back to trustedproxy.proxies when TrustProxies::at is an empty string', function (): void {
    TrustProxies::at('');
    Config::set('trustedproxy.proxies', ['10.0.0.1']);
    $detector = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: false);
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.0.0.1']);

    expect($detector->isTrusted($request))->toBeTrue();
});

it('falls back to trustedproxy.proxies when TrustProxies::at is an empty array', function (): void {
    TrustProxies::at([]);
    Config::set('trustedproxy.proxies', ['10.0.0.7']);
    $detector = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: false);
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.0.0.7']);

    expect($detector->isTrusted($request))->toBeTrue();
});

it('treats a single-star array like the wildcard string', function (): void {
    TrustProxies::at(['*']);
    $detector = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: false);
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']);

    expect($detector->isTrusted($request))->toBeFalse();

    $trusted = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: true);
    expect($trusted->isTrusted($request))->toBeTrue();
});

it('treats a double-star array like the wildcard string', function (): void {
    TrustProxies::at(['**']);
    $denied = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: false);
    $allowed = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: true);
    $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']);

    expect($denied->isTrusted($request))->toBeFalse()
        ->and($allowed->isTrusted($request))->toBeTrue();
});

it('expands the PRIVATE_SUBNETS token like Symfony setTrustedProxies', function (): void {
    TrustProxies::at(['PRIVATE_SUBNETS']);
    $detector = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: false);

    expect($detector->isTrusted(Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.1.2.3'])))->toBeTrue()
        ->and($detector->isTrusted(Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9'])))->toBeFalse();
});

it('keeps entries after a PRIVATE_SUBNETS token in the same list', function (): void {
    // 198.51.100.1 is TEST-NET-2 — not in PRIVATE_RANGES. It must remain after expand.
    TrustProxies::at(['PRIVATE_SUBNETS', '198.51.100.1']);
    $detector = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: false);

    expect($detector->isTrusted(Request::create('/', 'GET', server: ['REMOTE_ADDR' => '198.51.100.1'])))->toBeTrue();
});

it('treats Laravel Cloud with null proxies as a wildcard gated by the opt-in', function (): void {
    $previous = $_ENV['LARAVEL_CLOUD'] ?? null;
    $_ENV['LARAVEL_CLOUD'] = '1';
    $_SERVER['LARAVEL_CLOUD'] = '1';

    try {
        $request = Request::create('https://app.example/test', 'GET', server: [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_HOST' => 'app.example',
        ]);

        $denied = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: false);
        $allowed = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: true);

        expect($denied->isTrusted($request))->toBeFalse()
            ->and($allowed->isTrusted($request))->toBeTrue();
    } finally {
        if ($previous === null) {
            unset($_ENV['LARAVEL_CLOUD'], $_SERVER['LARAVEL_CLOUD']);
        } else {
            $_ENV['LARAVEL_CLOUD'] = $previous;
            $_SERVER['LARAVEL_CLOUD'] = $previous;
        }
    }
});

it('treats Forge hosts with FORGE_SERVER_ID as an implicit wildcard', function (): void {
    $forge = Request::create('https://app.on-forge.com/', 'GET', server: [
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_HOST' => 'app.on-forge.com',
        'FORGE_SERVER_ID' => '42',
    ]);
    $forgeWrongHost = Request::create('https://app.example/', 'GET', server: [
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_HOST' => 'app.example',
        'FORGE_SERVER_ID' => '42',
    ]);
    $forgeMissingId = Request::create('https://app.on-forge.com/', 'GET', server: [
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_HOST' => 'app.on-forge.com',
    ]);

    $allowed = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: true);
    $denied = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: false);

    expect($allowed->isTrusted($forge))->toBeTrue()
        ->and($denied->isTrusted($forge))->toBeFalse()
        ->and($allowed->isTrusted($forgeWrongHost))->toBeFalse()
        ->and($allowed->isTrusted($forgeMissingId))->toBeFalse();
});

it('treats Vapor hosts with VAPOR_SSM_PATH as an implicit wildcard', function (): void {
    $previous = $_ENV['VAPOR_SSM_PATH'] ?? null;
    $_ENV['VAPOR_SSM_PATH'] = '/vapor/ssm';

    try {
        $vapor = Request::create('https://app.on-vapor.com/', 'GET', server: [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_HOST' => 'app.on-vapor.com',
        ]);
        $vaporWrongHost = Request::create('https://app.example/', 'GET', server: [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_HOST' => 'app.example',
        ]);

        $allowed = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: true);

        expect($allowed->isTrusted($vapor))->toBeTrue()
            ->and($allowed->isTrusted($vaporWrongHost))->toBeFalse();
    } finally {
        if ($previous === null) {
            unset($_ENV['VAPOR_SSM_PATH']);
        } else {
            $_ENV['VAPOR_SSM_PATH'] = $previous;
        }
    }
});

it('honours a TrustProxies subclass $proxies when at() is unset', function (): void {
    $subclass = new class extends TrustProxies
    {
        /** @var list<string> */
        protected $proxies = ['10.9.8.7'];
    };

    app()->instance(TrustProxies::class, $subclass);

    try {
        $detector = new ConfigTrustedProxyDetector(Config::getFacadeRoot(), trustWildcardProxies: false);
        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '10.9.8.7']);

        expect($detector->isTrusted($request))->toBeTrue()
            ->and($detector->isTrusted(Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9'])))->toBeFalse();
    } finally {
        app()->forgetInstance(TrustProxies::class);
    }
});
