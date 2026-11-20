<?php

declare(strict_types=1);

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Qiick\HttpBasics\RequestId\RequestId;
use Qiick\HttpBasics\RequestId\RequestIdAcceptFrom;
use Qiick\HttpBasics\RequestId\RequestIdPropagator;
use Qiick\HttpBasics\RequestId\RequestIdResolver;
use Qiick\HttpBasics\Testing\AlwaysTrustedProxyDetector;
use Qiick\HttpBasics\Testing\FixedIdGenerator;

function makePropagator(array $allowedHosts): RequestIdPropagator
{
    $resolver = new RequestIdResolver(
        new FixedIdGenerator('mesh-request-id'),
        new AlwaysTrustedProxyDetector,
        RequestIdAcceptFrom::Never,
        ['http_basics.request_id'],
    );

    Context::flush();
    $resolver->bind(Request::create('/', 'GET'));

    return new RequestIdPropagator($allowedHosts, $resolver);
}

beforeEach(function (): void {
    Context::flush();
});

it('does not propagate when the allowlist is empty', function (): void {
    $propagator = makePropagator([]);

    expect($propagator->headersForHost('api.internal.test'))->toBe([]);
});

it('propagates to an allowlisted host', function (): void {
    $propagator = makePropagator(['api.internal.test']);

    expect($propagator->headersForHost('api.internal.test'))
        ->toBe([RequestId::HEADER => 'mesh-request-id']);
});

it('does not leak to a host outside the allowlist', function (): void {
    $propagator = makePropagator(['api.internal.test']);

    expect($propagator->headersForHost('evil.example'))->toBe([]);
});

it('propagates to every host when allowlist is *', function (): void {
    $propagator = makePropagator(['*']);

    expect($propagator->headersForHost('anywhere.example'))
        ->toBe([RequestId::HEADER => 'mesh-request-id']);
});

it('lets a caller-supplied X-Request-Id win on the PendingRequest', function (): void {
    Http::fake();

    $propagator = makePropagator(['*']);
    $pending = Http::withHeaders([RequestId::HEADER => 'caller-wins-id']);

    $applied = $propagator->applyTo($pending, 'https://api.internal.test/v1');

    $headers = $applied->getOptions()['headers'] ?? [];
    $found = null;
    foreach ($headers as $name => $value) {
        if (is_string($name) && strcasecmp($name, RequestId::HEADER) === 0) {
            $found = $value;
        }
    }

    expect($found)->toBe('caller-wins-id');
});

it('does not treat an empty caller X-Request-Id as winning', function (): void {
    Http::fake();

    $propagator = makePropagator(['*']);
    $pending = Http::withHeaders([RequestId::HEADER => '']);

    $applied = $propagator->applyTo($pending, 'https://api.internal.test/v1');

    $headers = $applied->getOptions()['headers'] ?? [];
    $found = null;
    foreach ($headers as $name => $value) {
        if (is_string($name) && strcasecmp($name, RequestId::HEADER) === 0) {
            $found = $value;
        }
    }

    expect($found)->toBe('mesh-request-id');
});

it('applies the bound id when the PendingRequest has no caller header', function (): void {
    Http::fake();

    $propagator = makePropagator(['api.internal.test']);
    $pending = new PendingRequest(Http::getFacadeRoot());

    $applied = $propagator->applyTo($pending, 'https://api.internal.test/v1/items');

    $headers = $applied->getOptions()['headers'] ?? [];
    $found = null;
    foreach ($headers as $name => $value) {
        if (is_string($name) && strcasecmp($name, RequestId::HEADER) === 0) {
            $found = $value;
        }
    }

    expect($found)->toBe('mesh-request-id');
});

it('does not propagate when the URL has no usable host', function (string $url): void {
    Http::fake();

    $propagator = makePropagator(['*']);
    $pending = new PendingRequest(Http::getFacadeRoot());

    $applied = $propagator->applyTo($pending, $url);

    $headers = $applied->getOptions()['headers'] ?? [];
    expect($headers)->not->toHaveKey(RequestId::HEADER);
})->with([
    'relative path' => ['/local/only'],
    'scheme only' => ['https://'],
]);
