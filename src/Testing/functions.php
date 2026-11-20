<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Testing;

use Qiick\HttpBasics\RequestId\RequestId;
use Symfony\Component\HttpFoundation\Response;

function assertRequestIdEchoed(Response $response, string $expected): void
{
    $actual = $response->headers->get(RequestId::HEADER);

    expect($actual)->toBe($expected);
}
