<?php

declare(strict_types=1);

use GuzzleHttp\Exception\TooManyRedirectsException;
use Illuminate\Http\Client\ConnectionException;
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

it('matches host and port on the allowlist', function (): void {
    $propagator = makePropagator(['api.internal.test:8443']);

    expect($propagator->headersForHost('api.internal.test', 8443))
        ->toBe([RequestId::HEADER => 'mesh-request-id'])
        ->and($propagator->headersForHost('api.internal.test', 443))->toBe([]);
});

it('checks later host and port entries when an earlier entry does not match', function (): void {
    $propagator = makePropagator(['api.internal.test:8443', 'workers.internal.test:9443']);

    expect($propagator->headersForHost('workers.internal.test', 9443))
        ->toBe([RequestId::HEADER => 'mesh-request-id'])
        ->and($propagator->headersForHost('workers.internal.test', 8443))->toBe([])
        ->and($propagator->headersForHost('workers.internal.test'))->toBe([]);
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

it('sends no header when current() is unbound', function (): void {
    $resolver = new RequestIdResolver(
        new FixedIdGenerator('mesh-request-id'),
        new AlwaysTrustedProxyDetector,
        RequestIdAcceptFrom::Never,
    );
    Context::flush();
    $propagator = new RequestIdPropagator(['*'], $resolver);

    expect($propagator->headersForHost('api.internal.test'))->toBe([]);
});

it('decides from the actual request URI not the applyTo url argument', function (): void {
    Http::fake(function (Illuminate\Http\Client\Request $request) {
        expect($request->header(RequestId::HEADER))->toBeEmpty();

        return Http::response('ok', 200);
    });

    $propagator = makePropagator(['api.internal.test']);
    // Misleading URL argument (would have allowed) — actual GET goes to evil.
    $pending = $propagator->applyTo(Http::asJson(), 'https://api.internal.test/v1');
    $pending->get('https://evil.example/steal');
});

it('lets a caller-supplied X-Request-Id win and never duplicates', function (): void {
    Http::fake(function (Illuminate\Http\Client\Request $request) {
        $values = $request->header(RequestId::HEADER);
        expect($values)->toBe(['caller-wins-id']);

        return Http::response('ok', 200);
    });

    $propagator = makePropagator(['*']);
    $pending = Http::withHeaders([RequestId::HEADER => 'caller-wins-id']);
    $propagator->applyTo($pending, 'https://api.internal.test/v1')
        ->get('https://api.internal.test/v1');
});

it('does not treat an empty caller X-Request-Id as winning', function (): void {
    Http::fake(function (Illuminate\Http\Client\Request $request) {
        expect($request->header(RequestId::HEADER))->toBe(['mesh-request-id']);

        return Http::response('ok', 200);
    });

    $propagator = makePropagator(['*']);
    // Empty header should be replaced at send time, not duplicated.
    $pending = Http::withHeaders([RequestId::HEADER => '']);
    $response = $propagator->applyTo($pending)->get('https://api.internal.test/v1');

    expect($response->status())->toBe(200);
});

it('strips the header when a redirect lands on a host outside the allowlist', function (): void {
    Http::fake([
        'https://api.internal.test/start' => Http::response('', 302, [
            'Location' => 'https://evil.example/sink',
        ]),
        'https://evil.example/sink' => function (Illuminate\Http\Client\Request $request) {
            expect($request->header(RequestId::HEADER))->toBeEmpty();

            return Http::response('sink', 200);
        },
    ]);

    $propagator = makePropagator(['api.internal.test']);
    $response = $propagator->applyTo(Http::withOptions(['allow_redirects' => true]))
        ->get('https://api.internal.test/start');

    expect($response->status())->toBe(200)->and($response->body())->toBe('sink');
});

it('re-applies the header on a same-allowlist redirect hop', function (): void {
    Http::fake([
        'https://api.internal.test/start' => Http::response('', 302, [
            'Location' => 'https://api.internal.test/next',
        ]),
        'https://api.internal.test/next' => function (Illuminate\Http\Client\Request $request) {
            expect($request->header(RequestId::HEADER))->toBe(['mesh-request-id']);

            return Http::response('next', 200);
        },
    ]);

    $propagator = makePropagator(['api.internal.test']);
    $response = $propagator->applyTo(Http::withOptions(['allow_redirects' => true]))
        ->get('https://api.internal.test/start');

    expect($response->body())->toBe('next');
});

it('does not leak via baseUrl when the relative path resolves off-allowlist', function (): void {
    Http::fake(function (Illuminate\Http\Client\Request $request) {
        expect($request->url())->toStartWith('https://evil.example/')
            ->and($request->header(RequestId::HEADER))->toBeEmpty();

        return Http::response('ok', 200);
    });

    $propagator = makePropagator(['api.internal.test']);
    $propagator->applyTo(Http::baseUrl('https://evil.example'))
        ->get('/steal');
});

it('respects allow_redirects false and does not follow Location', function (): void {
    Http::fake([
        'https://api.internal.test/start' => Http::response('', 302, [
            'Location' => 'https://evil.example/sink',
        ]),
        'https://evil.example/sink' => Http::response('should-not-hit', 200),
    ]);

    $propagator = makePropagator(['api.internal.test']);
    $response = $propagator->applyTo(Http::withOptions(['allow_redirects' => false]))
        ->get('https://api.internal.test/start');

    expect($response->status())->toBe(302);
});

it('uses default https port 443 when the URI omits an explicit port', function (): void {
    $propagator = makePropagator(['api.internal.test:443']);

    expect($propagator->headersForHost('api.internal.test', 443))
        ->toBe([RequestId::HEADER => 'mesh-request-id']);
});

it('applies the id on an https URI with no explicit port via applyTo', function (): void {
    Http::fake(function (Illuminate\Http\Client\Request $request) {
        expect($request->header(RequestId::HEADER))->toBe(['mesh-request-id']);

        return Http::response('ok', 200);
    });

    $propagator = makePropagator(['api.internal.test:443']);
    $propagator->applyTo(Http::asJson())->get('https://api.internal.test/v1');
});

it('applies the id on an http URI with default port 80 via applyTo', function (): void {
    Http::fake(function (Illuminate\Http\Client\Request $request) {
        expect($request->header(RequestId::HEADER))->toBe(['mesh-request-id']);

        return Http::response('ok', 200);
    });

    $propagator = makePropagator(['api.internal.test:80']);
    $propagator->applyTo(Http::asJson())->get('http://api.internal.test/v1');
});

it('changes POST to GET when following a 302 redirect', function (): void {
    Http::fake(function (Illuminate\Http\Client\Request $request) {
        if ($request->url() === 'https://api.internal.test/start') {
            return Http::response('', 302, ['Location' => 'https://api.internal.test/next']);
        }

        expect($request->method())->toBe('GET');

        return Http::response('next', 200);
    });

    $propagator = makePropagator(['api.internal.test']);
    $response = $propagator->applyTo(Http::withOptions(['allow_redirects' => true]))
        ->post('https://api.internal.test/start');

    expect($response->body())->toBe('next');
});

it('stops after Guzzle\'s default redirect limit of 5', function (): void {
    $hop = 0;
    Http::fake(function () use (&$hop) {
        $hop++;

        return Http::response('', 302, [
            'Location' => 'https://api.internal.test/hop-'.$hop,
        ]);
    });

    $propagator = makePropagator(['api.internal.test']);

    try {
        $propagator->applyTo(Http::withOptions(['allow_redirects' => true]))
            ->get('https://api.internal.test/start');
        expect(false)->toBeTrue(); // unreachable: must throw
    } catch (ConnectionException $exception) {
        expect($exception->getPrevious())->toBeInstanceOf(TooManyRedirectsException::class)
            ->and($exception->getMessage())->toContain('5 redirects');
    }

    // Initial request + 5 followed hops = 6 attempts before Guzzle refuses the 6th redirect.
    expect($hop)->toBe(6);
});

it('re-decides the allowlist on each Guzzle-followed hop', function (): void {
    Http::fake([
        'https://api.internal.test/start' => Http::response('', 302, [
            'Location' => 'https://other.internal.test/next',
        ]),
        'https://other.internal.test/next' => function (Illuminate\Http\Client\Request $request) {
            expect($request->header(RequestId::HEADER))->toBe(['mesh-request-id']);

            return Http::response('next', 200);
        },
    ]);

    $propagator = makePropagator(['api.internal.test', 'other.internal.test']);
    $response = $propagator->applyTo(Http::withOptions(['allow_redirects' => true]))
        ->get('https://api.internal.test/start');

    expect($response->body())->toBe('next');
});

it('strips a carried X-Request-Id on a redirect hop to a host outside the allowlist', function (): void {
    Http::fake();
    $propagator = makePropagator(['api.internal.test']);

    // Guzzle sets __redirect_count before every followed hop; the caller's header rides along.
    $propagator->applyTo(Http::withOptions(['__redirect_count' => 1])->withHeaders(['x-request-id' => 'caller-id']))
        ->get('https://evil.example/collect');

    Http::assertSent(static fn (Illuminate\Http\Client\Request $request): bool => $request->url() === 'https://evil.example/collect'
        && ! $request->hasHeader(RequestId::HEADER));
});
