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
