<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Qiick\HttpBasics\RequestId\RequestId;
use Qiick\HttpBasics\RequestId\RequestIdAcceptFrom;
use Qiick\HttpBasics\RequestId\RequestIdResolver;
use Qiick\HttpBasics\RequestId\RequestIdSource;
use Qiick\HttpBasics\Testing\AlwaysTrustedProxyDetector;
use Qiick\HttpBasics\Testing\FixedIdGenerator;
use Qiick\HttpBasics\Testing\NeverTrustedProxyDetector;
use Symfony\Component\HttpFoundation\Response;

function makeResolver(
    RequestIdAcceptFrom $acceptFrom = RequestIdAcceptFrom::TrustedProxy,
    bool $trusted = true,
): RequestIdResolver {
    return new RequestIdResolver(
        new FixedIdGenerator('generated-fixed-id'),
        $trusted ? new AlwaysTrustedProxyDetector : new NeverTrustedProxyDetector,
        $acceptFrom,
        ['http_basics.request_id'],
        'http_basics.request_id_source',
    );
}

beforeEach(function (): void {
    Context::flush();
});

it('keeps a valid inbound id under trusted_proxy when the hop is trusted', function (): void {
    $resolver = makeResolver(RequestIdAcceptFrom::TrustedProxy, trusted: true);
    $request = Request::create('/api/ping', 'GET');
    $request->headers->set(RequestId::HEADER, 'from-proxy-01');

    $id = $resolver->bind($request);

    expect($id->value)->toBe('from-proxy-01')
        ->and($id->source)->toBe(RequestIdSource::TrustedProxy)
        ->and($request->headers->get(RequestId::HEADER))->toBe('from-proxy-01')
        ->and(Context::getHidden('http_basics.request_id'))->toBe('from-proxy-01')
        ->and(Context::getHidden('http_basics.request_id_source'))->toBe('trusted_proxy');
});

it('generates when trusted_proxy and the hop is untrusted', function (): void {
    $resolver = makeResolver(RequestIdAcceptFrom::TrustedProxy, trusted: false);
    $request = Request::create('/', 'GET');
    $request->headers->set(RequestId::HEADER, 'spoofed-client');

    $id = $resolver->bind($request);

    expect($id->value)->toBe('generated-fixed-id')
        ->and($id->source)->toBe(RequestIdSource::Generated)
        // Must overwrite the spoofed inbound on the Request itself (H2).
        ->and($request->headers->get(RequestId::HEADER))->toBe('generated-fixed-id');
});

it('always generates when accept_from is never', function (): void {
    $resolver = makeResolver(RequestIdAcceptFrom::Never, trusted: true);
    $request = Request::create('/', 'GET');
    $request->headers->set(RequestId::HEADER, 'would-be-kept');

    $id = $resolver->bind($request);

    expect($id->value)->toBe('generated-fixed-id')
        ->and($id->source)->toBe(RequestIdSource::Generated);
});

it('keeps a valid id as client when accept_from is any', function (): void {
    $resolver = makeResolver(RequestIdAcceptFrom::Any, trusted: false);
    $request = Request::create('/', 'GET');
    $request->headers->set(RequestId::HEADER, 'partner-api-1');

    $id = $resolver->bind($request);

    expect($id->value)->toBe('partner-api-1')
        ->and($id->source)->toBe(RequestIdSource::Client);
});

it('generates on invalid or duplicate inbound values', function (array $values): void {
    $resolver = makeResolver();
    $request = Request::create('/', 'GET');
    $request->headers->remove(RequestId::HEADER);
    foreach ($values as $value) {
        $request->headers->set(RequestId::HEADER, $value, false);
    }

    $id = $resolver->bind($request);

    expect($id->value)->toBe('generated-fixed-id')
        ->and($id->source)->toBe(RequestIdSource::Generated);
})->with([
    'invalid' => [['bad id']],
    'duplicates' => [['dup-value-1', 'dup-value-2']],
    'missing' => [[]],
]);

it('stores provenance only in hidden Context, never visible Context', function (): void {
    $resolver = makeResolver();
    $request = Request::create('/', 'GET');

    $resolver->bind($request);

    expect(Context::getHidden('http_basics.request_id'))->toBe('generated-fixed-id')
        ->and(Context::getHidden('http_basics.request_id_source'))->toBe('generated')
        ->and(Context::has('http_basics.request_id'))->toBeFalse()
        ->and(Context::all())->not->toHaveKey('http_basics.request_id');
});

it('echoes the bound id onto the response', function (): void {
    $resolver = makeResolver();
    $request = Request::create('/', 'GET');
    $id = $resolver->bind($request);
    $response = new Response('ok');

    $resolver->echoInto($id, $response);

    expect($response->headers->get(RequestId::HEADER))->toBe('generated-fixed-id');
});

it('exposes current() from hidden Context after bind', function (): void {
    $resolver = makeResolver(RequestIdAcceptFrom::Any);
    $request = Request::create('/', 'GET');
    $request->headers->set(RequestId::HEADER, 'current-id-01');
    $resolver->bind($request);

    $current = $resolver->current();

    expect($current->value)->toBe('current-id-01')
        ->and($current->source)->toBe(RequestIdSource::Client);
});

it('current() prefers the first configured hidden key that is set', function (): void {
    $resolver = new RequestIdResolver(
        new FixedIdGenerator('generated-fixed-id'),
        new AlwaysTrustedProxyDetector,
        RequestIdAcceptFrom::Never,
        ['http_basics.request_id', 'structured_log.request_id'],
        'http_basics.request_id_source',
    );

    Context::flush();
    Context::addHidden('http_basics.request_id', 'first-key-wins');
    Context::addHidden('structured_log.request_id', 'second-key-value');
    Context::addHidden('http_basics.request_id_source', RequestIdSource::Generated->value);

    expect($resolver->current()->value)->toBe('first-key-wins');
});

it('current() rejects a non-string or empty hidden value', function (mixed $bad): void {
    $resolver = makeResolver();
    Context::flush();
    Context::addHidden('http_basics.request_id', $bad);

    $resolver->current();
})->with([
    'empty string' => [''],
    'integer' => [123],
])->throws(RuntimeException::class);
