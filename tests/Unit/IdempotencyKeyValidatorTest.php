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
