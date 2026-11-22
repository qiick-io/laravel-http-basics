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
        ->and($policy->magicSignatures()['png'] ?? null)->toBe(['89504E470D0A1A0A']);
});

it('throws for an unknown upload context', function (): void {
    $resolver = new ConfigUploadPolicyResolver([]);

    expect(fn () => $resolver->forContext('missing'))
        ->toThrow(InvalidArgumentException::class);
});
