<?php

declare(strict_types=1);

use Qiick\HttpBasics\Testing\FixedIdGenerator;
use Qiick\HttpBasics\Uploads\ArrayUploadPolicy;
use Qiick\HttpBasics\Uploads\FileNameSanitizer;
use Qiick\HttpBasics\Uploads\HardFloor;
use Qiick\HttpBasics\Uploads\NullMalwareScanner;
use Qiick\HttpBasics\Uploads\ScanResult;
use Qiick\HttpBasics\Uploads\UploadPolicy;
use Qiick\HttpBasics\Uploads\UploadPolicyResolver;
use Qiick\HttpBasics\Uploads\UploadRejectedException;
use Qiick\HttpBasics\Uploads\UploadValidator;

function uploadValidatorFor(UploadPolicy $policy): UploadValidator
{
    $resolver = new class($policy) implements UploadPolicyResolver
    {
        public function __construct(private UploadPolicy $policy) {}

        public function forContext(string $context): UploadPolicy
        {
            return $this->policy;
        }
    };

    return new UploadValidator(
        $resolver,
        new FileNameSanitizer,
        new HardFloor,
        new NullMalwareScanner,
        new FixedIdGenerator('upload-id-0001'),
    );
}

it('rejects every hard-floor suffix including middle ones like invoice.php.pdf', function (): void {
    $floor = new HardFloor;

    expect($floor->isBlocked('invoice.php.pdf'))->toBeTrue()
        ->and($floor->isBlocked('photo.JPG'))->toBeFalse()
        ->and($floor->suffixes('a.php.jpg'))->toBe(['php', 'jpg']);
});

it('rejects unsafe client names', function (): void {
    $sanitizer = new FileNameSanitizer;

    expect(fn () => $sanitizer->sanitize('../etc/passwd'))->toThrow(UploadRejectedException::class)
        ->and(fn () => $sanitizer->sanitize("evil\0.jpg"))->toThrow(UploadRejectedException::class)
        ->and($sanitizer->sanitize('report.pdf'))->toBe('report.pdf');
});

it('validates a png path and marks the scan as not_scanned', function (): void {
    $png = tempnam(sys_get_temp_dir(), 'hb-png-');
    expect($png)->toBeString();
    file_put_contents($png, hex2bin('89504E470D0A1A0A00000000') ?: '');

    $validator = uploadValidatorFor(new ArrayUploadPolicy(
        allowedExtensions: ['png'],
        maxBytes: 1024,
        magicSignatures: ['png' => ['89504E470D0A1A0A']],
    ));

    $validated = $validator->validatePath($png, 'shot.png', 'files');

    expect($validated->extension)->toBe('png')
        ->and($validated->scan)->toBe(ScanResult::NotScanned)
        ->and($validated->storageName)->toBe('upload-id-0001.png')
        ->and($validated->sha256)->not->toBe('')
        ->and($validated->mimeDetector)->toBe('finfo');

    @unlink($png);
});

it('rejects magic mismatches and oversize files', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'hb-bad-');
    expect($path)->toBeString();
    file_put_contents($path, 'not-a-png');

    $validator = uploadValidatorFor(new ArrayUploadPolicy(
        allowedExtensions: ['png'],
        magicSignatures: ['png' => ['89504E470D0A1A0A']],
    ));

    expect(fn () => $validator->validatePath($path, 'shot.png', 'files'))
        ->toThrow(UploadRejectedException::class);

    $big = uploadValidatorFor(new ArrayUploadPolicy(
        allowedExtensions: ['txt'],
        maxBytes: 4,
    ));
    expect(fn () => $big->validatePath($path, 'note.txt', 'files'))
        ->toThrow(UploadRejectedException::class);

    @unlink($path);
});
