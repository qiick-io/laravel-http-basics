<?php

declare(strict_types=1);

use Qiick\HttpBasics\Uploads\FileNameSanitizer;
use Qiick\HttpBasics\Uploads\UploadRejectedException;

it('accepts a plain basename and rejects each unsafe shape', function (): void {
    $sanitizer = new FileNameSanitizer;

    expect(FileNameSanitizer::maxBytes())->toBe(255);

    expect($sanitizer->sanitize('report.pdf'))->toBe('report.pdf')
        ->and($sanitizer->sanitize('photo.JPG'))->toBe('photo.JPG');

    expect(fn () => $sanitizer->sanitize(''))->toThrow(UploadRejectedException::class);
    expect(fn () => $sanitizer->sanitize(str_repeat('a', FileNameSanitizer::maxBytes() + 1)))->toThrow(UploadRejectedException::class);
    expect($sanitizer->sanitize(str_repeat('a', FileNameSanitizer::maxBytes())))->toBe(str_repeat('a', FileNameSanitizer::maxBytes()));

    expect(fn () => $sanitizer->sanitize("evil\0.jpg"))->toThrow(UploadRejectedException::class);
    expect(fn () => $sanitizer->sanitize("invoice\u{202E}fdp.lnk"))->toThrow(UploadRejectedException::class);
    expect(fn () => $sanitizer->sanitize("\xC3\x28.jpg"))->toThrow(UploadRejectedException::class);

    expect(fn () => $sanitizer->sanitize('path/to.jpg'))->toThrow(UploadRejectedException::class);
    expect(fn () => $sanitizer->sanitize('path\\to.jpg'))->toThrow(UploadRejectedException::class);
    expect(fn () => $sanitizer->sanitize('a..b.jpg'))->toThrow(UploadRejectedException::class);
    expect(fn () => $sanitizer->sanitize('a:b.jpg'))->toThrow(UploadRejectedException::class);
    expect(fn () => $sanitizer->sanitize('a%00.jpg'))->toThrow(UploadRejectedException::class);
    expect(fn () => $sanitizer->sanitize('a;b.jpg'))->toThrow(UploadRejectedException::class);

    expect(fn () => $sanitizer->sanitize('trailing.'))->toThrow(UploadRejectedException::class);
    expect(fn () => $sanitizer->sanitize('trailing '))->toThrow(UploadRejectedException::class);
    expect(fn () => $sanitizer->sanitize(' leading.jpg'))->toThrow(UploadRejectedException::class);
});
