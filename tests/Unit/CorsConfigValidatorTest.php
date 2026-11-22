<?php

declare(strict_types=1);

use Qiick\HttpBasics\Cors\CorsConfigValidator;

it('normalises concrete origins and allows non-credentialed wildcards', function (): void {
    $validator = new CorsConfigValidator;

    $normalized = $validator->validate([
        'supports_credentials' => false,
        'allowed_origins' => ['*', 'https://APP.Example.com:443'],
        'allowed_origins_patterns' => ['#.*#'],
        'exposed_headers' => ['*'],
    ]);

    expect($normalized['allowed_origins'])->toBe(['*', 'https://app.example.com']);
});

it('rejects non-string origins and invalid concrete origins', function (): void {
    $validator = new CorsConfigValidator;

    expect(fn () => $validator->validate([
        'supports_credentials' => false,
        'allowed_origins' => [123],
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => $validator->validate([
        'supports_credentials' => false,
        'allowed_origins' => ['https://evil.test/path'],
    ]))->toThrow(InvalidArgumentException::class, 'cors.allowed_origins contains an invalid origin: https://evil.test/path');
});

it('rejects credentialed patterns and exposed wildcards', function (): void {
    $validator = new CorsConfigValidator;

    expect(fn () => $validator->validate([
        'supports_credentials' => true,
        'allowed_origins' => ['https://app.example.com'],
        'allowed_origins_patterns' => ['#^https://app\\.example\\.com$#'],
        'exposed_headers' => ['X-Request-Id'],
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => $validator->validate([
        'supports_credentials' => true,
        'allowed_origins' => ['https://app.example.com'],
        'allowed_origins_patterns' => [],
        'exposed_headers' => ['*'],
    ]))->toThrow(InvalidArgumentException::class);

    $ok = $validator->validate([
        'supports_credentials' => true,
        'allowed_origins' => ['https://app.example.com'],
        'allowed_origins_patterns' => [],
        'exposed_headers' => ['X-Request-Id'],
    ]);

    expect($ok['allowed_origins'])->toBe(['https://app.example.com']);
});

it('rejects a credentialed allowed_headers wildcard, which Fruitcake turns into reflection', function (): void {
    $validator = new CorsConfigValidator;

    expect(fn () => $validator->validate([
        'supports_credentials' => true,
        'allowed_origins' => ['https://app.example.com'],
        'allowed_headers' => ['Content-Type', '*'],
    ]))->toThrow(InvalidArgumentException::class, 'cors.allowed_headers must not contain * when supports_credentials is true.');

    expect($validator->validate([
        'supports_credentials' => false,
        'allowed_origins' => ['https://app.example.com'],
        'allowed_headers' => ['*'],
    ])['allowed_headers'])->toBe(['*'])
        ->and($validator->validate([
            'supports_credentials' => true,
            'allowed_origins' => ['https://app.example.com'],
            'allowed_headers' => 'not-a-list',
        ])['allowed_headers'])->toBe('not-a-list');
});
