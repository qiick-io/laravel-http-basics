<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Uploads;

/**
 * @api Per-context upload policy (files, chat, …).
 */
interface UploadPolicy
{
    /**
     * @return list<string>|null Lowercase extensions without a leading dot; null = no allowlist.
     */
    public function allowedExtensions(): ?array;

    /**
     * @return list<string>|null Lowercase MIME types; null = no allowlist.
     */
    public function allowedMimeTypes(): ?array;

    public function maxBytes(): ?int;

    public function allowsSvg(): bool;

    public function contentTypeMismatch(): MismatchMode;

    public function maxUncompressedRatio(): ?int;

    /**
     * @return array<string, list<string>> Extension => hex magic prefixes.
     */
    public function magicSignatures(): array;
}
