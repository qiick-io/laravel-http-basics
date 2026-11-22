<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Testing;

use PHPUnit\Framework\Assert;
use Qiick\HttpBasics\RequestId\RequestId;
use Symfony\Component\HttpFoundation\Response;

function assertRequestIdEchoed(Response $response, string $expected): void
{
    Assert::assertSame($expected, $response->headers->get(RequestId::HEADER));
}

function assertContentLanguage(Response $response, string $expected): void
{
    Assert::assertSame($expected, $response->headers->get('Content-Language'));
}

function assertVaryAcceptLanguage(Response $response): void
{
    $vary = $response->headers->get('Vary');
    Assert::assertIsString($vary);

    $parts = array_map(trim(...), explode(',', $vary));
    $found = false;
    foreach ($parts as $part) {
        if (strcasecmp($part, 'Accept-Language') === 0) {
            $found = true;
            break;
        }
    }

    Assert::assertTrue($found, 'Expected Vary to include Accept-Language.');
}

/**
 * Asserts the default web-door security headers (nosniff, XFO, Referrer,
 * Permissions, and CSP when mode is enforce).
 *
 * @param  list<string>  $expectedHeaderNames
 */
function assertSecurityHeaders(
    Response $response,
    array $expectedHeaderNames = [
        'X-Content-Type-Options',
        'X-Frame-Options',
        'Referrer-Policy',
        'Permissions-Policy',
        'Content-Security-Policy',
    ],
): void {
    foreach ($expectedHeaderNames as $headerName) {
        Assert::assertTrue(
            $response->headers->has($headerName),
            "Expected security header {$headerName} to be present.",
        );
    }

    if (in_array('X-Content-Type-Options', $expectedHeaderNames, true)) {
        Assert::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }
}

function assertIdempotentReplay(Response $first, Response $second): void
{
    Assert::assertSame($first->getStatusCode(), $second->getStatusCode());
    Assert::assertSame((string) $first->getContent(), (string) $second->getContent());
}

/**
 * @param  array<string, mixed>  $snapshot
 */
function assertNoSensitiveBucketData(array $snapshot): void
{
    $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);
    Assert::assertStringNotContainsString('HTTP_BASICS_IDEMPOTENCY_ANON_SECRET', $encoded);
    Assert::assertDoesNotMatchRegularExpression('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $encoded);
}
