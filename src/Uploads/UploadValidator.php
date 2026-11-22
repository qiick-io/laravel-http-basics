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
            throw UploadRejectedException::nameInvalid($clientName);
        }

        $safeName = $this->names->sanitize($clientName);
        $policy = $this->policies->forContext($context);

        if ($this->hardFloor->isBlocked($safeName)) {
            throw UploadRejectedException::forbiddenType($safeName);
        }

        if ($this->hardFloor->isSvg($safeName) && ! $policy->allowsSvg()) {
            throw UploadRejectedException::forbiddenType($safeName);
        }

        $suffixes = $this->hardFloor->suffixes($safeName);
        // Null when the name has no suffix: no allowlist accepts it and no magic signature applies.
        $clientExtension = array_pop($suffixes);
        $allowedExtensions = $this->lowercased($policy->allowedExtensions());

        if ($allowedExtensions !== null && ! in_array($clientExtension, $allowedExtensions, true)) {
            throw UploadRejectedException::typeNotAllowed($safeName);
        }

        $sizeBytes = filesize($absolutePath);
        $maxBytes = $policy->maxBytes();

        if (! is_int($sizeBytes)) {
            throw UploadRejectedException::nameInvalid($clientName);
        }

        if ($maxBytes !== null && $sizeBytes > $maxBytes) {
            throw UploadRejectedException::tooLarge($safeName);
        }

        $this->assertMagic($absolutePath, $clientExtension === null ? [] : $policy->magicSignatures()[$clientExtension] ?? [], $safeName);

        $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath);

        if (! is_string($detectedMime)) {
            throw UploadRejectedException::contentMismatch($safeName);
        }

        if ($this->hardFloor->isBlockedMime($detectedMime, $policy->allowsSvg())) {
            throw UploadRejectedException::forbiddenType($safeName);
        }

        $allowedMimes = $this->lowercased($policy->allowedMimeTypes());

        if ($allowedMimes !== null && ! in_array($detectedMime, $allowedMimes, true)) {
            throw UploadRejectedException::typeNotAllowed($safeName);
        }

        $storageExtension = $this->mimeExtensions->extensionFor($detectedMime);

        if ($allowedExtensions !== null && ! in_array($storageExtension, $allowedExtensions, true)) {
            throw UploadRejectedException::typeNotAllowed($safeName);
        }

        if ($declaredMime !== null && $declaredMime !== '' && $policy->contentTypeMismatch() === MismatchMode::Reject) {
            if (strtolower(trim(explode(';', $declaredMime)[0])) !== $detectedMime) {
                throw UploadRejectedException::contentMismatch($safeName);
            }
        }

        $this->assertArchiveWithinBudget($absolutePath, $sizeBytes, $policy, $safeName);

        $scan = $this->scanner->scanPath($absolutePath);

        if ($scan === ScanResult::Infected) {
            throw UploadRejectedException::infected($safeName);
        }

        if ($scan === ScanResult::Failed) {
            throw UploadRejectedException::scanFailed($safeName);
        }

        $sha256 = hash_file('sha256', $absolutePath);

        if (! is_string($sha256)) {
            throw UploadRejectedException::nameInvalid($clientName);
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
    private function assertMagic(string $absolutePath, array $signatures, string $safeName): void
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

        throw UploadRejectedException::contentMismatch($safeName);
    }

    /**
     * Inflates every ZIP entry in streaming and counts the real bytes, because the
     * sizes in the local and central headers are attacker-controlled. Reading stops
     * at the budget (`size × max_uncompressed_ratio`, capped by
     * `max_uncompressed_bytes`), so a bomb is refused after at most budget + 1
     * inflated bytes. Any file libzip opens as an archive is measured, whatever its
     * sniffed MIME (docx, jar and PDF/ZIP polyglots included). Nested archives count
     * by their compressed bytes only; they are not opened.
     *
     * @throws UploadRejectedException `upload_too_large` over budget, `upload_content_mismatch`
     *                                 for an archive that cannot be read (corrupt or encrypted)
     */
    private function assertArchiveWithinBudget(string $absolutePath, int $sizeBytes, UploadPolicy $policy, string $safeName): void
    {
        $ratio = $policy->maxUncompressedRatio();

        if ($ratio === null) {
            return;
        }

        if (! class_exists(\ZipArchive::class)) {
            throw UploadRejectedException::forbiddenType($safeName);
        }

        $archive = new \ZipArchive;
        $openResult = $archive->open($absolutePath, \ZipArchive::RDONLY);

        if ($openResult === \ZipArchive::ER_NOZIP) {
            return;
        }

        if ($openResult !== true) {
            throw UploadRejectedException::contentMismatch($safeName);
        }

        // Refused before any entry is inflated, so a central directory of empty
        // entries cannot make the check itself slow.
        if ($archive->count() > 10_000) {
            throw UploadRejectedException::tooLarge($safeName);
        }

        $budget = min($sizeBytes * $ratio, $policy->maxUncompressedBytes() ?? PHP_INT_MAX);
        $inflatedBytes = 0;
        $byteCounter = hash_init('crc32b');

        // Each entry stream, and the read-only archive, is released as soon as it goes out of scope.
        for ($entryIndex = 0; $entryIndex < $archive->count(); $entryIndex++) {
            $entryStream = $archive->getStreamIndex($entryIndex);

            if (! is_resource($entryStream)) {
                throw UploadRejectedException::contentMismatch($safeName);
            }

            $inflatedBytes += hash_update_stream($byteCounter, $entryStream, $budget - $inflatedBytes);

            if (is_string(fgetc($entryStream))) {
                throw UploadRejectedException::tooLarge($safeName);
            }
        }
    }
}
