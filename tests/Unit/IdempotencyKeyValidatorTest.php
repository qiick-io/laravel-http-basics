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

it('reads an sf-string key in its quoted RFC 9651 form, or bare', function (string $header, ?string $expected): void {
    expect((new IdempotencyKeyValidator(IdempotencyKeyFormat::SfString))->canonicalKey($header))->toBe($expected);
})->with([
    'the draft example' => ['"8e03978e-40d5-43e8-bc93-6894a57f9324"', '8e03978e-40d5-43e8-bc93-6894a57f9324'],
    'escaped quote and backslash' => ['"a\\"b\\\\c"', 'a"b\\c'],
    'a space inside the quotes' => ['"order 42"', 'order 42'],
    'bare' => ['order-42', 'order-42'],
    'empty quotes' => ['""', null],
    'unterminated' => ['"abc', null],
    'text after the closing quote' => ['"abc"d', null],
    'an unescaped quote inside' => ['"a"b"', null],
    'an escape of another character' => ['"a\\nb"', null],
    'a trailing backslash' => ['"abc\\"', null],
    'a control character' => ["\"a\tb\"", null],
    'a quote in a bare key' => ['a"b', null],
]);

it('caps the unquoted sf-string key, not the quoted header', function (): void {
    $validator = new IdempotencyKeyValidator(IdempotencyKeyFormat::SfString, 4);

    expect($validator->canonicalKey('"abcd"'))->toBe('abcd')
        ->and($validator->canonicalKey('"abcde"'))->toBeNull();
});

it('keeps uuid keys bare', function (): void {
    $validator = new IdempotencyKeyValidator(IdempotencyKeyFormat::Uuid);

    expect($validator->canonicalKey('550e8400-e29b-41d4-a716-446655440000'))->toBe('550e8400-e29b-41d4-a716-446655440000')
        ->and($validator->canonicalKey('"550e8400-e29b-41d4-a716-446655440000"'))->toBeNull()
        ->and($validator->canonicalKey(null))->toBeNull();
});
