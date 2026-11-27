<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Uploads;

/**
 * @api Rejects unsafe client filenames before any storage name is chosen.
 *
 * NUL, C0/C1 controls, bidi / format characters, path separators, `..`,
 * trailing dots/spaces, invalid UTF-8, and names over 255 bytes are invalid.
 * Returns the NFC-normalised name on success.
 */
final class FileNameSanitizer
{
    public static function maxBytes(): int
    {
        return 255;
    }

    public function sanitize(string $clientName): string
    {
        if ($clientName === '') {
            throw UploadRejectedException::nameInvalid();
        }

        if (strlen($clientName) > self::maxBytes()) {
            throw UploadRejectedException::nameInvalid();
        }

        if (preg_match('//u', $clientName) !== 1) {
            throw UploadRejectedException::nameInvalid();
        }

        if (preg_match('/[\p{Cc}\p{Cf}]/u', $clientName) === 1) {
            throw UploadRejectedException::nameInvalid();
        }

        if (str_contains($clientName, '/')) {
            throw UploadRejectedException::nameInvalid();
        }

        if (str_contains($clientName, '\\')) {
            throw UploadRejectedException::nameInvalid();
        }

        if (str_contains($clientName, '..')) {
            throw UploadRejectedException::nameInvalid();
        }

        if (str_contains($clientName, ':') || str_contains($clientName, '%') || str_contains($clientName, ';')) {
            throw UploadRejectedException::nameInvalid();
        }

        // Normalisation fails only on invalid UTF-8, refused above; ext-intl is optional.
        $normalizedName = class_exists(\Normalizer::class) ? \Normalizer::normalize($clientName, \Normalizer::FORM_C) : $clientName;

        if (! is_string($normalizedName)) {
            throw UploadRejectedException::nameInvalid();
        }

        if (str_ends_with($normalizedName, '.')) {
            throw UploadRejectedException::nameInvalid();
        }

        if (str_ends_with($normalizedName, ' ')) {
            throw UploadRejectedException::nameInvalid();
        }

        if (str_starts_with($normalizedName, ' ')) {
            throw UploadRejectedException::nameInvalid();
        }

        return $normalizedName;
    }
}
