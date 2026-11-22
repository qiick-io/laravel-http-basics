<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Uploads;

/**
 * @api Rejects unsafe client filenames before any storage name is chosen.
 *
 * NUL, controls, path separators, `..`, trailing dots/spaces, and names over
 * 255 bytes are invalid. Returns the basename on success.
 */
final class FileNameSanitizer
{
    public const int MAX_BYTES = 255;

    public function sanitize(string $clientName): string
    {
        if ($clientName === '' || strlen($clientName) > self::MAX_BYTES) {
            throw UploadRejectedException::nameInvalid($clientName);
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $clientName) === 1) {
            throw UploadRejectedException::nameInvalid($clientName);
        }

        if (str_contains($clientName, '/') || str_contains($clientName, '\\') || str_contains($clientName, '..')) {
            throw UploadRejectedException::nameInvalid($clientName);
        }

        $base = basename($clientName);

        if ($base !== $clientName || $base === '.' || $base === '..') {
            throw UploadRejectedException::nameInvalid($clientName);
        }

        if (str_ends_with($base, '.') || str_ends_with($base, ' ') || str_starts_with($base, ' ')) {
            throw UploadRejectedException::nameInvalid($clientName);
        }

        return $base;
    }
}
