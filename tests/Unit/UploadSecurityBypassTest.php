<?php

declare(strict_types=1);

/**
 * Security repros from the adversarial review of #13 (must stay red until the fix).
 */

use Qiick\HttpBasics\Testing\FixedIdGenerator;
use Qiick\HttpBasics\Uploads\ArrayUploadPolicy;
use Qiick\HttpBasics\Uploads\ConfigUploadPolicyResolver;
use Qiick\HttpBasics\Uploads\FileNameSanitizer;
use Qiick\HttpBasics\Uploads\HardFloor;
use Qiick\HttpBasics\Uploads\NullMalwareScanner;
use Qiick\HttpBasics\Uploads\UploadPolicy;
use Qiick\HttpBasics\Uploads\UploadPolicyResolver;
use Qiick\HttpBasics\Uploads\UploadRejectedException;
use Qiick\HttpBasics\Uploads\UploadValidator;

function uploadSecurityValidator(UploadPolicy $policy): UploadValidator
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

function uploadTempFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'hb-up-');
    expect($path)->toBeString();
    file_put_contents($path, $contents);

    return $path;
}

it('rejects dangerous sniffed MIME under a harmless extension (review U1)', function (string $name, string $body, string $declared): void {
    $path = uploadTempFile($body);
    $validator = uploadSecurityValidator(new ArrayUploadPolicy(
        allowedExtensions: null,
        allowedMimeTypes: null,
        maxBytes: 10_000_000,
    ));

    expect(fn () => $validator->validatePath($path, $name, 'files', 'file', $declared))
        ->toThrow(UploadRejectedException::class);

    @unlink($path);
})->with([
    'html-as-txt' => ['html-as-txt.txt', '<!DOCTYPE html><html><script>alert(1)</script></html>', 'text/html'],
    'svg-as-txt' => ['svg-as-txt.txt', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'],
    'php-as-txt' => ['php-as-txt.txt', "<?php system('id');", 'text/x-php'],
    'php-as-inc' => ['a.inc', "<?php system('id');", 'text/x-php'],
    'php-as-phps' => ['a.phps', "<?php system('id');", 'text/x-php'],
]);

it('rejects hard-floor gaps and mangled suffixes (review U2)', function (string $name): void {
    $floor = new HardFloor;

    expect($floor->isBlocked($name))->toBeTrue();
})->with([
    '.htaccess',
    '.user.ini',
    'a.phps',
    'a.inc',
    'a.php%00.jpg',
    'a.php;.jpg',
    'a.php::$DATA',
    "a.php\u{00A0}",
    'payload.jar',
    'payload.war',
    'script.py',
    'script.rb',
    'shortcut.lnk',
    'macro.docm',
    'macro.xlsm',
    'flash.swf',
    'config.ini',
    'passwd.htpasswd',
    'disk.iso',
]);

it('rejects RTL-override disguised lnk names (review U2/N1)', function (): void {
    $sanitizer = new FileNameSanitizer;
    // U+202E REV: displays as invoiceknl.pdf while the real suffix is lnk
    $rtlName = "invoice\u{202E}fdp.lnk";

    expect(fn () => $sanitizer->sanitize($rtlName))->toThrow(UploadRejectedException::class)
        ->and((new HardFloor)->isBlocked('evil.lnk'))->toBeTrue();
});

it('builds storageName from the sniffed MIME, never the raw client suffix (review U3)', function (): void {
    $jpeg = hex2bin('FFD8FFE000104A46494600010100000100010000') ?: '';
    $path = uploadTempFile($jpeg);
    $validator = uploadSecurityValidator(new ArrayUploadPolicy(
        allowedExtensions: ['jpg', 'jpeg'],
        allowedMimeTypes: ['image/jpeg'],
        maxBytes: 10_000,
        magicSignatures: ['jpg' => ['FFD8FF'], 'jpeg' => ['FFD8FF']],
    ));

    // Client suffix is uppercased / non-canonical; storage must use the package MIME map.
    $validated = $validator->validatePath($path, 'photo.JPEG', 'files', 'file', 'image/jpeg');

    expect($validated->storageName)->toBe('upload-id-0001.jpg')
        ->and($validated->extension)->toBe('jpg');

    @unlink($path);
});

it('ships restrictive default size and allowlists (review U4)', function (): void {
    /** @var array{uploads: array{contexts: array<string, mixed>, magic_signatures: array<string, mixed>}} $config */
    $config = require dirname(__DIR__, 2).'/config/http-basics.php';
    $resolver = new ConfigUploadPolicyResolver(
        $config['uploads']['contexts'],
        $config['uploads']['magic_signatures'],
    );
    $policy = $resolver->forContext('files');

    expect($policy->maxBytes())->toBeInt()->toBeLessThanOrEqual(10 * 1024 * 1024)
        ->and($policy->allowedExtensions())->not->toBeNull()
        ->and($policy->allowedMimeTypes())->not->toBeNull();

    $path = uploadTempFile(str_repeat('A', 5 * 1024 * 1024));
    $validator = uploadSecurityValidator($policy);

    expect(fn () => $validator->validatePath($path, 'big.bin', 'files'))
        ->toThrow(UploadRejectedException::class);

    @unlink($path);
});

it('enforces max_uncompressed_ratio on zip archives (review U5)', function (): void {
    if (! class_exists(ZipArchive::class)) {
        $this->markTestSkipped('ZipArchive extension required');
    }

    $zipPath = sys_get_temp_dir().'/hb-zip-bomb-'.uniqid('', true).'.zip';
    $zip = new ZipArchive;
    expect($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    // Highly compressible payload: ~50 MiB uncompressed in a small zip.
    $zip->addFromString('pad.bin', str_repeat("\0", 50 * 1024 * 1024));
    $zip->close();

    expect(filesize($zipPath))->toBeLessThan(200_000);

    $validator = uploadSecurityValidator(new ArrayUploadPolicy(
        allowedExtensions: ['zip'],
        allowedMimeTypes: ['application/zip', 'application/x-zip-compressed'],
        maxBytes: 10_000_000,
        maxUncompressedRatio: 10,
    ));

    expect(fn () => $validator->validatePath($zipPath, 'bomb.zip', 'files', 'file', 'application/zip'))
        ->toThrow(UploadRejectedException::class);

    @unlink($zipPath);
});

/**
 * Builds a one-entry ZIP of `$uncompressedBytes` zero bytes, optionally rewriting the
 * declared uncompressed size in both the local and the central header, as an attacker can.
 */
function uploadZipOfZeros(int $uncompressedBytes, ?int $forgedDeclaredSize = null): string
{
    $zipPath = sys_get_temp_dir().'/hb-zip-'.uniqid('', true).'.zip';
    $zip = new ZipArchive;
    expect($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('a.txt', str_repeat("\0", $uncompressedBytes));
    $zip->close();

    if ($forgedDeclaredSize !== null) {
        $bytes = (string) file_get_contents($zipPath);
        $forged = pack('V', $forgedDeclaredSize);
        $localHeaderOffset = strpos($bytes, "PK\x03\x04");
        $centralHeaderOffset = strpos($bytes, "PK\x01\x02");
        expect($localHeaderOffset)->toBeInt()->and($centralHeaderOffset)->toBeInt();
        $bytes = substr_replace($bytes, $forged, (int) $localHeaderOffset + 22, 4);
        $bytes = substr_replace($bytes, $forged, (int) $centralHeaderOffset + 24, 4);
        file_put_contents($zipPath, $bytes);
    }

    return $zipPath;
}

function uploadZipValidator(int $ratio, ?int $maxUncompressedBytes = null): UploadValidator
{
    return uploadSecurityValidator(new ArrayUploadPolicy(
        allowedExtensions: ['zip'],
        allowedMimeTypes: ['application/zip'],
        maxBytes: 10_000_000,
        maxUncompressedRatio: $ratio,
        maxUncompressedBytes: $maxUncompressedBytes,
    ));
}

it('measures the real inflated bytes, not the sizes the zip headers declare (re-review U-R1)', function (): void {
    $zipPath = uploadZipOfZeros(5 * 1024 * 1024, forgedDeclaredSize: 10);

    $archive = new ZipArchive;
    expect($archive->open($zipPath))->toBeTrue()
        ->and($archive->statIndex(0)['size'] ?? null)->toBe(10);
    $archive->close();

    try {
        uploadZipValidator(ratio: 10)->validatePath($zipPath, 'forged.zip', 'files', 'file', 'application/zip');
        $this->fail('A zip with forged sizes must be measured by its real inflated bytes.');
    } catch (UploadRejectedException $exception) {
        expect($exception->errorCode())->toBe('upload_too_large');
    } finally {
        @unlink($zipPath);
    }
});
