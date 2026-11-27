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
        ->and(Context::get(RequestIdResolver::VISIBLE_CONTEXT_KEY))->toBe('from-proxy-01')
        ->and(Context::getHidden('http_basics.request_id_source'))->toBe('trusted_proxy');
});

it('generates when trusted_proxy and the hop is untrusted', function (): void {
    $resolver = makeResolver(RequestIdAcceptFrom::TrustedProxy, trusted: false);
    $request = Request::create('/', 'GET');
    $request->headers->set(RequestId::HEADER, 'spoofed-client');

    $id = $resolver->bind($request);

    expect($id->value)->toBe('generated-fixed-id')
        ->and($id->source)->toBe(RequestIdSource::Generated)
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
    'punctuation only' => [['--------']],
]);

it('stores the id in visible Context for logging and jobs', function (): void {
    $resolver = makeResolver();
    $request = Request::create('/', 'GET');

    $resolver->bind($request);

    expect(Context::get(RequestIdResolver::VISIBLE_CONTEXT_KEY))->toBe('generated-fixed-id')
        ->and(Context::getHidden('http_basics.request_id'))->toBe('generated-fixed-id')
        ->and(Context::getHidden('http_basics.request_id_source'))->toBe('generated')
        ->and(Context::hasHidden('http_basics.request_id_source'))->toBeTrue();
});

it('echoes the bound id onto the response', function (): void {
    $resolver = makeResolver();
    $request = Request::create('/', 'GET');
    $id = $resolver->bind($request);
    $response = new Response('ok');

    $resolver->echoInto($id, $response);

    expect($response->headers->get(RequestId::HEADER))->toBe('generated-fixed-id');
});

it('exposes current() from visible Context after bind', function (): void {
    $resolver = makeResolver(RequestIdAcceptFrom::Any);
    $request = Request::create('/', 'GET');
    $request->headers->set(RequestId::HEADER, 'current-id-01');
    $resolver->bind($request);

    $current = $resolver->current();

    expect($current)->not->toBeNull()
        ->and($current?->value)->toBe('current-id-01')
        ->and($current?->source)->toBe(RequestIdSource::Client);
});

it('returns null from current() when nothing is bound instead of throwing', function (): void {
    $resolver = makeResolver();

    expect($resolver->current())->toBeNull();
});

it('falls back to hidden context keys in current() when visible is absent', function (): void {
    $resolver = makeResolver();
    Context::addHidden('http_basics.request_id', 'hidden-only-id');
    Context::addHidden('http_basics.request_id_source', 'generated');

    $current = $resolver->current();

    expect($current)->not->toBeNull()
        ->and($current?->value)->toBe('hidden-only-id');
});

it('prefers a non-empty visible request id and ignores empty context values', function (): void {
    $resolver = makeResolver();
    Context::addHidden('http_basics.request_id', 'legacy-request-id');
    Context::add(RequestIdResolver::VISIBLE_CONTEXT_KEY, 'visible-request-id');

    expect($resolver->current()?->value)->toBe('visible-request-id');

    Context::add(RequestIdResolver::VISIBLE_CONTEXT_KEY, '');

    expect($resolver->current()?->value)->toBe('legacy-request-id');

    Context::addHidden('http_basics.request_id', '');

    expect($resolver->current())->toBeNull();
});

it('uses the first matching hidden key when several are configured', function (): void {
    $resolver = new RequestIdResolver(
        new FixedIdGenerator('generated-fixed-id'),
        new AlwaysTrustedProxyDetector,
        RequestIdAcceptFrom::Never,
        ['http_basics.request_id', 'legacy.request_id'],
    );

    Context::addHidden('http_basics.request_id', 'first-hidden-id');
    Context::addHidden('legacy.request_id', 'second-hidden-id');
    Context::addHidden('http_basics.request_id_source', 'generated');

    // break keeps the first hit; continue would overwrite with the second.
    expect($resolver->current()?->value)->toBe('first-hidden-id');
});
