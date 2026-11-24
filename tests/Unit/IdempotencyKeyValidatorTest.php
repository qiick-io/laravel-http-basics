<?php

declare(strict_types=1);

use Qiick\HttpBasics\Idempotency\IdempotencyKeyFormat;
use Qiick\HttpBasics\Idempotency\IdempotencyKeyValidator;

it('accepts uuid keys and rejects malformed values', function (string $key, bool $expected): void {
    $validator = new IdempotencyKeyValidator(IdempotencyKeyFormat::Uuid);

    expect($validator->isValid($key))->toBe($expected);
})->with([
    ['550e8400-e29b-41d4-a716-446655440000', true],
    ['not-a-uuid', false],
    ['', false],
    [str_repeat('a', 129), false],
]);

it('caps keys at 128 bytes by default', function (): void {
    $validator = new IdempotencyKeyValidator(IdempotencyKeyFormat::SfString);

    expect($validator->isValid(str_repeat('k', 128)))->toBeTrue()
        ->and($validator->isValid(str_repeat('k', 129)))->toBeFalse();
});

it('honours a custom maximum length to the byte', function (): void {
    $validator = new IdempotencyKeyValidator(IdempotencyKeyFormat::SfString, maxLength: 10);

    expect($validator->isValid(str_repeat('k', 10)))->toBeTrue()
        ->and($validator->isValid(str_repeat('k', 11)))->toBeFalse();
});

it('refuses a missing or empty key in either format', function (IdempotencyKeyFormat $format): void {
    $validator = new IdempotencyKeyValidator($format);

    expect($validator->isValid(null))->toBeFalse()
        ->and($validator->isValid(''))->toBeFalse();
})->with([IdempotencyKeyFormat::Uuid, IdempotencyKeyFormat::SfString]);

it('reports the format it validates', function (): void {
    expect((new IdempotencyKeyValidator(IdempotencyKeyFormat::SfString))->format())->toBe(IdempotencyKeyFormat::SfString);
});
