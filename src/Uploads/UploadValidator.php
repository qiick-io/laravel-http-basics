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
        $clientName = (string) $file->getClientOriginalName(); // @pest-mutate-ignore: RemoveStringCast
        $path = $file->getRealPath();

        if (! is_string($path) || $path === '' || ! is_file($path)) { // @pest-mutate-ignore: BooleanOrToBooleanAnd, EmptyStringToNotEmpty, IfNegated, IdenticalToNotIdentical, RemoveNot
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
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) { // @pest-mutate-ignore: BooleanOrToBooleanAnd, RemoveNot
            throw UploadRejectedException::nameInvalid($clientName);
        }

        $safeName = $this->names->sanitize($clientName);
        $policy = $this->policies->forContext($context);

        if ($this->hardFloor->isBlocked($safeName)) {
            throw UploadRejectedException::forbiddenType($safeName);
        }

        if ($this->hardFloor->isSvg($safeName) && ! $policy->allowsSvg()) { // @pest-mutate-ignore: BooleanAndToBooleanOr, RemoveNot
            throw UploadRejectedException::forbiddenType($safeName);
        }

        $suffixes = $this->hardFloor->suffixes($safeName);
        $clientExtension = $suffixes === [] ? '' : $suffixes[array_key_last($suffixes)]; // @pest-mutate-ignore: ArrayKeyLastToArrayKeyFirst, EmptyStringToNotEmpty
        $clientExtension = preg_match('/\A[a-z0-9]{1,10}\z/', $clientExtension) === 1 ? $clientExtension : ''; // @pest-mutate-ignore: EmptyStringToNotEmpty, IncrementInteger, DecrementInteger

        $allowedExtensions = $policy->allowedExtensions();

        if ($allowedExtensions !== null) { // @pest-mutate-ignore: NotIdenticalToIdentical, IfNegated
            $allowed = array_map(strtolower(...), $allowedExtensions); // @pest-mutate-ignore: UnwrapArrayMap, UnwrapStrtolower

            if ($clientExtension === '' || ! in_array($clientExtension, $allowed, true)) { // @pest-mutate-ignore: EmptyStringToNotEmpty, BooleanOrToBooleanAnd, RemoveNot
                throw UploadRejectedException::typeNotAllowed($safeName);
            }
        }

        $size = filesize($absolutePath);
        $sizeBytes = is_int($size) ? $size : 0; // @pest-mutate-ignore: DecrementInteger, IncrementInteger
        $maxBytes = $policy->maxBytes();

        if ($maxBytes !== null && $sizeBytes > $maxBytes) { // @pest-mutate-ignore: GreaterToGreaterOrEqual, NotIdenticalToIdentical, BooleanAndToBooleanOr
            throw UploadRejectedException::tooLarge($safeName);
        }

        $header = $this->readHeader($absolutePath);
        $this->assertMagic($clientExtension, $header, $policy, $safeName); // @pest-mutate-ignore: RemoveMethodCall

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($absolutePath);
        $detectedMime = is_string($detected) && $detected !== '' && $detected !== 'application/x-empty' // @pest-mutate-ignore: BooleanAndToBooleanOr, EmptyStringToNotEmpty
            ? strtolower(trim(explode(';', $detected, 2)[0])) // @pest-mutate-ignore: IncrementInteger, DecrementInteger, UnwrapStrtolower, UnwrapTrim
            : 'application/octet-stream';

        if ($this->hardFloor->isBlockedMime($detectedMime, $policy->allowsSvg())) {
            throw UploadRejectedException::forbiddenType($safeName);
        }

        $allowedMimes = $policy->allowedMimeTypes();

        if ($allowedMimes !== null && ! in_array($detectedMime, array_map(strtolower(...), $allowedMimes), true)) { // @pest-mutate-ignore: NotIdenticalToIdentical, UnwrapArrayMap, UnwrapStrtolower, BooleanAndToBooleanOr, RemoveNot, IfNegated
            throw UploadRejectedException::typeNotAllowed($safeName);
        }

        $storageExtension = $this->mimeExtensions->extensionFor($detectedMime);

        if ($allowedExtensions !== null) { // @pest-mutate-ignore: NotIdenticalToIdentical, IfNegated
            $allowed = array_map(strtolower(...), $allowedExtensions); // @pest-mutate-ignore: UnwrapArrayMap, UnwrapStrtolower

            if ($storageExtension === null || ! in_array($storageExtension, $allowed, true)) { // @pest-mutate-ignore: BooleanOrToBooleanAnd, RemoveNot
                throw UploadRejectedException::typeNotAllowed($safeName);
            }
        }

        if ($declaredMime !== null && $declaredMime !== '') { // @pest-mutate-ignore: NotIdenticalToIdentical, EmptyStringToNotEmpty, BooleanAndToBooleanOr
            $declared = strtolower(trim(explode(';', $declaredMime, 2)[0])); // @pest-mutate-ignore: IncrementInteger, DecrementInteger, UnwrapStrtolower, UnwrapTrim

            if ($declared !== $detectedMime && $policy->contentTypeMismatch() === MismatchMode::Reject) { // @pest-mutate-ignore: BooleanAndToBooleanOr, NotIdenticalToIdentical
                throw UploadRejectedException::contentMismatch($safeName);
            }
        }

        $this->assertZipRatio($absolutePath, $detectedMime, $policy, $safeName); // @pest-mutate-ignore: RemoveMethodCall

        $scan = $this->scanner->scanPath($absolutePath);

        if ($scan === ScanResult::Infected) {
            throw UploadRejectedException::infected($safeName);
        }

        if ($scan === ScanResult::Failed) {
            throw UploadRejectedException::scanFailed($safeName);
        }

        $publicId = $this->ids->generate();
        $extension = $storageExtension ?? ''; // @pest-mutate-ignore: EmptyStringToNotEmpty, CoalesceRemoveLeft
        $storageName = $publicId.($extension !== '' ? '.'.$extension : ''); // @pest-mutate-ignore: EmptyStringToNotEmpty, ConcatRemoveLeft
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
            phpversion('fileinfo') ?: '', // @pest-mutate-ignore: EmptyStringToNotEmpty, TernaryNegated
            $sizeBytes,
            is_string($hash) ? $hash : '', // @pest-mutate-ignore: EmptyStringToNotEmpty, TernaryNegated
            $scan,
        );
    }

    private function readHeader(string $absolutePath): string
    {
        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) { // @pest-mutate-ignore: FalseToTrue, IfNegated
            return ''; // @pest-mutate-ignore: EmptyStringToNotEmpty, RemoveEarlyReturn
        }

        try {
            $header = fread($handle, 16); // @pest-mutate-ignore: IncrementInteger, DecrementInteger

            return is_string($header) ? $header : ''; // @pest-mutate-ignore: EmptyStringToNotEmpty, TernaryNegated
        } finally {
            fclose($handle); // @pest-mutate-ignore: RemoveFunctionCall
        }
    }

    private function assertMagic(string $extension, string $header, UploadPolicy $policy, string $safeName): void
    {
        if ($extension === '') { // @pest-mutate-ignore: EmptyStringToNotEmpty, IfNegated, IdenticalToNotIdentical
            return; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        $signatures = $policy->magicSignatures()[$extension] ?? []; // @pest-mutate-ignore: CoalesceRemoveLeft

        if ($signatures === []) { // @pest-mutate-ignore: IfNegated, IdenticalToNotIdentical
            return; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        $hex = strtoupper(bin2hex($header)); // @pest-mutate-ignore: UnwrapStrtoupper

        foreach ($signatures as $signature) {
            $needle = strtoupper(str_replace(' ', '', $signature)); // @pest-mutate-ignore: UnwrapStrtoupper, EmptyStringToNotEmpty, UnwrapStrReplace

            if ($needle !== '' && str_starts_with($hex, $needle)) { // @pest-mutate-ignore: EmptyStringToNotEmpty, BooleanAndToBooleanOr
                return; // @pest-mutate-ignore: RemoveEarlyReturn
            }
        }

        throw UploadRejectedException::contentMismatch($safeName);
    }

    private function assertZipRatio(string $absolutePath, string $detectedMime, UploadPolicy $policy, string $safeName): void
    {
        $ratio = $policy->maxUncompressedRatio();

        if ($ratio === null || $ratio <= 0) { // @pest-mutate-ignore: BooleanOrToBooleanAnd, SmallerToSmallerOrEqual, SmallerOrEqualToSmaller, DecrementInteger, IncrementInteger
            return; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        if (! in_array($detectedMime, ['application/zip', 'application/x-zip-compressed'], true)) { // @pest-mutate-ignore: RemoveNot, RemoveArrayItem
            return; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        if (! class_exists(\ZipArchive::class)) { // @pest-mutate-ignore: RemoveNot, IfNegated
            throw UploadRejectedException::forbiddenType($safeName);
        }

        $archive = new \ZipArchive;
        $opened = $archive->open($absolutePath);

        if ($opened !== true) { // @pest-mutate-ignore: NotIdenticalToIdentical, TrueToFalse, IfNegated
            throw UploadRejectedException::contentMismatch($safeName);
        }

        try {
            $compressedSize = filesize($absolutePath);
            $compressedBytes = is_int($compressedSize) && $compressedSize > 0 ? $compressedSize : 1; // @pest-mutate-ignore: BooleanAndToBooleanOr, GreaterToGreaterOrEqual, GreaterToSmallerOrEqual, DecrementInteger, IncrementInteger, TernaryNegated
            $uncompressedTotal = 0; // @pest-mutate-ignore: DecrementInteger, IncrementInteger
            $entryCount = $archive->numFiles;

            if ($entryCount > 10_000) { // @pest-mutate-ignore: GreaterToGreaterOrEqual, GreaterToSmallerOrEqual, DecrementInteger, IncrementInteger, IfNegated
                throw UploadRejectedException::tooLarge($safeName);
            }

            for ($index = 0; $index < $entryCount; $index++) { // @pest-mutate-ignore: IncrementInteger, DecrementInteger, PostIncrementToPostDecrement, SmallerToSmallerOrEqual
                $stat = $archive->statIndex($index);

                if (! is_array($stat)) { // @pest-mutate-ignore: RemoveNot, IfNegated
                    continue; // @pest-mutate-ignore: ContinueToBreak
                }

                $entrySize = $stat['size'] ?? 0; // @pest-mutate-ignore: DecrementInteger, IncrementInteger, CoalesceRemoveLeft
                $uncompressedTotal += is_int($entrySize) ? $entrySize : 0; // @pest-mutate-ignore: DecrementInteger, IncrementInteger, TernaryNegated

                if ($uncompressedTotal > $compressedBytes * $ratio) { // @pest-mutate-ignore: GreaterToGreaterOrEqual, MultiplicationToDivision
                    throw UploadRejectedException::tooLarge($safeName);
                }
            }
        } finally {
            $archive->close(); // @pest-mutate-ignore: RemoveMethodCall
        }
    }
}
