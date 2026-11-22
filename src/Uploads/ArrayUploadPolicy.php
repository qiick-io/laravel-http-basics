<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Uploads;

/**
 * @api Array-backed UploadPolicy for tests and simple product bindings.
 */
final class ArrayUploadPolicy implements UploadPolicy
{
    /**
     * @param  list<string>|null  $allowedExtensions
     * @param  list<string>|null  $allowedMimeTypes
     * @param  array<string, list<string>>  $magicSignatures
     */
    public function __construct(
        private ?array $allowedExtensions = null,
        private ?array $allowedMimeTypes = null,
        private ?int $maxBytes = null,
        private bool $allowsSvg = false,
        private MismatchMode $contentTypeMismatch = MismatchMode::Reject,
        private ?int $maxUncompressedRatio = null,
        private array $magicSignatures = [],
    ) {}

    public function allowedExtensions(): ?array
    {
        return $this->allowedExtensions;
    }

    public function allowedMimeTypes(): ?array
    {
        return $this->allowedMimeTypes;
    }

    public function maxBytes(): ?int
    {
        return $this->maxBytes;
    }

    public function allowsSvg(): bool
    {
        return $this->allowsSvg;
    }

    public function contentTypeMismatch(): MismatchMode
    {
        return $this->contentTypeMismatch;
    }

    public function maxUncompressedRatio(): ?int
    {
        return $this->maxUncompressedRatio;
    }

    public function magicSignatures(): array
    {
        return $this->magicSignatures;
    }
}
