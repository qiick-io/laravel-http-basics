<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Uploads;

/**
 * @api Result of a successful upload floor validation (products persist this).
 */
final readonly class ValidatedUpload
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $publicId,
        public string $clientName,
        public string $safeName,
        public string $storageName,
        public string $extension,
        public ?string $declaredMime,
        public string $detectedMime,
        public string $mimeDetector,
        public string $mimeDetectorVersion,
        public int $sizeBytes,
        public string $sha256,
        public ScanResult $scan,
        public array $metadata = [],
    ) {}
}
