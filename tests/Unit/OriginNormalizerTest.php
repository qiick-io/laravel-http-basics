<?php

declare(strict_types=1);

use Qiick\HttpBasics\Cors\OriginNormalizer;

it('normalises safe origins and rejects every unsafe shape', function (): void {
    $normalizer = new OriginNormalizer;

    expect($normalizer->normalize('https://API.Example.com:443'))->toBe('https://api.example.com')
        ->and($normalizer->normalize('http://localhost:3000'))->toBe('http://localhost:3000')
        ->and($normalizer->normalize('https://app.example.com:8443'))->toBe('https://app.example.com:8443')
        ->and($normalizer->normalize('http://app.example.com:80'))->toBe('http://app.example.com')
        ->and($normalizer->normalize('https://app.example.com.'))->toBe('https://app.example.com')
        ->and($normalizer->normalize('  https://app.example.com  '))->toBe('https://app.example.com');

    expect($normalizer->normalize(''))->toBeNull()
        ->and($normalizer->normalize('null'))->toBeNull()
        ->and($normalizer->normalize('NULL'))->toBeNull()
        ->and($normalizer->normalize('ftp://app.example.com'))->toBeNull()
        ->and($normalizer->normalize('https://user:pass@evil.test'))->toBeNull()
        ->and($normalizer->normalize('https://evil.test/path'))->toBeNull()
        ->and($normalizer->normalize('https://evil.test?q=1'))->toBeNull()
        ->and($normalizer->normalize('https://evil.test#frag'))->toBeNull()
        ->and($normalizer->normalize('https://.'))->toBeNull()
        ->and($normalizer->normalize('not-a-url'))->toBeNull();
});

it('rejects each unsafe origin component on its own', function (string $origin): void {
    expect((new OriginNormalizer)->normalize($origin))->toBeNull();
})->with([
    'unparseable' => 'http://:80',
    'scheme without host' => 'https:',
    'userinfo user only' => 'https://user@evil.test',
    'userinfo password only' => 'https://:secret@evil.test',
    'path only' => 'https://evil.test/',
    'query only' => 'https://evil.test?q',
    'fragment only' => 'https://evil.test#f',
    'empty label' => 'https://app..example.com',
    'port zero' => 'https://app.example.com:0',
]);

it('lower-cases the scheme and keeps the lowest valid port', function (): void {
    $normalizer = new OriginNormalizer;

    expect($normalizer->normalize('HTTPS://App.Example.com'))->toBe('https://app.example.com')
        ->and($normalizer->normalize('http://localhost:1'))->toBe('http://localhost:1')
        ->and($normalizer->normalize('https://app.example.com:80'))->toBe('https://app.example.com:80')
        ->and($normalizer->normalize('http://app.example.com:443'))->toBe('http://app.example.com:443');
});
