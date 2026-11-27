<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Qiick\HttpBasics\Security\CspMode;
use Qiick\HttpBasics\Security\CspPolicyBuilder;
use Qiick\HttpBasics\Security\SecurityHeaderBuilder;
use Qiick\HttpBasics\Security\SecurityHeadersPolicy;
use Symfony\Component\HttpFoundation\Response;

it('preserves the default machine doors and honors an explicitly empty list', function (): void {
    expect((new SecurityHeadersPolicy)->machineDoorPatterns)->toBe(['api/*', 'mcp', 'mcp/*'])
        ->and((new SecurityHeadersPolicy(machineDoorPatterns: []))->machineDoorPatterns)->toBe([]);
});

it('leaves HSTS and its extra flags off by default', function (): void {
    $policy = new SecurityHeadersPolicy;

    expect($policy->hstsMaxAge)->toBe(0)
        ->and($policy->hstsIncludeSubdomains)->toBeFalse()
        ->and($policy->hstsPreload)->toBeFalse();
});

function makeSecurityBuilder(?CspPolicyBuilder $csp = null): SecurityHeaderBuilder
{
    $csp ??= new class implements CspPolicyBuilder
    {
        public function build(Request $request): string
        {
            return "default-src 'self'; frame-ancestors 'none'";
        }
    };

    return new SecurityHeaderBuilder($csp);
}

function makeSecurityPolicy(array $overrides = []): SecurityHeadersPolicy
{
    return new SecurityHeadersPolicy(...array_merge([
        'enabled' => true,
        'xFrameOptions' => 'DENY',
        'referrerPolicy' => 'strict-origin-when-cross-origin',
        'permissionsPolicy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        'cspMode' => CspMode::Enforce,
        'csp' => null,
        'cspReportOnly' => null,
        'cspReportUri' => null,
        'hstsMaxAge' => 0,
        'hstsIncludeSubdomains' => false,
        'hstsPreload' => false,
        'baselineOnMachineDoors' => true,
        'machineDoorPatterns' => ['api/*', 'mcp', 'mcp/*'],
    ], $overrides));
}

it('returns no headers when security is disabled', function (): void {
    $builder = makeSecurityBuilder();
    $request = Request::create('/dashboard', 'GET');
    $response = new Response('ok');

    expect($builder->forResponse($request, $response, makeSecurityPolicy(['enabled' => false])))->toBe([]);
});

it('sets the full web header set including CSP in enforce mode', function (): void {
    $builder = makeSecurityBuilder();
    $request = Request::create('/dashboard', 'GET');
    $response = new Response('ok');

    $headers = $builder->forResponse($request, $response, makeSecurityPolicy());

    expect($headers)->toHaveKeys([
        'X-Content-Type-Options',
        'X-Frame-Options',
        'Referrer-Policy',
        'Permissions-Policy',
        'Content-Security-Policy',
    ])
        ->and($headers['X-Content-Type-Options'])->toBe('nosniff')
        ->and($headers['X-Frame-Options'])->toBe('DENY')
        ->and($headers['Content-Security-Policy'])->toContain("frame-ancestors 'none'")
        ->and($headers)->not->toHaveKey('Content-Security-Policy-Report-Only')
        ->and($headers)->not->toHaveKey('Strict-Transport-Security');
});

it('emits report-only CSP and not enforce when mode is report-only', function (): void {
    $builder = makeSecurityBuilder();
    $request = Request::create('/dashboard', 'GET');
    $response = new Response('ok');

    $headers = $builder->forResponse($request, $response, makeSecurityPolicy([
        'cspMode' => CspMode::ReportOnly,
    ]));

    expect($headers)->toHaveKey('Content-Security-Policy-Report-Only')
        ->and($headers)->not->toHaveKey('Content-Security-Policy');
});

it('emits both CSP headers when mode is both', function (): void {
    $builder = makeSecurityBuilder();
    $request = Request::create('/dashboard', 'GET');
    $response = new Response('ok');

    $headers = $builder->forResponse($request, $response, makeSecurityPolicy([
        'cspMode' => CspMode::Both,
        'csp' => "default-src 'self'",
        'cspReportOnly' => "default-src 'none'",
    ]));

    expect($headers['Content-Security-Policy'])->toBe("default-src 'self'")
        ->and($headers['Content-Security-Policy-Report-Only'])->toBe("default-src 'none'");
});

it('skips CSP headers when mode is off', function (): void {
    $builder = makeSecurityBuilder();
    $request = Request::create('/dashboard', 'GET');
    $response = new Response('ok');

    $headers = $builder->forResponse($request, $response, makeSecurityPolicy([
        'cspMode' => CspMode::Off,
    ]));

    expect($headers)->not->toHaveKey('Content-Security-Policy')
        ->and($headers)->not->toHaveKey('Content-Security-Policy-Report-Only')
        ->and($headers)->toHaveKey('X-Frame-Options');
});

it('never clobbers an existing HSTS header', function (): void {
    $builder = makeSecurityBuilder();
    $request = Request::create('/dashboard', 'GET');
    $request->server->set('HTTPS', 'on');
    $response = new Response('ok');
    $response->headers->set('Strict-Transport-Security', 'max-age=1');

    $headers = $builder->forResponse($request, $response, makeSecurityPolicy([
        'hstsMaxAge' => 31536000,
    ]));

    expect($headers)->not->toHaveKey('Strict-Transport-Security');
});

it('adds no CSP of either kind when Content-Security-Policy is already present', function (): void {
    $builder = makeSecurityBuilder();
    $response = new Response('ok');
    $response->headers->set('Content-Security-Policy', "default-src 'none'");

    $headers = $builder->forResponse(Request::create('/dashboard', 'GET'), $response, makeSecurityPolicy([
        'cspMode' => CspMode::Both,
        'csp' => "default-src 'self'",
        'cspReportOnly' => "default-src 'none'",
    ]));

    expect($headers)->not->toHaveKey('Content-Security-Policy')
        ->and($headers)->not->toHaveKey('Content-Security-Policy-Report-Only');
});

it('adds no CSP of either kind when Content-Security-Policy-Report-Only is already present', function (): void {
    $builder = makeSecurityBuilder();
    $response = new Response('ok');
    $response->headers->set('Content-Security-Policy-Report-Only', "default-src 'none'");

    $headers = $builder->forResponse(Request::create('/dashboard', 'GET'), $response, makeSecurityPolicy([
        'cspMode' => CspMode::Both,
        'csp' => "default-src 'self'",
        'cspReportOnly' => "default-src 'none'",
    ]));

    expect($headers)->not->toHaveKey('Content-Security-Policy')
        ->and($headers)->not->toHaveKey('Content-Security-Policy-Report-Only');
});

it('never clobbers X-Frame-Options, Referrer-Policy, Permissions-Policy or nosniff', function (): void {
    $builder = makeSecurityBuilder();
    $request = Request::create('/dashboard', 'GET');
    $response = new Response('ok');
    $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
    $response->headers->set('Referrer-Policy', 'no-referrer');
    $response->headers->set('Permissions-Policy', 'camera=(self)');
    $response->headers->set('X-Content-Type-Options', 'nosniff');

    $headers = $builder->forResponse($request, $response, makeSecurityPolicy());

    expect($headers)->not->toHaveKey('X-Frame-Options')
        ->and($headers)->not->toHaveKey('Referrer-Policy')
        ->and($headers)->not->toHaveKey('Permissions-Policy')
        ->and($headers)->not->toHaveKey('X-Content-Type-Options');
});

it('appends report-uri onto the built or override CSP string before set', function (): void {
    $builder = makeSecurityBuilder();
    $request = Request::create('/dashboard', 'GET');
    $response = new Response('ok');

    $headers = $builder->forResponse($request, $response, makeSecurityPolicy([
        'csp' => "default-src 'self'",
        'cspReportUri' => 'https://example.test/csp',
    ]));

    expect($headers['Content-Security-Policy'])->toBe("default-src 'self'; report-uri https://example.test/csp");
});

it('sets HSTS only on secure requests when max_age is positive', function (): void {
    $builder = makeSecurityBuilder();
    $policy = makeSecurityPolicy([
        'hstsMaxAge' => 31536000,
        'hstsIncludeSubdomains' => true,
        'hstsPreload' => true,
    ]);

    $insecure = Request::create('http://example.test/dashboard', 'GET');
    expect($builder->forResponse($insecure, new Response('ok'), $policy))
        ->not->toHaveKey('Strict-Transport-Security');

    $secure = Request::create('https://example.test/dashboard', 'GET');
    $secure->server->set('HTTPS', 'on');
    $headers = $builder->forResponse($secure, new Response('ok'), $policy);

    expect($headers['Strict-Transport-Security'])->toBe('max-age=31536000; includeSubDomains; preload');
});

it('omits HSTS flag tokens when they are not configured', function (): void {
    $builder = makeSecurityBuilder();
    $secure = Request::create('https://example.test/dashboard', 'GET');
    $secure->server->set('HTTPS', 'on');

    $headers = $builder->forResponse($secure, new Response('ok'), makeSecurityPolicy([
        'hstsMaxAge' => 60,
    ]));

    expect($headers['Strict-Transport-Security'])->toBe('max-age=60')
        ->and($headers['Strict-Transport-Security'])->not->toContain('includeSubDomains')
        ->and($headers['Strict-Transport-Security'])->not->toContain('preload');
});

it('applies baseline only on machine doors when enabled', function (): void {
    $builder = makeSecurityBuilder();
    $request = Request::create('/api/items', 'GET');
    $response = new Response('{"ok":true}');

    $headers = $builder->forResponse($request, $response, makeSecurityPolicy());

    expect($headers)->toHaveKeys([
        'X-Content-Type-Options',
        'Referrer-Policy',
        'Permissions-Policy',
    ])
        ->and($headers)->not->toHaveKey('X-Frame-Options')
        ->and($headers)->not->toHaveKey('Content-Security-Policy');
});

it('skips machine doors entirely when baseline_on_machine_doors is false', function (): void {
    $builder = makeSecurityBuilder();
    $request = Request::create('/api/items', 'GET');

    expect($builder->forResponse($request, new Response('ok'), makeSecurityPolicy([
        'baselineOnMachineDoors' => false,
    ])))->toBe([]);
});

it('uses the config CSP override instead of the builder', function (): void {
    $builder = makeSecurityBuilder();
    $request = Request::create('/dashboard', 'GET');

    $headers = $builder->forResponse($request, new Response('ok'), makeSecurityPolicy([
        'csp' => "default-src 'none'",
    ]));

    expect($headers['Content-Security-Policy'])->toBe("default-src 'none'");
});

it('falls back to the built CSP for report-only when report_only_policy is empty', function (): void {
    $builder = makeSecurityBuilder();
    $request = Request::create('/dashboard', 'GET');

    $headers = $builder->forResponse($request, new Response('ok'), makeSecurityPolicy([
        'cspMode' => CspMode::ReportOnly,
        'cspReportOnly' => null,
    ]));

    expect($headers['Content-Security-Policy-Report-Only'])->toContain("frame-ancestors 'none'");
});

it('treats empty machine door patterns as web', function (): void {
    $builder = makeSecurityBuilder();
    $request = Request::create('/api/items', 'GET');

    $headers = $builder->forResponse($request, new Response('ok'), makeSecurityPolicy([
        'machineDoorPatterns' => [],
    ]));

    expect($headers)->toHaveKey('Content-Security-Policy')
        ->and($headers)->toHaveKey('X-Frame-Options');
});

it('does not emit HSTS when max_age is zero even on https', function (): void {
    $builder = makeSecurityBuilder();
    $secure = Request::create('https://example.test/dashboard', 'GET');
    $secure->server->set('HTTPS', 'on');

    expect($builder->forResponse($secure, new Response('ok'), makeSecurityPolicy([
        'hstsMaxAge' => 0,
    ])))->not->toHaveKey('Strict-Transport-Security');
});

it('emits HSTS when max_age is exactly one on https', function (): void {
    $builder = makeSecurityBuilder();
    $secure = Request::create('https://example.test/dashboard', 'GET');
    $secure->server->set('HTTPS', 'on');

    $headers = $builder->forResponse($secure, new Response('ok'), makeSecurityPolicy([
        'hstsMaxAge' => 1,
    ]));

    expect($headers['Strict-Transport-Security'])->toBe('max-age=1');
});

it('trims a whitespace-only report-only policy and falls back to the builder', function (): void {
    $builder = makeSecurityBuilder();
    $headers = $builder->forResponse(Request::create('/dashboard', 'GET'), new Response('ok'), makeSecurityPolicy([
        'cspMode' => CspMode::ReportOnly,
        'cspReportOnly' => '   ',
    ]));

    expect($headers['Content-Security-Policy-Report-Only'])->toContain("frame-ancestors 'none'");
});

it('falls back to the builder when the CSP override is whitespace-only', function (): void {
    $builder = makeSecurityBuilder();
    $headers = $builder->forResponse(Request::create('/dashboard', 'GET'), new Response('ok'), makeSecurityPolicy([
        'csp' => '   ',
    ]));

    expect($headers['Content-Security-Policy'])->toContain("frame-ancestors 'none'");
});

it('trims a padded CSP override before set', function (): void {
    $builder = makeSecurityBuilder();
    $headers = $builder->forResponse(Request::create('/dashboard', 'GET'), new Response('ok'), makeSecurityPolicy([
        'csp' => "  default-src 'self'  ",
    ]));

    expect($headers['Content-Security-Policy'])->toBe("default-src 'self'");
});

it('trims a padded report-only override before set', function (): void {
    $builder = makeSecurityBuilder();
    $headers = $builder->forResponse(Request::create('/dashboard', 'GET'), new Response('ok'), makeSecurityPolicy([
        'cspMode' => CspMode::ReportOnly,
        'cspReportOnly' => "  default-src 'none'  ",
    ]));

    expect($headers['Content-Security-Policy-Report-Only'])->toBe("default-src 'none'");
});

it('ignores a whitespace-only report-uri', function (): void {
    $builder = makeSecurityBuilder();
    $headers = $builder->forResponse(Request::create('/dashboard', 'GET'), new Response('ok'), makeSecurityPolicy([
        'csp' => "default-src 'self'",
        'cspReportUri' => '   ',
    ]));

    expect($headers['Content-Security-Policy'])->toBe("default-src 'self'")
        ->and($headers['Content-Security-Policy'])->not->toContain('report-uri');
});

it('trims a padded report-uri before appending', function (): void {
    $builder = makeSecurityBuilder();
    $headers = $builder->forResponse(Request::create('/dashboard', 'GET'), new Response('ok'), makeSecurityPolicy([
        'csp' => "default-src 'self'",
        'cspReportUri' => '  https://example.test/csp  ',
    ]));

    expect($headers['Content-Security-Policy'])->toBe("default-src 'self'; report-uri https://example.test/csp");
});

it('normalises a trailing semicolon before appending report-uri', function (): void {
    $builder = makeSecurityBuilder();
    $headers = $builder->forResponse(Request::create('/dashboard', 'GET'), new Response('ok'), makeSecurityPolicy([
        'csp' => "default-src 'self';",
        'cspReportUri' => 'https://example.test/csp',
    ]));

    expect($headers['Content-Security-Policy'])->toBe("default-src 'self'; report-uri https://example.test/csp");
});
