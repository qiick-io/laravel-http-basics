<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Uploads;

/**
 * @api Rejects unsafe client filenames before any storage name is chosen.
 *
 * NUL, C0/C1 controls, bidi / format characters, path separators, `..`,
 * trailing dots/spaces, invalid UTF-8, and names over 255 bytes are invalid.
 * Returns the NFC basename on success.
 */
final class FileNameSanitizer
{
    public const int MAX_BYTES = 255;

    public function sanitize(string $clientName): string
    {
        if ($clientName === '' || strlen($clientName) > self::MAX_BYTES) {
            throw UploadRejectedException::nameInvalid($clientName);
        }

        if (preg_match('//u', $clientName) !== 1) {
            throw UploadRejectedException::nameInvalid($clientName);
        }

        if (preg_match('/[\p{Cc}\p{Cf}]/u', $clientName) === 1) {
            throw UploadRejectedException::nameInvalid($clientName);
        }

        if (str_contains($clientName, '/') || str_contains($clientName, '\\') || str_contains($clientName, '..')) {
            throw UploadRejectedException::nameInvalid($clientName);
        }

        if (str_contains($clientName, ':') || str_contains($clientName, '%') || str_contains($clientName, ';')) {
            throw UploadRejectedException::nameInvalid($clientName);
        }

        $normalized = $clientName;

        if (class_exists(\Normalizer::class)) {
            $nfc = \Normalizer::normalize($clientName, \Normalizer::FORM_C);

            if (! is_string($nfc) || $nfc === '') {
                throw UploadRejectedException::nameInvalid($clientName);
            }

            $normalized = $nfc;
        }

        $base = basename($normalized);

        if ($base !== $normalized || $base === '.' || $base === '..') {
            throw UploadRejectedException::nameInvalid($clientName);
        }

        if (str_ends_with($base, '.') || str_ends_with($base, ' ') || str_starts_with($base, ' ')) {
            throw UploadRejectedException::nameInvalid($clientName);
        }

        return $base;
    }
}
