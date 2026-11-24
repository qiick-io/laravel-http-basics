<?php

declare(strict_types=1);

use Qiick\HttpBasics\Idempotency\ReplayHeaderSanitizer;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

it('keeps only allowlisted headers, compared without letter case', function (): void {
    $sanitizer = new ReplayHeaderSanitizer(['content-type', 'X-Trace'], []);
    $response = new Response('', 200, ['Content-Type' => 'application/json', 'x-trace' => 'abc', 'X-Other' => '1']);

    expect($sanitizer->extract($response))->toBe(['content-type' => 'application/json', 'x-trace' => 'abc']);
});

it('joins a repeated header into one value', function (): void {
    $response = new Response;
    $response->headers->set('Link', ['<a>; rel=next', '<b>; rel=prev']);

    expect((new ReplayHeaderSanitizer(['Link'], []))->extract($response))->toBe(['link' => '<a>; rel=next, <b>; rel=prev']);
});

it('drops a denied header even when it is also allowlisted', function (): void {
    $sanitizer = new ReplayHeaderSanitizer(['Content-Type', 'X-Secret'], ['x-SECRET']);
    $response = new Response('', 200, ['Content-Type' => 'text/plain', 'X-Secret' => 'key']);

    expect($sanitizer->extract($response))->toBe(['content-type' => 'text/plain']);
});

it('never replays rate-limit counters, even allowlisted ones (design I16)', function (): void {
    // They describe the original request: a replay must not repeat a stale Remaining.
    $sanitizer = new ReplayHeaderSanitizer(['Content-Type', 'X-RateLimit-Remaining', 'X-Ratelimit-Limit'], []);
    $response = new Response('', 200, ['Content-Type' => 'text/plain', 'X-RateLimit-Remaining' => '4', 'X-Ratelimit-Limit' => '5']);

    expect($sanitizer->extract($response))->toBe(['content-type' => 'text/plain']);
});

it('flags a response with a denied header or any cookie', function (Closure $build, bool $expected): void {
    $sanitizer = new ReplayHeaderSanitizer([], ['X-Api-Key']);

    expect($sanitizer->responseHasDeniedHeaders($build()))->toBe($expected);
})->with([
    'denied header, any case' => [fn () => new Response('', 200, ['x-api-KEY' => 'k']), true],
    'a cookie' => [function (): Response {
        $response = new Response;
        $response->headers->setCookie(Cookie::create('session', 'abc'));

        return $response;
    }, true],
    'a plain response' => [fn () => new Response('', 200, ['Content-Type' => 'text/plain']), false],
]);
