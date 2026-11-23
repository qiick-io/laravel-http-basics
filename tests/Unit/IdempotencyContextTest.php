<?php

declare(strict_types=1);

use Qiick\HttpBasics\Idempotency\Fingerprinter;
use Qiick\HttpBasics\Idempotency\IdempotencyContext;
use Qiick\HttpBasics\Idempotency\IdempotencyKeyFormat;

it('defaults to the current fingerprint version and no optional facts', function (): void {
    $context = new IdempotencyContext(IdempotencyKeyFormat::SfString);

    expect($context->keyFormat)->toBe(IdempotencyKeyFormat::SfString)
        ->and($context->fingerprintVersion)->toBe(1)
        ->and($context->requestId)->toBeNull()
        ->and($context->traceId)->toBeNull()
        ->and($context->credentialPublicId)->toBeNull()
        ->and($context->metadata)->toBe([]);
});

it('accepts values at the edge of what the columns hold', function (): void {
    $context = new IdempotencyContext(
        IdempotencyKeyFormat::Uuid,
        fingerprintVersion: 1,
        requestId: str_repeat('r', 128),
        traceId: '4bf92f3577b34da6a3ce929d0e0e4736',
        credentialPublicId: '019a5f6e-8c1b-7d2e-9f3a-4b5c6d7e8f90',
        metadata: ['request.content_digest' => 'sha-256=:abc=:'],
    );

    expect($context->requestId)->toHaveLength(128)
        ->and($context->traceId)->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($context->credentialPublicId)->toBe('019a5f6e-8c1b-7d2e-9f3a-4b5c6d7e8f90')
        ->and($context->metadata)->toBe(['request.content_digest' => 'sha-256=:abc=:']);
});

it('refuses context the record could not store faithfully', function (array $arguments, string $message): void {
    expect(fn () => new IdempotencyContext(IdempotencyKeyFormat::Uuid, ...$arguments))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'fingerprint version zero' => [['fingerprintVersion' => 0], 'fingerprint version'],
    'request id over 128 characters' => [['requestId' => str_repeat('r', 129)], 'request id'],
    'trace id in upper case' => [['traceId' => '4BF92F3577B34DA6A3CE929D0E0E4736'], 'trace id'],
    'trace id too short' => [['traceId' => '4bf92f3577b34da6a3ce929d0e0e473'], 'trace id'],
    'all-zero trace id' => [['traceId' => str_repeat('0', 32)], 'trace id'],
    'credential id that is not a UUID' => [['credentialPublicId' => 'key_123'], 'credential public id'],
    'metadata JSON cannot encode' => [['metadata' => ['ratio' => INF]], 'metadata'],
]);

it('defaults to the fingerprint version the Fingerprinter produces', function (): void {
    expect((new IdempotencyContext(IdempotencyKeyFormat::Uuid))->fingerprintVersion)
        ->toBe(Fingerprinter::VERSION);
});

it('accepts namespaced metadata of plain values up to 4 KiB', function (): void {
    $metadata = ['upload' => ['size' => 12, 'ok' => true, 'tags' => ['a', null]], 'request.content_digest' => str_repeat('x', 4000)];

    expect((new IdempotencyContext(IdempotencyKeyFormat::Uuid, metadata: $metadata))->metadata)->toBe($metadata)
        ->and(strlen((string) json_encode(['k' => str_repeat('x', 4096 - 8)])))->toBe(4096)
        ->and((new IdempotencyContext(IdempotencyKeyFormat::Uuid, metadata: ['k' => str_repeat('x', 4096 - 8)]))->metadata)->toHaveKey('k');
});

it('refuses metadata the audit row should not hold', function (array $metadata): void {
    expect(fn () => new IdempotencyContext(IdempotencyKeyFormat::Uuid, metadata: $metadata))
        ->toThrow(InvalidArgumentException::class, 'metadata');
})->with([
    'over 4 KiB' => [['k' => str_repeat('x', 4096 - 7)]],
    'a list instead of keys' => [['a', 'b']],
    'a key that is not namespaced' => [['Request Digest' => 'x']],
    'an empty namespace segment' => [['request..digest' => 'x']],
    'an object' => [['model' => new ArrayObject(['secret' => 'x'])]],
    'an object deeper down' => [['upload' => ['file' => new SplFileInfo('/tmp')]]],
]);
