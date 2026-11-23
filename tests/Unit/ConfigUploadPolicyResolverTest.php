<?php

declare(strict_types=1);

use Qiick\HttpBasics\Uploads\ConfigUploadPolicyResolver;
use Qiick\HttpBasics\Uploads\MismatchMode;

it('resolves the files context with restrictive defaults from config', function (): void {
    /** @var array{uploads: array{contexts: array<string, mixed>, magic_signatures: array<string, mixed>}} $config */
    $config = require dirname(__DIR__, 2).'/config/http-basics.php';

    $resolver = new ConfigUploadPolicyResolver(
        $config['uploads']['contexts'],
        $config['uploads']['magic_signatures'],
    );

    $policy = $resolver->forContext('files');

    expect($policy->maxBytes())->toBe(10 * 1024 * 1024)
        ->and($policy->allowedExtensions())->toContain('pdf', 'png', 'txt')
        ->and($policy->allowedMimeTypes())->toContain('application/pdf', 'image/png', 'text/plain')
        ->and($policy->allowsSvg())->toBeFalse()
        ->and($policy->contentTypeMismatch())->toBe(MismatchMode::Reject)
        ->and($policy->maxUncompressedRatio())->toBe(100)
        ->and($policy->maxUncompressedBytes())->toBe(256 * 1024 * 1024)
        ->and($policy->magicSignatures()['png'] ?? null)->toBe(['89504E470D0A1A0A']);
});

it('throws for an unknown upload context', function (): void {
    $resolver = new ConfigUploadPolicyResolver([]);

    expect(fn () => $resolver->forContext('missing'))
        ->toThrow(InvalidArgumentException::class);
});

it('normalises partial context entries and falls back to default magic', function (): void {
    $resolver = new ConfigUploadPolicyResolver([
        'chat' => [
            'allowed_extensions' => ['png', 123, null],
            'allowed_mime_types' => 'not-an-array',
            'max_bytes' => 'unlimited',
            'allows_svg' => true,
            'content_type_mismatch' => 'trust_sniffed',
            'max_uncompressed_ratio' => 12,
        ],
    ], [
        'png' => ['89504E470D0A1A0A', 99],
        0 => ['ignored'],
    ]);

    $policy = $resolver->forContext('chat');

    expect($policy->allowedExtensions())->toBe(['png'])
        ->and($policy->allowedMimeTypes())->toBeNull()
        ->and($policy->maxBytes())->toBeNull()
        ->and($policy->allowsSvg())->toBeTrue()
        ->and($policy->contentTypeMismatch())->toBe(MismatchMode::TrustSniffed)
        ->and($policy->maxUncompressedRatio())->toBe(12)
        ->and($policy->magicSignatures())->toBe(['png' => ['89504E470D0A1A0A']]);
});

it('rejects unknown mismatch modes and keeps explicit magic maps', function (): void {
    $resolver = new ConfigUploadPolicyResolver([
        'files' => [
            'allowed_extensions' => null,
            'allowed_mime_types' => ['image/png'],
            'max_bytes' => 100,
            'content_type_mismatch' => 'nope',
            'magic_signatures' => [
                'png' => ['AABB'],
            ],
        ],
    ]);

    $policy = $resolver->forContext('files');

    expect($policy->contentTypeMismatch())->toBe(MismatchMode::Reject)
        ->and($policy->maxBytes())->toBe(100)
        ->and($policy->allowedMimeTypes())->toBe(['image/png'])
        ->and($policy->magicSignatures())->toBe(['png' => ['AABB']]);
});

it('coerces loose config values without failing open', function (): void {
    $resolver = new ConfigUploadPolicyResolver([
        'loose' => [
            'allows_svg' => 1,
            'allows_archives' => 1,
            'content_type_mismatch' => 123,
            'max_bytes' => 0,
            'max_uncompressed_bytes' => 4096,
            'magic_signatures' => [
                0 => ['AABB'],
                'gif' => 'not-a-list',
                'png' => ['89504E470D0A1A0A'],
            ],
        ],
        'bare' => [],
    ]);

    $loosePolicy = $resolver->forContext('loose');
    $barePolicy = $resolver->forContext('bare');

    expect($loosePolicy->allowsSvg())->toBeTrue()
        ->and($loosePolicy->allowsArchives())->toBeTrue()
        ->and($loosePolicy->contentTypeMismatch())->toBe(MismatchMode::Reject)
        ->and($loosePolicy->maxBytes())->toBe(0)
        ->and($loosePolicy->maxUncompressedBytes())->toBe(4096)
        ->and($loosePolicy->magicSignatures())->toBe(['png' => ['89504E470D0A1A0A']])
        ->and($barePolicy->allowsSvg())->toBeFalse()
        // Archives stay refused unless a context opts in.
        ->and($barePolicy->allowsArchives())->toBeFalse()
        ->and($barePolicy->maxUncompressedBytes())->toBeNull()
        ->and($barePolicy->magicSignatures())->toBe([]);
});
