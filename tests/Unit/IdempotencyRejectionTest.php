<?php

declare(strict_types=1);

use Qiick\HttpBasics\Idempotency\FleetErrorRenderer;
use Qiick\HttpBasics\Idempotency\IdempotencyErrorCode;
use Qiick\HttpBasics\Idempotency\IdempotencyRejection;

it('exposes stable error codes and renders fleet json with retry after', function (): void {
    $rejection = new IdempotencyRejection(
        IdempotencyErrorCode::InProgress,
        409,
        ['Retry-After' => '30'],
    );

    expect($rejection->errorCode())->toBe('idempotency_in_progress');

    $response = (new FleetErrorRenderer)->render($rejection);
    expect($response->getStatusCode())->toBe(409)
        ->and($response->headers->get('Retry-After'))->toBe('30')
        ->and($response->getData(true)['error'])->toBe('idempotency_in_progress');
});
