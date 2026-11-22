<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Uploads;

use Illuminate\Http\UploadedFile;
use Qiick\HttpBasics\Contracts\IdGenerator;

/**
 * @api Validates an UploadedFile or an assembled path against the upload floor.
 *
 * Enforces the hard floor (suffix + sniffed MIME), size, allowlists, magic
 * bytes, ZIP uncompressed ratio, and a package-owned storage extension derived
 * from the sniffed MIME (never the raw client suffix).
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

    public function validate(UploadedFile $file, string $context, string $field = 'file'): ValidatedUpload
    {
        $clientName = (string) $file->getClientOriginalName();
        $path = $file->getRealPath();

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw UploadRejectedException::nameInvalid($clientName);
        }

        return $this->validatePath($path, $clientName, $context, $field, $file->getClientMimeType());
    }

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
        $clientExtension = $suffixes === [] ? '' : $suffixes[array_key_last($suffixes)];
        $clientExtension = preg_match('/\A[a-z0-9]{1,10}\z/', $clientExtension) === 1 ? $clientExtension : '';

        $allowedExtensions = $policy->allowedExtensions();

        if ($allowedExtensions !== null) {
            $allowed = array_map(strtolower(...), $allowedExtensions);

            if ($clientExtension === '' || ! in_array($clientExtension, $allowed, true)) {
                throw UploadRejectedException::typeNotAllowed($safeName);
            }
        }

        $size = filesize($absolutePath);
        $sizeBytes = is_int($size) ? $size : 0;
        $maxBytes = $policy->maxBytes();

        if ($maxBytes !== null && $sizeBytes > $maxBytes) {
            throw UploadRejectedException::tooLarge($safeName);
        }

        $header = $this->readHeader($absolutePath);
        $this->assertMagic($clientExtension, $header, $policy, $safeName);

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($absolutePath);
        $detectedMime = is_string($detected) && $detected !== '' && $detected !== 'application/x-empty'
            ? strtolower(trim(explode(';', $detected, 2)[0]))
            : 'application/octet-stream';

        if ($this->hardFloor->isBlockedMime($detectedMime, $policy->allowsSvg())) {
            throw UploadRejectedException::forbiddenType($safeName);
        }

        $allowedMimes = $policy->allowedMimeTypes();

        if ($allowedMimes !== null && ! in_array($detectedMime, array_map(strtolower(...), $allowedMimes), true)) {
            throw UploadRejectedException::typeNotAllowed($safeName);
        }

        $storageExtension = $this->mimeExtensions->extensionFor($detectedMime);

        if ($allowedExtensions !== null) {
            $allowed = array_map(strtolower(...), $allowedExtensions);

            if ($storageExtension === null || ! in_array($storageExtension, $allowed, true)) {
                throw UploadRejectedException::typeNotAllowed($safeName);
            }
        }

        if ($declaredMime !== null && $declaredMime !== '') {
            $declared = strtolower(trim(explode(';', $declaredMime, 2)[0]));

            if ($declared !== $detectedMime && $policy->contentTypeMismatch() === MismatchMode::Reject) {
                throw UploadRejectedException::contentMismatch($safeName);
            }
        }

        $this->assertZipRatio($absolutePath, $detectedMime, $policy, $safeName);

        $scan = $this->scanner->scanPath($absolutePath);

        if ($scan === ScanResult::Infected) {
            throw UploadRejectedException::infected($safeName);
        }

        if ($scan === ScanResult::Failed) {
            throw UploadRejectedException::scanFailed($safeName);
        }

        $publicId = $this->ids->generate();
        $extension = $storageExtension ?? '';
        $storageName = $publicId.($extension !== '' ? '.'.$extension : '');
        $hash = hash_file('sha256', $absolutePath);

        return new ValidatedUpload(
            $publicId,
            $clientName,
            $safeName,
            $storageName,
            $extension,
            $declaredMime,
            $detectedMime,
            'finfo',
            phpversion('fileinfo') ?: '',
            $sizeBytes,
            is_string($hash) ? $hash : '',
            $scan,
        );
    }

    private function readHeader(string $absolutePath): string
    {
        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            return '';
        }

        try {
            $header = fread($handle, 16);

            return is_string($header) ? $header : '';
        } finally {
            fclose($handle);
        }
    }

    private function assertMagic(string $extension, string $header, UploadPolicy $policy, string $safeName): void
    {
        if ($extension === '') {
            return;
        }

        $signatures = $policy->magicSignatures()[$extension] ?? [];

        if ($signatures === []) {
            return;
        }

        $hex = strtoupper(bin2hex($header));

        foreach ($signatures as $signature) {
            $needle = strtoupper(str_replace(' ', '', $signature));

            if ($needle !== '' && str_starts_with($hex, $needle)) {
                return;
            }
        }

        throw UploadRejectedException::contentMismatch($safeName);
    }

    private function assertZipRatio(string $absolutePath, string $detectedMime, UploadPolicy $policy, string $safeName): void
    {
        $ratio = $policy->maxUncompressedRatio();

        if ($ratio === null || $ratio <= 0) {
            return;
        }

        if (! in_array($detectedMime, ['application/zip', 'application/x-zip-compressed'], true)) {
            return;
        }

        if (! class_exists(\ZipArchive::class)) {
            throw UploadRejectedException::forbiddenType($safeName);
        }

        $archive = new \ZipArchive;
        $opened = $archive->open($absolutePath);

        if ($opened !== true) {
            throw UploadRejectedException::contentMismatch($safeName);
        }

        try {
            $compressedSize = filesize($absolutePath);
            $compressedBytes = is_int($compressedSize) && $compressedSize > 0 ? $compressedSize : 1;
            $uncompressedTotal = 0;
            $entryCount = $archive->numFiles;

            if ($entryCount > 10_000) {
                throw UploadRejectedException::tooLarge($safeName);
            }

            for ($index = 0; $index < $entryCount; $index++) {
                $stat = $archive->statIndex($index);

                if (! is_array($stat)) {
                    continue;
                }

                $entrySize = $stat['size'] ?? 0;
                $uncompressedTotal += is_int($entrySize) ? $entrySize : 0;

                if ($uncompressedTotal > $compressedBytes * $ratio) {
                    throw UploadRejectedException::tooLarge($safeName);
                }
            }
        } finally {
            $archive->close();
        }
    }
}
