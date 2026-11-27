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
use Qiick\HttpBasics\Uploads\ZipLayoutVerifier;

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

    expect(floorOutcome(new ArrayUploadPolicy(allowsArchives: true, maxUncompressedRatio: 10), $honestZip, 'bundle.zip'))->toBe('accepted');
});

it('skips the archive check for non-archives and when no limit is set', function (): void {
    $bomb = floorZip(['pad.bin' => str_repeat("\0", 1024 * 1024)]);

    expect(floorOutcome(new ArrayUploadPolicy(allowsArchives: true, maxUncompressedRatio: 10), floorFile(floorPng()), 'shot.png'))->toBe('accepted')
        ->and(floorOutcome(new ArrayUploadPolicy(allowsArchives: true), $bomb, 'bomb.zip'))->toBe('accepted')
        // With neither limit set the product opted out of archive inspection entirely.
        ->and(floorOutcome(new ArrayUploadPolicy(allowsArchives: true), floorZip(['../evil.php' => 'x']), 'a.zip'))->toBe('accepted');
});

it('rejects an archive whose entries together exceed the budget', function (): void {
    // Each entry alone stays under size × ratio; only the running total exceeds it.
    $zipPath = floorZip(['a.txt' => str_repeat('a', 4000), 'b.txt' => str_repeat('b', 4000)]);
    $ratio = intdiv(6000, (int) filesize($zipPath));

    expect(floorOutcome(new ArrayUploadPolicy(allowsArchives: true, maxUncompressedRatio: $ratio), $zipPath, 'pair.zip'))->toBe('upload_too_large');
});

it('caps the inflation budget at max_uncompressed_bytes', function (): void {
    $policy = new ArrayUploadPolicy(allowsArchives: true, maxUncompressedRatio: 1000, maxUncompressedBytes: 1000);

    expect(floorOutcome($policy, floorZip(['a.txt' => str_repeat('a', 1001)]), 'a.zip'))->toBe('upload_too_large')
        ->and(floorOutcome($policy, floorZip(['a.txt' => str_repeat('a', 1000)]), 'a.zip'))->toBe('accepted');
});

it('measures any archive libzip opens, whatever its name or sniffed type', function (): void {
    $bombAsDocument = floorZip(['word/document.xml' => str_repeat("\0", 1024 * 1024)]);

    expect(floorOutcome(new ArrayUploadPolicy(allowsArchives: true, maxUncompressedRatio: 10), $bombAsDocument, 'report.docx'))->toBe('upload_too_large');
});

it('rejects a corrupt or encrypted archive it cannot measure', function (): void {
    $truncated = floorZip(['a.txt' => 'hello world']);
    file_put_contents($truncated, substr((string) file_get_contents($truncated), 0, -30));

    $encrypted = floorZip(['a.txt' => 'secret']);
    $archive = new ZipArchive;
    $archive->open($encrypted);
    $archive->setEncryptionName('a.txt', ZipArchive::EM_AES_256, 'password');
    $archive->close();

    $policy = new ArrayUploadPolicy(allowsArchives: true, maxUncompressedRatio: 10);

    expect(floorOutcome($policy, $truncated, 'a.zip'))->toBe('upload_content_mismatch')
        ->and(floorOutcome($policy, $encrypted, 'a.zip'))->toBe('upload_content_mismatch');
});

it('caps the number of archive entries at 10 000', function (): void {
    $entries = array_fill_keys(array_map(static fn (int $entryNumber): string => "e{$entryNumber}", range(1, 10_000)), '');
    $policy = new ArrayUploadPolicy(allowsArchives: true, maxUncompressedRatio: 10);

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

    expect(floorOutcome(new ArrayUploadPolicy(allowsArchives: true, maxUncompressedRatio: 1000), $zipPath, 'a.zip'))->toBe('upload_content_mismatch');
});

it('refuses an archive whose CRC does not match its data (re-review U-R2)', function (): void {
    $zipPath = floorZip(['a.txt' => 'hello world']);
    floorPatchZip($zipPath, "PK\x03\x04", 14, "\0\0\0\0");
    floorPatchZip($zipPath, "PK\x01\x02", 16, "\0\0\0\0");

    expect(floorOutcome(new ArrayUploadPolicy(allowsArchives: true, maxUncompressedRatio: 1000), $zipPath, 'a.zip'))->toBe('upload_content_mismatch');
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
    // Leading text (so libzip says "not a zip"), a directory header, a record that points
    // back at it, then padding. 22 bytes of record plus the longest comment (65 535) is
    // how far back a reader looks.
    $plausibleRecord = "PK\x05\x06".pack('vvvvVVv', 0, 0, 1, 1, 4, 0, 0);
    $withinReach = floorFile("notes\nPK\x01\x02".$plausibleRecord.str_repeat('a', 65_557 - 22));
    $outOfReach = floorFile("notes\nPK\x01\x02".$plausibleRecord.str_repeat('a', 65_558 - 22));
    $policy = new ArrayUploadPolicy(maxUncompressedRatio: 10);

    expect(floorOutcome($policy, $withinReach, 'note.txt'))->toBe('upload_content_mismatch')
        ->and(floorOutcome($policy, $outOfReach, 'note.txt'))->toBe('accepted');
});

it('applies max_uncompressed_bytes even when no ratio is set', function (): void {
    $policy = new ArrayUploadPolicy(allowsArchives: true, maxUncompressedBytes: 1000);

    expect(floorOutcome($policy, floorZip(['a.txt' => str_repeat('a', 1001)]), 'a.zip'))->toBe('upload_too_large')
        ->and(floorOutcome($policy, floorZip(['a.txt' => str_repeat('a', 1000)]), 'a.zip'))->toBe('accepted');
});

it('fails closed on a zero or negative archive budget', function (ArrayUploadPolicy $policy): void {
    expect(floorOutcome($policy, floorZip(['a.txt' => 'x']), 'a.zip'))->toBe('upload_too_large');
})->with([
    'negative ratio' => [new ArrayUploadPolicy(allowsArchives: true, maxUncompressedRatio: -1)],
    'zero ceiling' => [new ArrayUploadPolicy(allowsArchives: true, maxUncompressedRatio: 10, maxUncompressedBytes: 0)],
    'negative ceiling' => [new ArrayUploadPolicy(allowsArchives: true, maxUncompressedBytes: -5)],
]);

it('refuses archive entries whose names escape or hide', function (string $entryName): void {
    expect(floorOutcome(new ArrayUploadPolicy(allowsArchives: true, maxUncompressedRatio: 10), floorZip([$entryName => 'x']), 'a.zip'))->toBe('upload_forbidden_type');
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

    expect(floorOutcome(new ArrayUploadPolicy(allowsArchives: true, maxUncompressedRatio: 10), $zipPath, 'a.zip'))->toBe('accepted');
});

it('puts the previous error handler back after measuring an archive', function (): void {
    floorOutcome(new ArrayUploadPolicy(allowsArchives: true, maxUncompressedRatio: 10), floorZip(['a.txt' => 'x']), 'a.zip');

    expect(fn () => trigger_error('unrelated warning', E_USER_WARNING))->toThrow(ErrorException::class, 'unrelated warning');
});

/**
 * Hand-built ZIP for structures ZipArchive will not write: local headers the central
 * directory omits or contradicts, Unix modes, data descriptors, ZIP64 end records,
 * bytes before, between or after the records. Offsets in the directory always point
 * at the real local headers, so libzip opens what a careful attacker would send.
 *
 * @param  list<array{name: string, data: string, localName?: string, isListed?: bool, unixMode?: int, hasDataDescriptor?: bool, hasDescriptorSignature?: bool, hasZip64Descriptor?: bool, localExtra?: string, zip64DirectoryFields?: string, directoryExtraBefore?: string}>  $entries
 * @param  array{comment?: string, afterEnd?: string, leadingBytes?: string, beforeDirectory?: string, isZip64?: bool, beforeZip64Record?: string, beforeZip64Locator?: string, zip64RecordBytes?: int}  $layout
 */
function craftedZipBytes(array $entries, array $layout = []): string
{
    $localSection = $layout['leadingBytes'] ?? '';
    $centralSection = '';
    $listedCount = 0;

    foreach ($entries as $entry) {
        $compressed = (string) gzdeflate($entry['data']);
        $crc = crc32($entry['data']);
        $hasDataDescriptor = $entry['hasDataDescriptor'] ?? false;
        $localExtra = $entry['localExtra'] ?? '';
        $flags = $hasDataDescriptor ? 0x08 : 0;
        $localName = $entry['localName'] ?? $entry['name'];
        $localOffset = strlen($localSection);
        // With a data descriptor the local header carries zeros and the sizes follow the data.
        $localSizes = $hasDataDescriptor ? pack('VVV', 0, 0, 0) : pack('VVV', $crc, strlen($compressed), strlen($entry['data']));

        $localSection .= "PK\x03\x04".pack('vvvvv', 20, $flags, 8, 0, 0).$localSizes
            .pack('vv', strlen($localName), strlen($localExtra)).$localName.$localExtra.$compressed;

        if ($hasDataDescriptor) {
            $isZip64Descriptor = $entry['hasZip64Descriptor'] ?? false;
            $localSection .= ($entry['hasDescriptorSignature'] ?? true ? "PK\x07\x08" : '').pack('V', $crc)
                .($isZip64Descriptor ? pack('PP', strlen($compressed), strlen($entry['data'])) : pack('VV', strlen($compressed), strlen($entry['data'])));
        }

        if (! ($entry['isListed'] ?? true)) {
            continue;
        }

        $unixMode = $entry['unixMode'] ?? null;
        // ZIP64 in the directory: the maxed 32-bit fields move, in order, to extra field 0x0001.
        $zip64Fields = $entry['zip64DirectoryFields'] ?? 'none';
        $hasZip64Sizes = $zip64Fields === 'sizes and offset';
        $directoryExtra = ($entry['directoryExtraBefore'] ?? '').match ($zip64Fields) {
            'none' => '',
            'offset' => pack('vvP', 0x0001, 8, $localOffset),
            'sizes and offset' => pack('vvPPP', 0x0001, 24, strlen($entry['data']), strlen($compressed), $localOffset),
            'truncated' => pack('vv', 0x0001, 0),
            'missing' => ZIP_TIMESTAMP_EXTRA,
            // Three bytes too few for another field header.
            'dangling' => ZIP_TIMESTAMP_EXTRA."\0\0\0",
            // Claims 8 bytes of offset but carries 7.
            'short' => pack('vv', 0x0001, 8).str_repeat("\0", 7),
        };
        $listedCount++;
        $centralSection .= "PK\x01\x02".pack('vvvvvv', $unixMode === null ? 45 : (3 << 8) | 45, 45, $flags, 8, 0, 0)
            .pack('VVV', $crc, $hasZip64Sizes ? 0xFFFF_FFFF : strlen($compressed), $hasZip64Sizes ? 0xFFFF_FFFF : strlen($entry['data']))
            .pack('vvvvv', strlen($entry['name']), strlen($directoryExtra), 0, 0, 0)
            .pack('VV', $unixMode === null ? 0 : $unixMode << 16, $zip64Fields === 'none' ? $localOffset : 0xFFFF_FFFF).$entry['name'].$directoryExtra;
    }

    $localSection .= $layout['beforeDirectory'] ?? '';
    $directoryOffset = strlen($localSection);
    $comment = $layout['comment'] ?? '';
    $zip64Records = '';

    if ($layout['isZip64'] ?? false) {
        $centralSection .= $layout['beforeZip64Record'] ?? '';
        $zip64RecordAt = $directoryOffset + strlen($centralSection);
        $recordBytes = $layout['zip64RecordBytes'] ?? 56;
        $directoryBytes = strlen($centralSection) - strlen($layout['beforeZip64Record'] ?? '');
        // A record shorter than the 56-byte minimum is cut, with a size field that agrees.
        $zip64Records = substr("PK\x06\x06".pack('PvvVVPPPP', $recordBytes - 12, 45, 45, 0, 0, $listedCount, $listedCount, $directoryBytes, $directoryOffset)
            .str_repeat("\0", max(0, $recordBytes - 56)), 0, $recordBytes)
            .($layout['beforeZip64Locator'] ?? '')
            ."PK\x06\x07".pack('VPV', 0, $zip64RecordAt, 1);
        $endRecord = "PK\x05\x06".pack('vvvvVVv', 0, 0, 0xFFFF, 0xFFFF, 0xFFFF_FFFF, 0xFFFF_FFFF, strlen($comment));
    } else {
        $endRecord = "PK\x05\x06".pack('vvvvVVv', 0, 0, $listedCount, $listedCount, strlen($centralSection), $directoryOffset, strlen($comment));
    }

    return $localSection.$centralSection.$zip64Records.$endRecord.$comment.($layout['afterEnd'] ?? '');
}

/**
 * @param  list<array{name: string, data: string, localName?: string, isListed?: bool, unixMode?: int, hasDataDescriptor?: bool, hasDescriptorSignature?: bool, localExtra?: string}>  $entries
 * @param  array{comment?: string, afterEnd?: string, leadingBytes?: string, beforeDirectory?: string, isZip64?: bool, beforeZip64Locator?: string, zip64RecordBytes?: int}  $layout
 */
function craftedZip(array $entries, array $layout = []): string
{
    return floorFile(craftedZipBytes($entries, $layout));
}

it('accepts a hand-built archive whose local headers match its central directory', function (bool $hasDataDescriptor): void {
    $zipPath = craftedZip([
        ['name' => 'a.txt', 'data' => 'hello', 'hasDataDescriptor' => $hasDataDescriptor],
        ['name' => 'b.txt', 'data' => 'world', 'hasDataDescriptor' => $hasDataDescriptor],
    ]);

    expect(floorOutcome(new ArrayUploadPolicy(maxUncompressedRatio: 10, allowsArchives: true), $zipPath, 'a.zip'))->toBe('accepted');
})->with([
    'sizes in the local header' => [false],
    'sizes in a data descriptor' => [true],
]);

it('refuses a bomb hidden in a local header the central directory never lists (re-review B1)', function (): void {
    // Streaming readers (bsdtar from a pipe, ZipInputStream) walk local headers and inflate b.txt.
    $zipPath = craftedZip([
        ['name' => 'b.txt', 'data' => str_repeat("\0", 5 * 1024 * 1024), 'isListed' => false],
        ['name' => 'a.txt', 'data' => 'hello'],
    ]);
    $policy = new ArrayUploadPolicy(maxUncompressedRatio: 100, maxUncompressedBytes: 1024 * 1024, allowsArchives: true);

    expect(floorOutcome($policy, $zipPath, 'a.zip'))->toBe('upload_content_mismatch');
});

it('refuses a local header name that contradicts the central directory (re-review B1)', function (): void {
    $zipPath = craftedZip([['name' => 'xx/evil.txt', 'localName' => '../evil.txt', 'data' => 'x']]);

    expect(floorOutcome(new ArrayUploadPolicy(maxUncompressedRatio: 10, allowsArchives: true), $zipPath, 'a.zip'))->toBe('upload_content_mismatch');
});

it('refuses symbolic link entries (re-review N1)', function (): void {
    $zipPath = craftedZip([
        ['name' => 'd', 'data' => '/etc', 'unixMode' => 0o120777],
        ['name' => 'd/pwned.txt', 'data' => 'x'],
    ]);

    expect(floorOutcome(new ArrayUploadPolicy(maxUncompressedRatio: 10, allowsArchives: true), $zipPath, 'a.zip'))->toBe('upload_forbidden_type');
});

it('keeps regular Unix files and directories', function (): void {
    $zipPath = craftedZip([
        ['name' => 'docs/', 'data' => '', 'unixMode' => 0o040755],
        ['name' => 'docs/a.txt', 'data' => 'x', 'unixMode' => 0o100644],
    ]);

    expect(floorOutcome(new ArrayUploadPolicy(maxUncompressedRatio: 10, allowsArchives: true), $zipPath, 'a.zip'))->toBe('accepted');
});

it('refuses a second end-of-central-directory record (re-review N4)', function (string $layout): void {
    $honest = (string) file_get_contents(craftedZip([['name' => 'a.txt', 'data' => 'hello']]));
    $endRecord = substr($honest, -22);
    $bytes = $layout === 'appended'
        ? $honest.$endRecord
        // The comment hides a second record; its length field now covers 22 more bytes.
        : substr($honest, 0, -2).pack('v', 22).$endRecord;

    expect(floorOutcome(new ArrayUploadPolicy(maxUncompressedRatio: 10, allowsArchives: true), floorFile($bytes), 'a.zip'))->toBe('upload_content_mismatch');
})->with(['appended', 'inside the comment']);

it('does not mistake a stray end-of-directory signature for a hidden archive (re-review N3)', function (): void {
    // No central directory sits where the record says, so no ZIP reader can open this.
    $blob = floorFile(str_repeat("\xAB", 4096)."PK\x05\x06".str_repeat("\xCD", 4096));

    expect(floorOutcome(new ArrayUploadPolicy(maxUncompressedRatio: 10), $blob, 'blob.bin'))->toBe('accepted');
});

it('refuses archives unless the context explicitly allows them', function (string $clientName): void {
    $policy = new ArrayUploadPolicy(maxUncompressedRatio: 10);

    expect(floorOutcome($policy, floorZip(['a.txt' => 'hello']), $clientName))->toBe('upload_forbidden_type');
})->with(['bundle.zip', 'report.docx', 'sheet.xlsx', 'misnamed.txt']);

it('refuses compressed and archive suffixes by name unless archives are allowed', function (string $clientName): void {
    expect(floorOutcome(new ArrayUploadPolicy, floorFile('plain text'), $clientName))->toBe('upload_forbidden_type')
        ->and(floorOutcome(new ArrayUploadPolicy(allowsArchives: true), floorFile('plain text'), $clientName))->toBe('accepted');
})->with(['a.zip', 'a.7z', 'a.rar', 'a.tar.gz', 'a.tgz', 'a.docx', 'a.odt', 'a.epub', 'a.apk']);

/**
 * Asks the verifier alone about the bytes, with the entry list libzip reads (no
 * CHECKCONS) from `$openedBytes`: the same bytes, or an intact twin when libzip
 * itself would refuse the broken ones.
 */
function zipLayoutCovers(string $bytes, ?string $openedBytes = null): bool
{
    $path = floorFile($bytes);
    $openedPath = floorFile($openedBytes ?? $bytes);
    $archive = new ZipArchive;
    expect($archive->open($openedPath, ZipArchive::RDONLY))->toBeTrue();

    try {
        return (new ZipLayoutVerifier)->coversWholeFile($path, strlen($bytes), $archive);
    } finally {
        $archive->close();
        @unlink($path);
        @unlink($openedPath);
    }
}

const ZIP_TIMESTAMP_EXTRA = "\x55\x54\x05\x00\x01\x00\x00\x00\x00";
const ZIP64_EXTRA = "\x01\x00\x10\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";

it('accepts local headers that tile the file up to the central directory', function (array $entries, array $layout): void {
    expect(zipLayoutCovers(craftedZipBytes($entries, $layout)))->toBeTrue();
})->with([
    'one entry' => [[['name' => 'a.txt', 'data' => 'hello']], []],
    'an archive comment' => [[['name' => 'a.txt', 'data' => 'hello']], ['comment' => 'release 1.0']],
    'the longest comment' => [[['name' => 'a.txt', 'data' => 'hello']], ['comment' => str_repeat('c', 65_535)]],
    'descriptors with and without signature' => [[
        ['name' => 'a.txt', 'data' => 'hello', 'hasDataDescriptor' => true],
        ['name' => 'b.txt', 'data' => 'world', 'hasDataDescriptor' => true, 'hasDescriptorSignature' => false],
    ], []],
    'a 32-bit descriptor after a non-ZIP64 extra' => [[
        ['name' => 'a.txt', 'data' => 'hello', 'hasDataDescriptor' => true, 'localExtra' => ZIP_TIMESTAMP_EXTRA],
    ], []],
    'a ZIP64 descriptor after another extra field' => [[
        ['name' => 'a.txt', 'data' => 'hello', 'hasDataDescriptor' => true, 'hasZip64Descriptor' => true, 'localExtra' => ZIP_TIMESTAMP_EXTRA.ZIP64_EXTRA],
    ], []],
    'a ZIP64 descriptor after an empty ZIP64 extra' => [[
        ['name' => 'a.txt', 'data' => 'hello', 'hasDataDescriptor' => true, 'hasZip64Descriptor' => true, 'localExtra' => "\x01\x00\x00\x00"],
    ], []],
    'ZIP64 end records' => [[['name' => 'a.txt', 'data' => 'hello']], ['isZip64' => true]],
    'a ZIP64 record with extensible data' => [[['name' => 'a.txt', 'data' => 'hello']], ['isZip64' => true, 'zip64RecordBytes' => 60]],
]);

it('refuses bytes a streaming reader would see but the central directory skips', function (array $entries, array $layout): void {
    expect(zipLayoutCovers(craftedZipBytes($entries, $layout)))->toBeFalse();
})->with([
    'an unlisted local header' => [[['name' => 'b.txt', 'data' => 'hidden', 'isListed' => false], ['name' => 'a.txt', 'data' => 'hello']], []],
    'leading bytes' => [[['name' => 'a.txt', 'data' => 'hello']], ['leadingBytes' => '%PDF-1.4 '.str_repeat('x', 64)]],
    'a gap before the central directory' => [[['name' => 'a.txt', 'data' => 'hello']], ['beforeDirectory' => str_repeat("\0", 16)]],
    'bytes after the end record' => [[['name' => 'a.txt', 'data' => 'hello']], ['afterEnd' => 'trailing']],
    'a short signature after the end record' => [[['name' => 'a.txt', 'data' => 'hello']], ['afterEnd' => "PK\x05\x06".str_repeat("\0", 17)]],
    'a ZIP64 descriptor sized as 32-bit' => [[['name' => 'a.txt', 'data' => 'hello', 'hasDataDescriptor' => true, 'localExtra' => ZIP64_EXTRA]], []],
    'a 32-bit descriptor sized as ZIP64' => [[['name' => 'a.txt', 'data' => 'hello', 'hasDataDescriptor' => true, 'hasZip64Descriptor' => true, 'localExtra' => ZIP_TIMESTAMP_EXTRA]], []],
    'a gap before the ZIP64 record' => [[['name' => 'a.txt', 'data' => 'hello']], ['isZip64' => true, 'beforeZip64Record' => 'gap']],
    'a gap before the ZIP64 locator' => [[['name' => 'a.txt', 'data' => 'hello']], ['isZip64' => true, 'beforeZip64Locator' => 'gap']],
]);

it('refuses a ZIP64 record under the 56-byte minimum', function (): void {
    $entries = [['name' => 'a.txt', 'data' => 'hello']];

    expect(zipLayoutCovers(
        craftedZipBytes($entries, ['isZip64' => true, 'zip64RecordBytes' => 55]),
        craftedZipBytes($entries, ['isZip64' => true]),
    ))->toBeFalse();
});

it('refuses ZIP64 end records that do not chain', function (string $signature, string $replacement): void {
    $bytes = craftedZipBytes([['name' => 'a.txt', 'data' => 'hello']], ['isZip64' => true]);

    expect(zipLayoutCovers(str_replace($signature, $replacement, $bytes), $bytes))->toBeFalse();
})->with([
    'no locator' => ["PK\x06\x07", 'PKxx'],
    'no ZIP64 record' => ["PK\x06\x06", 'PKxx'],
]);

it('stops at a local header cut short by the end of the file', function (): void {
    // The directory claims a long first entry, so the second header would start past the end.
    $twin = craftedZipBytes([['name' => 'a.txt', 'data' => random_bytes(5000)], ['name' => 'b.txt', 'data' => 'x']]);
    $short = craftedZipBytes([['name' => 'a.txt', 'data' => 'hello'], ['name' => 'b.txt', 'data' => 'x']]);

    expect(zipLayoutCovers($short, $twin))->toBeFalse();
});

/**
 * Asks the verifier whether a reader that tolerates leading data would open the bytes.
 */
function zipTailIsReadable(string $bytes): bool
{
    $path = floorFile($bytes);

    try {
        return (new ZipLayoutVerifier)->hasReadableEndRecord($path, strlen($bytes));
    } finally {
        @unlink($path);
    }
}

it('finds an archive a tolerant reader would open behind leading data', function (string $bytes): void {
    expect(zipTailIsReadable($bytes))->toBeTrue();
})->with([
    'archive after text' => ["notes\n".craftedZipBytes([['name' => 'a.txt', 'data' => 'hello']])],
    'archive, then a stray signature' => ["notes\n".craftedZipBytes([['name' => 'a.txt', 'data' => 'hello']])."junk PK\x05\x06 junk"],
    'ZIP64 directory size' => ["notes\nPK\x05\x06".pack('vvvvVVv', 0, 0, 0, 0, 0xFFFF_FFFF, 0, 0)],
    'ZIP64 directory offset' => ["notes\nPK\x05\x06".pack('vvvvVVv', 0, 0, 0, 0, 0, 0xFFFF_FFFF, 0)],
    'ZIP64 record at the first byte' => ["PK\x05\x06".pack('vvvvVVv', 0, 0, 0, 0, 0xFFFF_FFFF, 0xFFFF_FFFF, 0).'tail'],
]);

it('ignores end-of-directory signatures no reader could follow', function (string $bytes): void {
    expect(zipTailIsReadable($bytes))->toBeFalse();
})->with([
    'no signature' => ['plain text'],
    'a stray signature' => [str_repeat('a', 100)."PK\x05\x06".str_repeat('b', 100)],
    'a signature cut short by the end of the file' => [str_repeat('a', 100)."PK\x05\x06".str_repeat("\0", 17)],
    'an empty directory' => ["notes\nPK\x05\x06".pack('vvvvVVv', 0, 0, 0, 0, 0, 0, 0)],
]);

function zipLocalHeader(string $name, int $method, int $flags, int $crc, int $compressedBytes, int $uncompressedBytes, string $extra = ''): string
{
    return "PK\x03\x04".pack('vvvvvVVVvv', 20, $flags, $method, 0, 0, $crc, $compressedBytes, $uncompressedBytes, strlen($name), strlen($extra)).$name.$extra;
}

function zipCentralHeader(string $name, int $method, int $flags, int $crc, int $compressedBytes, int $uncompressedBytes, int $localOffset): string
{
    return "PK\x01\x02".pack('vvvvvvVVVvvvvvVV', 20, 20, $flags, $method, 0, 0, $crc, $compressedBytes, $uncompressedBytes, strlen($name), 0, 0, 0, 0, 0, $localOffset).$name;
}

function zipEndRecord(int $entries, int $directoryBytes, int $directoryOffset): string
{
    return "PK\x05\x06".pack('vvvvVVv', 0, 0, $entries, $entries, $directoryBytes, $directoryOffset, 0);
}

/**
 * Re-review 4, B1: the listed entry's local header hides inside the extra field of an
 * unlisted one, so walking header lengths still lands on the central directory.
 */
function zipWithHeaderInsideExtraField(string $hiddenName): string
{
    $bomb = str_repeat("\0", 5 * 1024 * 1024);
    $compressedBomb = (string) gzdeflate($bomb);
    $payload = random_bytes(strlen($compressedBomb));
    $listedHeader = zipLocalHeader('b.txt', 0, 0, crc32($payload), strlen($payload), strlen($payload));
    $extra = pack('vv', 0x7777, strlen($listedHeader) + strlen($payload)).$listedHeader.$payload;
    $body = zipLocalHeader($hiddenName, 8, 0, crc32($bomb), strlen($compressedBomb), strlen($bomb), $extra).$compressedBomb;
    $directory = zipCentralHeader('b.txt', 0, 0, crc32($payload), strlen($payload), strlen($payload), 30 + strlen($hiddenName) + 4);

    return $body.$directory.zipEndRecord(1, strlen($directory), strlen($body));
}

/**
 * Re-review 4, B2: a stored entry with a data descriptor ends, for a streaming reader,
 * at the first descriptor that matches; a full local header hides after it.
 */
function zipWithStoredDescriptorSplit(): string
{
    $bomb = str_repeat("\0", 5 * 1024 * 1024);
    $compressedBomb = (string) gzdeflate($bomb);
    $hidden = zipLocalHeader('h.bin', 8, 0, crc32($bomb), strlen($compressedBomb), strlen($bomb)).$compressedBomb;
    $data = 'AAAA'."PK\x07\x08".pack('VVV', crc32('AAAA'), 4, 4).$hidden;
    $descriptor = "PK\x07\x08".pack('VVV', crc32($data), strlen($data), strlen($data));
    $body = zipLocalHeader('a.txt', 0, 0x08, 0, 0, 0).$data.$descriptor;
    $directory = zipCentralHeader('a.txt', 0, 0x08, crc32($data), strlen($data), strlen($data), 0);

    return $body.$directory.zipEndRecord(1, strlen($directory), strlen($body));
}

it('refuses what a streaming reader would extract but libzip never lists (re-review 4)', function (string $bytes): void {
    $policy = new ArrayUploadPolicy(maxUncompressedRatio: 100, maxUncompressedBytes: 1024 * 1024, allowsArchives: true);

    expect(floorOutcome($policy, floorFile($bytes), 'a.zip'))->toBe('upload_content_mismatch');
})->with([
    'B1: a header inside an extra field' => [fn (): string => zipWithHeaderInsideExtraField('x.bin')],
    'B1: a traversal name inside an extra field' => [fn (): string => zipWithHeaderInsideExtraField('../evil.txt')],
    'B2: a stored entry split by a fake descriptor' => [fn (): string => zipWithStoredDescriptorSplit()],
]);

it('refuses a data descriptor that disagrees with the central directory', function (): void {
    $bytes = craftedZipBytes([['name' => 'a.txt', 'data' => 'hello', 'hasDataDescriptor' => true]]);
    $descriptorAt = (int) strpos($bytes, "PK\x07\x08");
    // Same CRC, a compressed size one byte larger than the directory says.
    $forged = substr_replace($bytes, pack('V', unpack('V', $bytes, $descriptorAt + 8)[1] + 1), $descriptorAt + 8, 4);

    expect(floorOutcome(new ArrayUploadPolicy(maxUncompressedRatio: 10, allowsArchives: true), floorFile($forged), 'a.zip'))->toBe('upload_content_mismatch');
});

it('refuses archives even when the context turned archive inspection off (re-review 4 N2)', function (): void {
    $pdfPrefix = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
    $adjustedPolyglot = floorFile(craftedZipBytes([['name' => 'payload.html', 'data' => '<script>x</script>']], ['leadingBytes' => $pdfPrefix]));
    $zipPath = floorZip(['payload.html' => '<script>x</script>']);
    $appendedPolyglot = floorFile($pdfPrefix.file_get_contents($zipPath));
    @unlink($zipPath);

    expect(floorOutcome(new ArrayUploadPolicy, $adjustedPolyglot, 'report.pdf'))->toBe('upload_forbidden_type')
        ->and(floorOutcome(new ArrayUploadPolicy, $appendedPolyglot, 'report.pdf'))->toBe('upload_content_mismatch');
});

it('refuses other archive formats finfo recognises (re-review 4 N3)', function (string $bytes): void {
    expect(floorOutcome(new ArrayUploadPolicy, floorFile($bytes), 'x.dat'))->toBe('upload_forbidden_type');
})->with([
    'deb' => ["!<arch>\ndebian-binary   1342943816  0     0     100644  4         `\n2.0\n"],
    'rpm' => ["\xED\xAB\xEE\xDB\x03\x00\x00\x00\x00\x01".str_repeat("\0", 200)],
    'lz4' => ["\x04\x22\x4D\x18".str_repeat("\0", 60)],
    'lzh' => ["\x00\x00-lh5-".str_repeat("\0", 60)],
    'wim' => ["MSWIM\0\0\0".str_repeat("\0", 200)],
    'warc' => ["WARC/1.0\r\nWARC-Type: warcinfo\r\n\r\n"],
    'iso' => [str_repeat("\0", 0x8001).'CD001'.str_repeat("\0", 3000)],
]);

it('follows ZIP64 local header offsets in the central directory', function (string $zip64Fields, string $extraBefore): void {
    $entries = [
        ['name' => 'a.txt', 'data' => 'hello'],
        ['name' => 'b.txt', 'data' => 'world', 'zip64DirectoryFields' => $zip64Fields, 'directoryExtraBefore' => $extraBefore],
    ];

    expect(zipLayoutCovers(craftedZipBytes($entries)))->toBeTrue();
})->with([
    'offset only' => ['offset', ''],
    'sizes, then offset' => ['sizes and offset', ''],
    'after another extra field' => ['offset', ZIP_TIMESTAMP_EXTRA],
]);

it('refuses a ZIP64 directory offset it cannot read', function (string $zip64Fields): void {
    $twin = craftedZipBytes([['name' => 'a.txt', 'data' => 'hello', 'zip64DirectoryFields' => 'offset']]);
    $broken = craftedZipBytes([['name' => 'a.txt', 'data' => 'hello', 'zip64DirectoryFields' => $zip64Fields]]);

    // The directory says "offset in ZIP64 extra", but the extra field does not hold it.
    expect(zipLayoutCovers($broken, $twin))->toBeFalse();
})->with(['no room' => ['truncated'], 'one byte short' => ['short'], 'no ZIP64 field' => ['missing'], 'a dangling field header' => ['dangling']]);

it('refuses a central directory with fewer entries than libzip listed', function (): void {
    $twin = craftedZipBytes([['name' => 'a.txt', 'data' => 'hello'], ['name' => 'b.txt', 'data' => 'world']]);

    expect(zipLayoutCovers(craftedZipBytes([['name' => 'a.txt', 'data' => 'hello']]), $twin))->toBeFalse();
});

it('stops at the first data descriptor that disagrees, even before the last entry', function (): void {
    $bytes = craftedZipBytes([
        ['name' => 'a.txt', 'data' => 'hello', 'hasDataDescriptor' => true],
        ['name' => 'b.txt', 'data' => 'world'],
    ]);
    $descriptorAt = (int) strpos($bytes, "PK\x07\x08");
    $forged = substr_replace($bytes, pack('V', unpack('V', $bytes, $descriptorAt + 12)[1] + 1), $descriptorAt + 12, 4);

    expect(zipLayoutCovers($forged, $bytes))->toBeFalse();
});

it('refuses a central directory with bytes left after its last entry', function (): void {
    $bytes = craftedZipBytes([['name' => 'a.txt', 'data' => 'hello']]);
    $endRecordAt = strlen($bytes) - 22;
    // One spare byte inside the directory: the end record now claims one byte more.
    $padded = substr($bytes, 0, $endRecordAt)."\0".substr_replace(substr($bytes, $endRecordAt), pack('V', unpack('V', $bytes, $endRecordAt + 12)[1] + 1), 12, 4);

    expect(zipLayoutCovers($padded, $bytes))->toBeFalse();
});
