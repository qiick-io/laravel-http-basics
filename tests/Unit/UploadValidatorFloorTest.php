<?php

declare(strict_types=1);

/**
 * Pins every UploadValidator branch the floor depends on, so that removing a check
 * (magic bytes, an allowlist, the archive budget) fails a test instead of hiding
 * behind a mutation ignore.
 */

use Illuminate\Http\UploadedFile;
use Qiick\HttpBasics\Testing\FixedIdGenerator;
use Qiick\HttpBasics\Uploads\ArrayUploadPolicy;
use Qiick\HttpBasics\Uploads\DetectedMimeExtension;
use Qiick\HttpBasics\Uploads\FileNameSanitizer;
use Qiick\HttpBasics\Uploads\HardFloor;
use Qiick\HttpBasics\Uploads\MismatchMode;
use Qiick\HttpBasics\Uploads\NullMalwareScanner;
use Qiick\HttpBasics\Uploads\ScanResult;
use Qiick\HttpBasics\Uploads\UploadPolicy;
use Qiick\HttpBasics\Uploads\UploadPolicyResolver;
use Qiick\HttpBasics\Uploads\UploadRejectedException;
use Qiick\HttpBasics\Uploads\UploadValidator;
use Qiick\HttpBasics\Uploads\ValidatedUpload;

const FLOOR_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
const FLOOR_JPEG_BYTES = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
const FLOOR_GIF_BYTES = "GIF89a\x01\x00\x01\x00\x00\x00\x00;";
const FLOOR_SVG_BYTES = '<svg xmlns="http://www.w3.org/2000/svg"></svg>';

function floorValidator(UploadPolicy $policy): UploadValidator
{
    return new UploadValidator(
        new readonly class($policy) implements UploadPolicyResolver
        {
            public function __construct(private UploadPolicy $policy) {}

            public function forContext(string $context): UploadPolicy
            {
                return $this->policy;
            }
        },
        new FileNameSanitizer,
        new HardFloor,
        new NullMalwareScanner,
        new FixedIdGenerator('upload-id-0001'),
    );
}

function floorFile(string $contents): string
{
    $path = sys_get_temp_dir().'/hb-floor-'.uniqid('', true);
    file_put_contents($path, $contents);

    return $path;
}

function floorPng(): string
{
    return (string) base64_decode(FLOOR_PNG_BASE64, true);
}

/**
 * @param  array<string, string>  $entries  entry name => contents
 */
function floorZip(array $entries): string
{
    $zipPath = sys_get_temp_dir().'/hb-floor-'.uniqid('', true).'.zip';
    $archive = new ZipArchive;
    expect($archive->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();

    foreach ($entries as $entryName => $entryContents) {
        $archive->addFromString($entryName, $entryContents);
    }

    $archive->close();

    return $zipPath;
}

/**
 * Runs the validator and returns the rejection code, or `accepted`.
 */
function floorOutcome(UploadPolicy $policy, string $path, string $clientName, ?string $declaredMime = null): string
{
    try {
        floorValidator($policy)->validatePath($path, $clientName, 'files', 'file', $declaredMime);

        return 'accepted';
    } catch (UploadRejectedException $exception) {
        return $exception->errorCode();
    } finally {
        @unlink($path);
    }
}

it('rejects an UploadedFile whose temporary file is gone', function (): void {
    $path = floorFile(floorPng());
    $upload = new UploadedFile($path, 'shot.png', 'image/png', null, true);
    unlink($path);

    expect(fn () => floorValidator(new ArrayUploadPolicy)->validate($upload, 'files'))
        ->toThrow(UploadRejectedException::class, 'The uploaded file name is invalid.');
});

it('rejects a directory and an unreadable file as an invalid upload', function (): void {
    expect(floorOutcome(new ArrayUploadPolicy, sys_get_temp_dir(), 'dir.txt'))->toBe('upload_name_invalid');

    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        return;
    }

    $unreadable = floorFile('hello');
    chmod($unreadable, 0o000);

    expect(floorOutcome(new ArrayUploadPolicy, $unreadable, 'note.txt'))->toBe('upload_name_invalid');
});

it('checks the last suffix of the client name against the allowlist', function (): void {
    $policy = new ArrayUploadPolicy(allowedExtensions: ['txt']);

    expect(floorOutcome($policy, floorFile('hello world'), 'notes.v2.txt'))->toBe('accepted')
        ->and(floorOutcome($policy, floorFile('hello world'), 'README'))->toBe('upload_type_not_allowed');
});

it('compares allowlists case-insensitively', function (): void {
    $policy = new ArrayUploadPolicy(allowedExtensions: ['PNG'], allowedMimeTypes: ['IMAGE/PNG']);

    expect(floorOutcome($policy, floorFile(floorPng()), 'shot.png'))->toBe('accepted');
});

it('accepts a file of exactly max_bytes and rejects one byte more', function (): void {
    $policy = new ArrayUploadPolicy(maxBytes: 11);

    expect(floorOutcome($policy, floorFile('hello world'), 'note.txt'))->toBe('accepted')
        ->and(floorOutcome($policy, floorFile('hello world!'), 'note.txt'))->toBe('upload_too_large');
});

it('rejects content that misses the magic bytes of its extension even with no allowlist', function (): void {
    $policy = new ArrayUploadPolicy(magicSignatures: ['pdf' => ['255044462D']]);

    expect(floorOutcome($policy, floorFile('hello world'), 'report.pdf'))->toBe('upload_content_mismatch')
        ->and(floorOutcome($policy, floorFile('hello world'), 'note.txt'))->toBe('accepted');
});

it('matches magic signatures ignoring spaces and case, against any listed signature', function (): void {
    $jpegPolicy = new ArrayUploadPolicy(magicSignatures: ['jpg' => ['ff d8 ff']]);
    $gifPolicy = new ArrayUploadPolicy(magicSignatures: ['gif' => ['474946383761', '474946383961']]);
    $longPolicy = new ArrayUploadPolicy(magicSignatures: ['png' => ['89504E470D0A1A0A0000000D49484452']]);

    expect(floorOutcome($jpegPolicy, floorFile(FLOOR_JPEG_BYTES), 'photo.jpg'))->toBe('accepted')
        ->and(floorOutcome($gifPolicy, floorFile(FLOOR_GIF_BYTES), 'anim.gif'))->toBe('accepted')
        ->and(floorOutcome($longPolicy, floorFile(floorPng()), 'shot.png'))->toBe('accepted');
});

it('never lets an empty or odd-length magic signature match every file', function (): void {
    $policy = new ArrayUploadPolicy(magicSignatures: ['pdf' => ['', ' ', '25504']]);

    expect(floorOutcome($policy, floorFile('%PDF-1.4'), 'report.pdf'))->toBe('upload_content_mismatch');
});

it('rejects a sniffed type whose storage extension is not allowlisted', function (): void {
    $policy = new ArrayUploadPolicy(allowedExtensions: ['txt']);

    expect(floorOutcome($policy, floorFile(FLOOR_JPEG_BYTES), 'note.txt'))->toBe('upload_type_not_allowed')
        ->and(floorOutcome($policy, floorFile(str_repeat("\x00\x01\x02\x03\xFE\xFF\x80\x81", 8)), 'note.txt'))->toBe('upload_type_not_allowed');
});

it('rejects a sniffed MIME outside the MIME allowlist', function (): void {
    $policy = new ArrayUploadPolicy(allowedMimeTypes: ['image/png']);

    expect(floorOutcome($policy, floorFile('hello world'), 'note.txt'))->toBe('upload_type_not_allowed');
});

it('stores an unknown type without any extension', function (): void {
    $path = floorFile(str_repeat("\x00\x01\x02\x03\xFE\xFF\x80\x81", 8));
    $validated = floorValidator(new ArrayUploadPolicy)->validatePath($path, 'blob.dat', 'files');
    @unlink($path);

    expect($validated->storageName)->toBe('upload-id-0001')
        ->and($validated->extension)->toBe('')
        ->and($validated->detectedMime)->toBe('application/octet-stream');
});

it('records the sniffed facts of an accepted file', function (): void {
    $path = floorFile(floorPng());
    $expectedHash = hash_file('sha256', $path);
    $validated = floorValidator(new ArrayUploadPolicy)->validatePath($path, 'shot.png', 'files', 'file', 'image/png');
    @unlink($path);

    expect($validated)->toBeInstanceOf(ValidatedUpload::class)
        ->and($validated->publicId)->toBe('upload-id-0001')
        ->and($validated->clientName)->toBe('shot.png')
        ->and($validated->safeName)->toBe('shot.png')
        ->and($validated->storageName)->toBe('upload-id-0001.png')
        ->and($validated->declaredMime)->toBe('image/png')
        ->and($validated->detectedMime)->toBe('image/png')
        ->and($validated->mimeDetectorVersion)->toBe(PHP_VERSION)
        ->and($validated->sizeBytes)->toBe(strlen(floorPng()))
        ->and($validated->sha256)->toBe($expectedHash)
        ->and($validated->scan)->toBe(ScanResult::NotScanned);
});

it('compares the declared type by its essence and only in reject mode', function (?string $declaredMime, MismatchMode $mode, string $expectedOutcome): void {
    $policy = new ArrayUploadPolicy(contentTypeMismatch: $mode);

    expect(floorOutcome($policy, floorFile(floorPng()), 'shot.png', $declaredMime))->toBe($expectedOutcome);
})->with([
    'no declared type' => [null, MismatchMode::Reject, 'accepted'],
    'empty declared type' => ['', MismatchMode::Reject, 'accepted'],
    'case and parameters ignored' => [' IMAGE/PNG ; charset=binary', MismatchMode::Reject, 'accepted'],
    'mismatch rejected' => ['text/plain', MismatchMode::Reject, 'upload_content_mismatch'],
    'parameter is not the essence' => ['text/plain;image/png', MismatchMode::Reject, 'upload_content_mismatch'],
    'mismatch trusted when sniffing wins' => ['text/plain', MismatchMode::TrustSniffed, 'accepted'],
]);

it('accepts SVG content and suffix only when the context allows SVG', function (): void {
    $path = floorFile(FLOOR_SVG_BYTES);
    $validated = floorValidator(new ArrayUploadPolicy(allowsSvg: true))->validatePath($path, 'icon.svg', 'files');
    @unlink($path);

    expect($validated->storageName)->toBe('upload-id-0001.svg')
        ->and(floorOutcome(new ArrayUploadPolicy, floorFile(FLOOR_SVG_BYTES), 'icon.svg'))->toBe('upload_forbidden_type')
        ->and(floorOutcome(new ArrayUploadPolicy, floorFile('hello world'), 'icon.svg'))->toBe('upload_forbidden_type');
});

it('accepts an honest archive within its inflation budget', function (): void {
    $honestZip = floorZip(['a.txt' => 'hello world', 'b.txt' => 'second entry']);

    expect(floorOutcome(new ArrayUploadPolicy(maxUncompressedRatio: 10), $honestZip, 'bundle.zip'))->toBe('accepted');
});

it('skips the archive check for non-archives and when no limit is set', function (): void {
    $bomb = floorZip(['pad.bin' => str_repeat("\0", 1024 * 1024)]);

    expect(floorOutcome(new ArrayUploadPolicy(maxUncompressedRatio: 10), floorFile(floorPng()), 'shot.png'))->toBe('accepted')
        ->and(floorOutcome(new ArrayUploadPolicy, $bomb, 'bomb.zip'))->toBe('accepted')
        // With neither limit set the product opted out of archive inspection entirely.
        ->and(floorOutcome(new ArrayUploadPolicy, floorZip(['../evil.php' => 'x']), 'a.zip'))->toBe('accepted');
});

it('rejects an archive whose entries together exceed the budget', function (): void {
    // Each entry alone stays under size × ratio; only the running total exceeds it.
    $zipPath = floorZip(['a.txt' => str_repeat('a', 4000), 'b.txt' => str_repeat('b', 4000)]);
    $ratio = intdiv(6000, (int) filesize($zipPath));

    expect(floorOutcome(new ArrayUploadPolicy(maxUncompressedRatio: $ratio), $zipPath, 'pair.zip'))->toBe('upload_too_large');
});

it('caps the inflation budget at max_uncompressed_bytes', function (): void {
    $policy = new ArrayUploadPolicy(maxUncompressedRatio: 1000, maxUncompressedBytes: 1000);

    expect(floorOutcome($policy, floorZip(['a.txt' => str_repeat('a', 1001)]), 'a.zip'))->toBe('upload_too_large')
        ->and(floorOutcome($policy, floorZip(['a.txt' => str_repeat('a', 1000)]), 'a.zip'))->toBe('accepted');
});

it('measures any archive libzip opens, whatever its name or sniffed type', function (): void {
    $bombAsDocument = floorZip(['word/document.xml' => str_repeat("\0", 1024 * 1024)]);

    expect(floorOutcome(new ArrayUploadPolicy(maxUncompressedRatio: 10), $bombAsDocument, 'report.docx'))->toBe('upload_too_large');
});

it('rejects a corrupt or encrypted archive it cannot measure', function (): void {
    $truncated = floorZip(['a.txt' => 'hello world']);
    file_put_contents($truncated, substr((string) file_get_contents($truncated), 0, -30));

    $encrypted = floorZip(['a.txt' => 'secret']);
    $archive = new ZipArchive;
    $archive->open($encrypted);
    $archive->setEncryptionName('a.txt', ZipArchive::EM_AES_256, 'password');
    $archive->close();

    $policy = new ArrayUploadPolicy(maxUncompressedRatio: 10);

    expect(floorOutcome($policy, $truncated, 'a.zip'))->toBe('upload_content_mismatch')
        ->and(floorOutcome($policy, $encrypted, 'a.zip'))->toBe('upload_content_mismatch');
});

it('caps the number of archive entries at 10 000', function (): void {
    $entries = array_fill_keys(array_map(static fn (int $entryNumber): string => "e{$entryNumber}", range(1, 10_000)), '');
    $policy = new ArrayUploadPolicy(maxUncompressedRatio: 10);

    expect(floorOutcome($policy, floorZip($entries), 'many.zip'))->toBe('accepted')
        ->and(floorOutcome($policy, floorZip($entries + ['one-more' => '']), 'many.zip'))->toBe('upload_too_large');
});

it('only ever appends a short alphanumeric storage extension', function (): void {
    foreach (DetectedMimeExtension::map() as $storageExtension) {
        expect($storageExtension)->toMatch('/\A[a-z0-9]{1,10}\z/');
    }
});

/**
 * Overwrites `$replacement` at `$offset` from the given ZIP signature, in every
 * record that carries it.
 */
function floorPatchZip(string $zipPath, string $signature, int $offset, string $replacement): void
{
    $bytes = (string) file_get_contents($zipPath);
    $recordOffset = strpos($bytes, $signature);
    expect($recordOffset)->toBeInt();
    file_put_contents($zipPath, substr_replace($bytes, $replacement, (int) $recordOffset + $offset, strlen($replacement)));
}

it('refuses a corrupt deflate stream instead of failing with a 500 (re-review U-R2)', function (): void {
    $zipPath = floorZip(['a.txt' => str_repeat('hello world ', 2000)]);
    $bytes = (string) file_get_contents($zipPath);
    // The compressed data starts after the 30-byte local header and the 5-byte name.
    file_put_contents($zipPath, substr_replace($bytes, str_repeat("\xFF", 16), 35, 16));

    expect(floorOutcome(new ArrayUploadPolicy(maxUncompressedRatio: 1000), $zipPath, 'a.zip'))->toBe('upload_content_mismatch');
});

it('refuses an archive whose CRC does not match its data (re-review U-R2)', function (): void {
    $zipPath = floorZip(['a.txt' => 'hello world']);
    floorPatchZip($zipPath, "PK\x03\x04", 14, "\0\0\0\0");
    floorPatchZip($zipPath, "PK\x01\x02", 16, "\0\0\0\0");

    expect(floorOutcome(new ArrayUploadPolicy(maxUncompressedRatio: 1000), $zipPath, 'a.zip'))->toBe('upload_content_mismatch');
});

it('refuses a PDF+ZIP polyglot libzip cannot open as an archive (re-review U-R3)', function (): void {
    $zipPath = floorZip(['pad.bin' => str_repeat("\0", 1024 * 1024)]);
    $polyglot = floorFile("%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n".file_get_contents($zipPath));
    @unlink($zipPath);

    $archive = new ZipArchive;
    expect($archive->open($polyglot, ZipArchive::RDONLY))->toBe(ZipArchive::ER_NOZIP);

    expect(floorOutcome(new ArrayUploadPolicy(maxUncompressedRatio: 10), $polyglot, 'report.pdf'))->toBe('upload_content_mismatch');
});

it('looks for an end-of-central-directory record only where a ZIP reader does', function (): void {
    // 22 bytes of record plus the longest comment (65 535) is as far back as a reader searches.
    $withinReach = floorFile("PK\x05\x06".str_repeat('a', 65_557 - 4));
    $outOfReach = floorFile("PK\x05\x06".str_repeat('a', 65_558 - 4));
    $policy = new ArrayUploadPolicy(maxUncompressedRatio: 10);

    expect(floorOutcome($policy, $withinReach, 'note.txt'))->toBe('upload_content_mismatch')
        ->and(floorOutcome($policy, $outOfReach, 'note.txt'))->toBe('accepted');
});

it('applies max_uncompressed_bytes even when no ratio is set', function (): void {
    $policy = new ArrayUploadPolicy(maxUncompressedBytes: 1000);

    expect(floorOutcome($policy, floorZip(['a.txt' => str_repeat('a', 1001)]), 'a.zip'))->toBe('upload_too_large')
        ->and(floorOutcome($policy, floorZip(['a.txt' => str_repeat('a', 1000)]), 'a.zip'))->toBe('accepted');
});

it('fails closed on a zero or negative archive budget', function (ArrayUploadPolicy $policy): void {
    expect(floorOutcome($policy, floorZip(['a.txt' => 'x']), 'a.zip'))->toBe('upload_too_large');
})->with([
    'negative ratio' => [new ArrayUploadPolicy(maxUncompressedRatio: -1)],
    'zero ceiling' => [new ArrayUploadPolicy(maxUncompressedRatio: 10, maxUncompressedBytes: 0)],
    'negative ceiling' => [new ArrayUploadPolicy(maxUncompressedBytes: -5)],
]);

it('refuses archive entries whose names escape or hide', function (string $entryName): void {
    expect(floorOutcome(new ArrayUploadPolicy(maxUncompressedRatio: 10), floorZip([$entryName => 'x']), 'a.zip'))->toBe('upload_forbidden_type');
})->with([
    'parent traversal' => '../../../../etc/cron.d/evil',
    'traversal inside' => 'docs/../../evil.php',
    'absolute' => '/abs/evil.php',
    'windows traversal' => '..\\..\\win.php',
    'windows drive' => 'C:\\evil.php',
    'control character' => "evil\x07.txt",
]);

it('accepts ordinary nested entry names', function (): void {
    $zipPath = floorZip(['docs/readme.txt' => 'x', 'docs/v1..v2/notes.txt' => 'y', 'img/' => '']);

    expect(floorOutcome(new ArrayUploadPolicy(maxUncompressedRatio: 10), $zipPath, 'a.zip'))->toBe('accepted');
});

it('puts the previous error handler back after measuring an archive', function (): void {
    floorOutcome(new ArrayUploadPolicy(maxUncompressedRatio: 10), floorZip(['a.txt' => 'x']), 'a.zip');

    expect(fn () => trigger_error('unrelated warning', E_USER_WARNING))->toThrow(ErrorException::class, 'unrelated warning');
});
