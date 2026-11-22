<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Qiick\HttpBasics\Idempotency\AnonymousBucketResolver;

use function Qiick\HttpBasics\Testing\assertNoSensitiveBucketData;

it('derives stable anon buckets without embedding the secret', function (): void {
    $resolver = new AnonymousBucketResolver(
        str_repeat('s', 32),
        ['ip', 'user_agent', 'route'],
    );

    $request = Request::create('/api/public', 'POST', server: [
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_USER_AGENT' => 'test-agent',
    ]);

    $bucket = $resolver->bucket($request);

    expect($bucket)->toStartWith('anon:')
        ->and(strlen($bucket))->toBeGreaterThan(10);

    assertNoSensitiveBucketData(['bucket' => $bucket]);
});

it('does not trust request bucket inputs', function (): void {
    $resolver = new AnonymousBucketResolver(str_repeat('s', 32), ['ip', 'route']);

    $request = Request::create('/api/public?bucket=evil', 'POST', ['bucket' => 'evil'], server: [
        'REMOTE_ADDR' => '203.0.113.10',
    ]);

    $bucket = $resolver->bucket($request);

    expect($bucket)->not->toContain('evil');
});

it('includes the client ip in the hmac material', function (): void {
    $secret = str_repeat('s', 32);
    $resolver = new AnonymousBucketResolver($secret, ['ip']);

    $withIp = Request::create('/api/public', 'POST', server: [
        'REMOTE_ADDR' => '203.0.113.10',
    ]);
    $withoutIp = Request::create('/api/public', 'POST');
    $withoutIp->server->remove('REMOTE_ADDR');

    expect($resolver->bucket($withIp))->toBe('anon:'.hash_hmac('sha256', '203.0.113.10', $secret))
        ->and($resolver->bucket($withoutIp))->toBe('anon:'.hash_hmac('sha256', '', $secret));
});

it('includes the user agent in the hmac material', function (): void {
    $secret = str_repeat('s', 32);
    $resolver = new AnonymousBucketResolver($secret, ['user_agent']);

    $request = Request::create('/api/public', 'POST', server: ['HTTP_USER_AGENT' => 'curl/8.5']);

    expect($resolver->bucket($request))->toBe('anon:'.hash_hmac('sha256', 'curl/8.5', $secret));
});

it('casts a missing user agent to an empty string', function (): void {
    $secret = str_repeat('s', 32);
    $resolver = new AnonymousBucketResolver($secret, ['user_agent']);

    $request = Request::create('/api/public', 'POST');
    $request->headers->remove('User-Agent');

    expect($resolver->bucket($request))->toBe('anon:'.hash_hmac('sha256', '', $secret));
});

it('prefers the named route over uri and path', function (): void {
    $secret = str_repeat('s', 32);
    $resolver = new AnonymousBucketResolver($secret, ['route']);

    $named = Request::create('/api/widgets', 'POST');
    $namedRoute = new Route(['POST'], 'api/widgets', static fn () => null);
    $namedRoute->name('widgets.store');
    $named->setRouteResolver(static fn () => $namedRoute);

    expect($resolver->bucket($named))->toBe('anon:'.hash_hmac('sha256', 'widgets.store', $secret));
});

it('falls back from route uri to path when the route is unnamed', function (): void {
    $secret = str_repeat('s', 32);
    $resolver = new AnonymousBucketResolver($secret, ['route']);

    $withUri = Request::create('/api/widgets', 'POST');
    $uriRoute = new Route(['POST'], 'api/widgets/{id}', static fn () => null);
    $withUri->setRouteResolver(static fn () => $uriRoute);

    $pathOnly = Request::create('/api/widgets/1', 'POST');
    $pathOnly->setRouteResolver(static fn () => null);

    expect($resolver->bucket($withUri))->toBe('anon:'.hash_hmac('sha256', 'api/widgets/{id}', $secret))
        ->and($resolver->bucket($pathOnly))->toBe('anon:'.hash_hmac('sha256', 'api/widgets/1', $secret));
});

it('treats unknown components as empty parts', function (): void {
    /** @var list<'ip'|'user_agent'|'route'> $components */
    $components = ['ip', 'unknown'];
    $secret = str_repeat('s', 32);
    $resolver = new AnonymousBucketResolver($secret, $components);

    $request = Request::create('/api/public', 'POST', server: [
        'REMOTE_ADDR' => '203.0.113.10',
    ]);

    expect($resolver->bucket($request))->toBe(
        'anon:'.hash_hmac('sha256', "203.0.113.10\n", $secret),
    );
});
