<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Uploads;

use Illuminate\Http\UploadedFile;
use Qiick\HttpBasics\Contracts\IdGenerator;

/**
 * @api Validates an UploadedFile or an assembled path against the upload floor.
 *
 * Enforces the hard floor (suffix + sniffed MIME), size, allowlists, magic
 * bytes, the archive inflation budget, and a package-owned storage extension
 * derived from the sniffed MIME (never the raw client suffix).
 */
final class UploadValidator
{
    public function __construct(
        private UploadPolicyResolver $policies,
        private FileNameSanitizer $names,
        private HardFloor $hardFloor,
        private MalwareScanner $scanner,
        private IdGenerator $ids,
        private DetectedMimeExtension $mimeExtensions = new DetectedMimeExtension,
        private ZipLayoutVerifier $zipLayouts = new ZipLayoutVerifier,
    ) {}

    /**
     * @throws UploadRejectedException when the file breaks the floor or the context policy
     */
    public function validate(UploadedFile $file, string $context, string $field = 'file'): ValidatedUpload
    {
        // A missing temporary file has no real path; `''` then fails the is_file guard.
        return $this->validatePath((string) $file->getRealPath(), $file->getClientOriginalName(), $context, $field, $file->getClientMimeType());
    }

    /**
     * Side effect: runs the bound MalwareScanner on the file and, for ZIP archives,
     * inflates every entry in streaming (nothing is written to disk).
     *
     * @throws UploadRejectedException when the file breaks the floor or the context policy
     */
    public function validatePath(
        string $absolutePath,
        string $clientName,
        string $context,
        string $field = 'file',
        ?string $declaredMime = null,
    ): ValidatedUpload {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw UploadRejectedException::nameInvalid();
        }

        $safeName = $this->names->sanitize($clientName);
        $policy = $this->policies->forContext($context);

        if ($this->hardFloor->isBlocked($safeName)) {
            throw UploadRejectedException::forbiddenType();
        }

        if ($this->hardFloor->isSvg($safeName) && ! $policy->allowsSvg()) {
            throw UploadRejectedException::forbiddenType();
        }

        if ($this->hardFloor->isArchive($safeName) && ! $policy->allowsArchives()) {
            throw UploadRejectedException::forbiddenType();
        }

        $suffixes = $this->hardFloor->suffixes($safeName);
        // Null when the name has no suffix: no allowlist accepts it and no magic signature applies.
        $clientExtension = array_pop($suffixes);
        $allowedExtensions = $this->lowercased($policy->allowedExtensions());

        if ($allowedExtensions !== null && ! in_array($clientExtension, $allowedExtensions, true)) {
            throw UploadRejectedException::typeNotAllowed();
        }

        $sizeBytes = filesize($absolutePath);
        $maxBytes = $policy->maxBytes();

        if (! is_int($sizeBytes)) {
            throw UploadRejectedException::nameInvalid();
        }

        if ($maxBytes !== null && $sizeBytes > $maxBytes) {
            throw UploadRejectedException::tooLarge();
        }

        $this->assertMagic($absolutePath, $clientExtension === null ? [] : $policy->magicSignatures()[$clientExtension] ?? []);

        $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath);

        if (! is_string($detectedMime)) {
            throw UploadRejectedException::contentMismatch();
        }

        if ($this->hardFloor->isBlockedMime($detectedMime, $policy->allowsSvg())) {
            throw UploadRejectedException::forbiddenType();
        }

        if ($this->hardFloor->isArchiveMime($detectedMime) && ! $policy->allowsArchives()) {
            throw UploadRejectedException::forbiddenType();
        }

        $allowedMimes = $this->lowercased($policy->allowedMimeTypes());

        if ($allowedMimes !== null && ! in_array($detectedMime, $allowedMimes, true)) {
            throw UploadRejectedException::typeNotAllowed();
        }

        $storageExtension = $this->mimeExtensions->extensionFor($detectedMime);

        if ($allowedExtensions !== null && ! in_array($storageExtension, $allowedExtensions, true)) {
            throw UploadRejectedException::typeNotAllowed();
        }

        if ($declaredMime !== null && $declaredMime !== '' && $policy->contentTypeMismatch() === MismatchMode::Reject) {
            if (strtolower(trim(explode(';', $declaredMime)[0])) !== $detectedMime) {
                throw UploadRejectedException::contentMismatch();
            }
        }

        $this->assertArchiveWithinBudget($absolutePath, $sizeBytes, $policy);

        $scan = $this->scanner->scanPath($absolutePath);

        if ($scan === ScanResult::Infected) {
            throw UploadRejectedException::infected();
        }

        if ($scan === ScanResult::Failed) {
            throw UploadRejectedException::scanFailed();
        }

        $sha256 = hash_file('sha256', $absolutePath);

        if (! is_string($sha256)) {
            throw UploadRejectedException::nameInvalid();
        }

        $publicId = $this->ids->generate();

        return new ValidatedUpload(
            $publicId,
            $clientName,
            $safeName,
            $storageExtension === null ? $publicId : $publicId.'.'.$storageExtension,
            $storageExtension ?? '',
            $declaredMime,
            $detectedMime,
            'finfo',
            // ext-fileinfo is bundled with PHP, so its version is the PHP version.
            PHP_VERSION,
            $sizeBytes,
            $sha256,
            $scan,
        );
    }

    /**
     * @param  list<string>|null  $values
     * @return list<string>|null
     */
    private function lowercased(?array $values): ?array
    {
        return $values === null ? null : array_map(strtolower(...), $values);
    }

    /**
     * Each signature is a hex prefix; spaces and letter case are ignored. An empty
     * signature is skipped, because the empty prefix would match every file.
     *
     * @param  list<string>  $signatures
     */
    private function assertMagic(string $absolutePath, array $signatures): void
    {
        if ($signatures === []) {
            return;
        }

        foreach ($signatures as $signature) {
            $expectedHex = strtoupper(str_replace(' ', '', $signature));
            $prefix = file_get_contents($absolutePath, length: intdiv(strlen($expectedHex), 2));

            if ($expectedHex !== '' && is_string($prefix) && strtoupper(bin2hex($prefix)) === $expectedHex) {
                return;
            }
        }

        throw UploadRejectedException::contentMismatch();
    }

    /**
     * Inflates every ZIP entry in streaming and counts the real bytes, because the
     * sizes in the local and central headers are attacker-controlled. Reading stops
     * at the budget (`size × max_uncompressed_ratio`, capped by
     * `max_uncompressed_bytes`; either limit alone applies, and a zero or negative
     * limit allows nothing), so a bomb is refused after at most budget + 1 inflated
     * bytes. Any file libzip opens as an archive is measured, whatever its sniffed
     * MIME (docx, jar and PDF/ZIP polyglots included); a file libzip cannot open but
     * that still ends in a ZIP directory is refused. Entry names that climb out of
     * the extraction directory are refused. Nested archives count by their
     * compressed bytes only; they are not opened.
     *
     * @throws UploadRejectedException `upload_too_large` over budget, `upload_content_mismatch`
     *                                 for an archive that cannot be read (corrupt, bad CRC,
     *                                 encrypted, hidden behind leading data), `upload_forbidden_type`
     *                                 for an unsafe entry name
     */
    private function assertArchiveWithinBudget(string $absolutePath, int $sizeBytes, UploadPolicy $policy): void
    {
        $ratio = $policy->maxUncompressedRatio();
        $ceiling = $policy->maxUncompressedBytes();

        // Both limits null turns inspection off only where archives are allowed: a
        // context that refuses them must still catch a ZIP behind another format.
        if ($ratio === null && $ceiling === null && $policy->allowsArchives()) {
            return;
        }

        if (! class_exists(\ZipArchive::class)) {
            throw UploadRejectedException::forbiddenType();
        }

        $archive = new \ZipArchive;
        // CHECKCONS refuses a local header that contradicts the central directory.
        $openResult = $archive->open($absolutePath, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS);

        if ($openResult === \ZipArchive::ER_NOZIP) {
            $this->assertNoHiddenArchive($absolutePath, $sizeBytes);

            return;
        }

        if ($openResult !== true) {
            throw UploadRejectedException::contentMismatch();
        }

        // Catches what the name and the sniffed MIME missed, a PDF+ZIP polyglot included.
        if (! $policy->allowsArchives()) {
            throw UploadRejectedException::forbiddenType();
        }

        // Refused before any entry is inflated, so a central directory of empty
        // entries cannot make the check itself slow.
        if ($archive->count() > 10_000) {
            throw UploadRejectedException::tooLarge();
        }

        $budget = max(0, min($ratio === null ? PHP_INT_MAX : $sizeBytes * $ratio, $ceiling ?? PHP_INT_MAX));
        $inflatedBytes = 0;
        $byteCounter = hash_init('crc32b');

        // libzip reports a broken deflate stream or a CRC mismatch as a warning while
        // reading; it is a refusal, not a server error.
        set_error_handler(static function (): never {
            throw UploadRejectedException::contentMismatch();
        });

        try {
            // Each entry stream, and the read-only archive, is released as soon as it goes out of scope.
            for ($entryIndex = 0; $entryIndex < $archive->count(); $entryIndex++) {
                // Raw bytes: libzip would otherwise decode a non-UTF-8 name from CP437 and hide its controls.
                $entryName = $archive->getNameIndex($entryIndex, \ZipArchive::FL_ENC_RAW);

                if (! is_string($entryName) || ! $this->isSafeEntryName($entryName) || $this->isSymbolicLink($archive, $entryIndex)) {
                    throw UploadRejectedException::forbiddenType();
                }

                $entryStream = $archive->getStreamIndex($entryIndex);

                if (! is_resource($entryStream)) {
                    throw UploadRejectedException::contentMismatch();
                }

                $inflatedBytes += hash_update_stream($byteCounter, $entryStream, $budget - $inflatedBytes);

                if (is_string(fgetc($entryStream))) {
                    throw UploadRejectedException::tooLarge();
                }
            }
        } finally {
            restore_error_handler();
        }

        if (! $this->zipLayouts->coversWholeFile($absolutePath, $sizeBytes, $archive)) {
            throw UploadRejectedException::contentMismatch();
        }
    }

    /**
     * A link entry lets a later entry write through it, outside the extraction directory.
     */
    private function isSymbolicLink(\ZipArchive $archive, int $entryIndex): bool
    {
        $archive->getExternalAttributesIndex($entryIndex, $operatingSystem, $attributes);

        return $operatingSystem === \ZipArchive::OPSYS_UNIX && is_int($attributes) && (($attributes >> 16) & 0o170000) === 0o120000;
    }

    /**
     * Zip slip: a `..` segment, an absolute or drive path, or a control character.
     * Backslashes count as separators, as Windows extractors treat them.
     */
    private function isSafeEntryName(string $entryName): bool
    {
        return preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)|^[/\\\\]|^[a-z]:|[\x00-\x1F\x7F]#i', $entryName) !== 1;
    }

    /**
     * Other ZIP readers (unzip, Python's zipfile) accept data in front of an archive
     * that libzip refuses, so a file whose tail holds a readable end-of-central-directory
     * record is refused rather than passed as "not an archive".
     */
    private function assertNoHiddenArchive(string $absolutePath, int $sizeBytes): void
    {
        if ($this->zipLayouts->hasReadableEndRecord($absolutePath, $sizeBytes)) {
            throw UploadRejectedException::contentMismatch();
        }
    }
}
