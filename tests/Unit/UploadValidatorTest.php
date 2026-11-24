<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Qiick\HttpBasics\Testing\FixedIdGenerator;
use Qiick\HttpBasics\Uploads\ArrayUploadPolicy;
use Qiick\HttpBasics\Uploads\DetectedMimeExtension;
use Qiick\HttpBasics\Uploads\FileNameSanitizer;
use Qiick\HttpBasics\Uploads\HardFloor;
use Qiick\HttpBasics\Uploads\MalwareScanner;
use Qiick\HttpBasics\Uploads\MismatchMode;
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

it('maps sniffed MIME types to canonical storage extensions', function (): void {
    $mapper = new DetectedMimeExtension;
    $expected = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'text/plain' => 'txt',
        'application/zip' => 'zip',
        'application/x-zip-compressed' => 'zip',
        'image/svg+xml' => 'svg',
    ];

    expect(DetectedMimeExtension::map())->toBe($expected);

    foreach ($expected as $mime => $extension) {
        expect($mapper->extensionFor($mime))->toBe($extension)
            ->and($mapper->extensionFor(strtoupper($mime).'; charset=utf-8'))->toBe($extension);
    }

    expect($mapper->extensionFor('application/octet-stream'))->toBeNull()
        ->and($mapper->extensionFor(' text/plain '))->toBe('txt');
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
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==') ?: '');

    $validator = uploadValidatorFor(new ArrayUploadPolicy(
        allowedExtensions: ['png'],
        allowedMimeTypes: ['image/png'],
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

it('validates through UploadedFile and rejects missing paths', function (): void {
    $png = tempnam(sys_get_temp_dir(), 'hb-upl-');
    expect($png)->toBeString();
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==') ?: '');

    $upload = new UploadedFile($png, 'shot.png', 'image/png', null, true);
    $validator = uploadValidatorFor(new ArrayUploadPolicy(
        allowedExtensions: ['png'],
        allowedMimeTypes: ['image/png'],
        maxBytes: 1024,
        magicSignatures: ['png' => ['89504E470D0A1A0A']],
    ));

    $validated = $validator->validate($upload, 'files');
    expect($validated->storageName)->toBe('upload-id-0001.png');

    expect(fn () => $validator->validatePath('/tmp/does-not-exist-hb-upload', 'missing.png', 'files'))
        ->toThrow(UploadRejectedException::class);

    @unlink($png);
});

it('rejects infected and failed malware scans with dedicated codes', function (): void {
    $png = tempnam(sys_get_temp_dir(), 'hb-scan-');
    expect($png)->toBeString();
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==') ?: '');

    $infected = new class implements MalwareScanner
    {
        public function scanPath(string $absolutePath): ScanResult
        {
            return ScanResult::Infected;
        }

        public function scanBytes(string $bytes): ScanResult
        {
            return ScanResult::Infected;
        }
    };
    $failed = new class implements MalwareScanner
    {
        public function scanPath(string $absolutePath): ScanResult
        {
            return ScanResult::Failed;
        }

        public function scanBytes(string $bytes): ScanResult
        {
            return ScanResult::Failed;
        }
    };

    $policy = new ArrayUploadPolicy(
        allowedExtensions: ['png'],
        allowedMimeTypes: ['image/png'],
        maxBytes: 1024,
        magicSignatures: ['png' => ['89504E470D0A1A0A']],
    );

    $resolver = new class($policy) implements UploadPolicyResolver
    {
        public function __construct(private UploadPolicy $policy) {}

        public function forContext(string $context): UploadPolicy
        {
            return $this->policy;
        }
    };

    $infectedValidator = new UploadValidator($resolver, new FileNameSanitizer, new HardFloor, $infected, new FixedIdGenerator('id'));
    $failedValidator = new UploadValidator($resolver, new FileNameSanitizer, new HardFloor, $failed, new FixedIdGenerator('id'));

    try {
        $infectedValidator->validatePath($png, 'shot.png', 'files');
        expect(false)->toBeTrue();
    } catch (UploadRejectedException $exception) {
        expect($exception->errorCode())->toBe('upload_infected');
    }

    try {
        $failedValidator->validatePath($png, 'shot.png', 'files');
        expect(false)->toBeTrue();
    } catch (UploadRejectedException $exception) {
        expect($exception->errorCode())->toBe('upload_scan_failed');
    }

    @unlink($png);
});

it('rejects svg suffixes unless allows_svg and rejects content-type mismatch', function (): void {
    $svg = tempnam(sys_get_temp_dir(), 'hb-svg-');
    expect($svg)->toBeString();
    file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

    $blocked = uploadValidatorFor(new ArrayUploadPolicy(
        allowedExtensions: ['svg'],
        allowedMimeTypes: ['image/svg+xml'],
        allowsSvg: false,
        maxBytes: 10_000,
    ));
    expect(fn () => $blocked->validatePath($svg, 'icon.svg', 'files'))
        ->toThrow(UploadRejectedException::class);

    $png = tempnam(sys_get_temp_dir(), 'hb-mm-');
    expect($png)->toBeString();
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==') ?: '');

    $mismatch = uploadValidatorFor(new ArrayUploadPolicy(
        allowedExtensions: ['png'],
        allowedMimeTypes: ['image/png'],
        maxBytes: 1024,
        contentTypeMismatch: MismatchMode::Reject,
        magicSignatures: ['png' => ['89504E470D0A1A0A']],
    ));
    expect(fn () => $mismatch->validatePath($png, 'shot.png', 'files', 'file', 'text/plain'))
        ->toThrow(UploadRejectedException::class);

    @unlink($svg);
    @unlink($png);
});

it('checks the RIFF signature of a webp under the shipped policy', function (string $bytes, bool $isAccepted): void {
    $path = tempnam(sys_get_temp_dir(), 'hb-webp-');
    expect($path)->toBeString();
    file_put_contents($path, $bytes);

    try {
        $validate = fn () => app(UploadValidator::class)->validatePath($path, 'photo.webp', 'files');

        $isAccepted
            ? expect($validate())->not->toBeNull()
            : expect($validate)->toThrow(UploadRejectedException::class);
    } finally {
        @unlink($path);
    }
})->with([
    'a webp' => ["RIFF\x1a\x00\x00\x00WEBPVP8L\x0d\x00\x00\x00\x2f\x00\x00\x00\x10\x07\x10\x11\x11\x88\x88\xfe\x07\x00", true],
    'png bytes named webp' => [base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='), false],
]);
