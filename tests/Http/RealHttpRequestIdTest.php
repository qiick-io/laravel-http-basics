<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Qiick\HttpBasics\RequestId\RequestId;
use Qiick\HttpBasics\RequestId\RequestIdAcceptFrom;
use Qiick\HttpBasics\RequestId\RequestIdPropagator;
use Qiick\HttpBasics\RequestId\RequestIdResolver;
use Qiick\HttpBasics\Testing\AlwaysTrustedProxyDetector;
use Qiick\HttpBasics\Testing\FixedIdGenerator;

require_once __DIR__.'/Support/php-built-in-server.php';

/**
 * Real HTTP against php -S (fleet rule). Group http-server is required by CI.
 */
$httpState = new class
{
    public int $primaryPort = 0;

    public int $redirectPort = 0;

    /** @var resource|null */
    public $primaryProc = null;

    /** @var resource|null */
    public $redirectProc = null;
};

beforeAll(function () use ($httpState): void {
    $server = __DIR__.'/fixtures/server.php';
    $root = dirname(__DIR__, 1);
    $httpState->primaryPort = 8765 + random_int(0, 200);
    $httpState->redirectPort = $httpState->primaryPort + 1;

    $httpState->primaryProc = startPhpBuiltInServer(
        $httpState->primaryPort,
        $server,
        $root,
        '127.0.0.1',
    );
    $httpState->redirectProc = startPhpBuiltInServer(
        $httpState->redirectPort,
        $server,
        $root,
        '127.0.0.1',
    );
});

afterAll(function () use ($httpState): void {
    foreach ([$httpState->primaryProc, $httpState->redirectProc] as $proc) {
        if (is_resource($proc)) {
            stopPhpBuiltInServer($proc);
        }
    }
});

beforeEach(function (): void {
    // These integration tests deliberately reach the local php -S fixtures.
    Http::allowStrayRequests();
});

it('echoes a generated X-Request-Id over real HTTP', function () use ($httpState): void {
    $result = realHttpRequest($httpState->primaryPort, '/ping');

    expect($result['status'])->toBe(200)
        ->and($result['headers'])->toHaveKey(strtolower(RequestId::HEADER))
        ->and($result['headers'][strtolower(RequestId::HEADER)])->toBe('http-server-generated');
})->group('http-server');

it('rejects log-injection inbound ids over real HTTP', function () use ($httpState): void {
    $result = realHttpRequest($httpState->primaryPort, '/ping', headers: [
        RequestId::HEADER => 'bad id value',
    ]);

    expect($result['headers'][strtolower(RequestId::HEADER)])->toBe('http-server-generated');
})->group('http-server');

it('keeps a trusted proxy id over real HTTP', function () use ($httpState): void {
    $result = realHttpRequest($httpState->primaryPort, '/ping', headers: [
        RequestId::HEADER => 'trusted-real-01',
    ]);

    expect($result['headers'][strtolower(RequestId::HEADER)])->toBe('trusted-real-01');
})->group('http-server');

it('carries X-Request-Id on 404 and CORS preflight over real HTTP', function () use ($httpState): void {
    $missing = realHttpRequest($httpState->primaryPort, '/missing-route');
    expect($missing['status'])->toBe(404)
        ->and($missing['headers'])->toHaveKey(strtolower(RequestId::HEADER));

    $cors = realHttpRequest($httpState->primaryPort, '/cors', 'OPTIONS');
    expect($cors['status'])->toBe(204)
        ->and($cors['headers'])->toHaveKey(strtolower(RequestId::HEADER));
})->group('http-server');

it('strips X-Request-Id on a cross-port redirect followed by the propagator', function () use ($httpState): void {
    Context::flush();
    $resolver = new RequestIdResolver(
        new FixedIdGenerator('mesh-request-id'),
        new AlwaysTrustedProxyDetector,
        RequestIdAcceptFrom::Never,
    );
    $resolver->bind(Request::create('/', 'GET'));

    $primary = "http://127.0.0.1:{$httpState->primaryPort}";
    $redirect = "http://127.0.0.1:{$httpState->redirectPort}/ping?dump_headers=1";

    // Allow first hop only — second port must not receive the header.
    $propagator = new RequestIdPropagator(
        ["127.0.0.1:{$httpState->primaryPort}"],
        $resolver,
    );

    $pending = $propagator->applyTo(Http::withOptions(['allow_redirects' => true]));
    $response = $pending->get($primary.'/ping?redirect_to='.urlencode($redirect));

    expect($response->status())->toBe(200);
    $payload = $response->json();
    expect($response->body())->toContain('raw_inbound_x_request_id')
        ->and($payload)->toBeArray()
        ->and(array_key_exists('raw_inbound_x_request_id', $payload))->toBeTrue()
        ->and($payload['raw_inbound_x_request_id'])->toBeNull();
})->group('http-server');

it('does not forward Authorization or Cookie on a cross-host redirect hop', function () use ($httpState): void {
    Context::flush();
    $resolver = new RequestIdResolver(
        new FixedIdGenerator('mesh-request-id'),
        new AlwaysTrustedProxyDetector,
        RequestIdAcceptFrom::Never,
    );
    $resolver->bind(Request::create('/', 'GET'));

    $primary = "http://127.0.0.1:{$httpState->primaryPort}";
    $redirect = "http://127.0.0.1:{$httpState->redirectPort}/ping?dump_headers=1";

    $propagator = new RequestIdPropagator(['*'], $resolver);
    $response = $propagator->applyTo(Http::withOptions(['allow_redirects' => true])
        ->withHeaders([
            'Authorization' => 'Bearer should-not-leak',
            'Cookie' => 'session=should-not-leak',
        ]))
        ->get($primary.'/ping?redirect_to='.urlencode($redirect));

    expect($response->status())->toBe(200);
    $payload = $response->json();
    expect($payload)->toBeArray()
        ->and(array_key_exists('raw_authorization', $payload))->toBeTrue()
        ->and($payload['raw_authorization'])->toBeNull()
        ->and(array_key_exists('raw_cookie', $payload))->toBeTrue()
        ->and($payload['raw_cookie'])->toBeNull();
})->group('http-server');

it('follows a 303 like Guzzle: POST becomes GET without a body', function () use ($httpState): void {
    Context::flush();
    $resolver = new RequestIdResolver(
        new FixedIdGenerator('mesh-request-id'),
        new AlwaysTrustedProxyDetector,
        RequestIdAcceptFrom::Never,
    );
    $resolver->bind(Request::create('/', 'GET'));

    $primary = "http://127.0.0.1:{$httpState->primaryPort}";
    $redirect = "http://127.0.0.1:{$httpState->redirectPort}/ping?dump_headers=1";

    $propagator = new RequestIdPropagator(['*'], $resolver);
    $response = $propagator->applyTo(Http::withOptions(['allow_redirects' => true]))
        ->withBody('posted-body', 'text/plain')
        ->post($primary.'/ping?redirect_to='.urlencode($redirect).'&redirect_status=303');

    expect($response->status())->toBe(200);
    $payload = $response->json();
    expect($payload)->toBeArray()
        ->and($payload['raw_method'] ?? null)->toBe('GET')
        ->and(in_array($payload['raw_content_length'] ?? null, [null, '', '0'], true))->toBeTrue();
})->group('http-server');
