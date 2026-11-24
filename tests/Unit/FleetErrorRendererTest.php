<?php

declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Qiick\HttpBasics\Errors\ErrorCode;
use Qiick\HttpBasics\Idempotency\FleetErrorRenderer;
use Qiick\HttpBasics\Idempotency\IdempotencyRejection;

it('adds documentation_url only when one is configured', function (?string $url, bool $hasLink): void {
    $payload = (new FleetErrorRenderer($url))
        ->render(new IdempotencyRejection(ErrorCode::IdempotencyKeyRequired, 400), Request::create('/api/x', 'POST'))
        ->getData(true);

    expect(array_key_exists('documentation_url', $payload))->toBe($hasLink)
        ->and($payload['documentation_url'] ?? null)->toBe($hasLink ? $url : null);
})->with([
    'configured' => ['https://docs.example.test/idempotency', true],
    'empty' => ['', false],
    'absent' => [null, false],
]);

it('falls back to the English message when the translator has no usable line', function (mixed $translated): void {
    $translator = Mockery::mock(Translator::class);
    $translator->shouldReceive('get')->andReturn($translated);

    $payload = (new FleetErrorRenderer(null, $translator))
        ->render(new IdempotencyRejection(ErrorCode::IdempotencyKeyRequired, 400), Request::create('/api/x', 'POST'))
        ->getData(true);

    expect($payload['message'])->toBe('Idempotency-Key header is required.');
})->with([
    'the key itself (no line)' => ['http-basics::idempotency.idempotency_key_required'],
    'not a string' => [['nested' => 'array']],
]);
