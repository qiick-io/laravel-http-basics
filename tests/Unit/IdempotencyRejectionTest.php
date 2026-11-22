<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Qiick\HttpBasics\Contracts\ErrorRenderer;
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

    $response = (new FleetErrorRenderer)->render($rejection, Request::create('/api/widgets', 'POST'));
    expect($response->getStatusCode())->toBe(409)
        ->and($response->headers->get('Retry-After'))->toBe('30')
        ->and($response->getData(true)['error'])->toBe('idempotency_in_progress')
        ->and($response->getData(true)['message'])->toBeString()->not->toBe('');
});

it('translates refusal messages while keeping the error code stable', function (): void {
    $rejection = new IdempotencyRejection(IdempotencyErrorCode::KeyRequired, 400);
    $request = Request::create('/api/widgets', 'POST');
    $request->setLocale('it');

    $response = app(ErrorRenderer::class)->render($rejection, $request);

    expect($response->getData(true)['error'])->toBe('idempotency_key_required')
        ->and($response->getData(true)['message'])->toBe('L\'header Idempotency-Key è obbligatorio.');
});
