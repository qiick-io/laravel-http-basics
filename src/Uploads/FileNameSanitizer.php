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
    public static function maxBytes(): int
    {
        return 255;
    }

    public function sanitize(string $clientName): string
    {
        if ($clientName === '') { // @pest-mutate-ignore: EmptyStringToNotEmpty
            throw UploadRejectedException::nameInvalid($clientName);
        }

        if (strlen($clientName) > self::maxBytes()) { // @pest-mutate-ignore: GreaterToGreaterOrEqual
            throw UploadRejectedException::nameInvalid($clientName);
        }

        if (preg_match('//u', $clientName) !== 1) {
            throw UploadRejectedException::nameInvalid($clientName);
        }

        if (preg_match('/[\p{Cc}\p{Cf}]/u', $clientName) === 1) {
            throw UploadRejectedException::nameInvalid($clientName);
        }

        if (str_contains($clientName, '/')) {
            throw UploadRejectedException::nameInvalid($clientName);
        }

        if (str_contains($clientName, '\\')) {
            throw UploadRejectedException::nameInvalid($clientName);
        }

        if (str_contains($clientName, '..')) {
            throw UploadRejectedException::nameInvalid($clientName);
        }

        if (str_contains($clientName, ':') || str_contains($clientName, '%') || str_contains($clientName, ';')) { // @pest-mutate-ignore: BooleanOrToBooleanAnd
            throw UploadRejectedException::nameInvalid($clientName);
        }

        $normalized = $clientName;

        if (class_exists(\Normalizer::class)) { // @pest-mutate-ignore: IfNegated
            $nfc = \Normalizer::normalize($clientName, \Normalizer::FORM_C);

            if (! is_string($nfc) || $nfc === '') { // @pest-mutate-ignore: BooleanOrToBooleanAnd, EmptyStringToNotEmpty
                throw UploadRejectedException::nameInvalid($clientName);
            }

            $normalized = $nfc;
        }

        $base = basename($normalized);

        if ($base !== $normalized) {
            throw UploadRejectedException::nameInvalid($clientName);
        }

        if ($base === '.' || $base === '..') { // @pest-mutate-ignore: BooleanOrToBooleanAnd
            throw UploadRejectedException::nameInvalid($clientName);
        }

        if (str_ends_with($base, '.')) {
            throw UploadRejectedException::nameInvalid($clientName);
        }

        if (str_ends_with($base, ' ')) {
            throw UploadRejectedException::nameInvalid($clientName);
        }

        if (str_starts_with($base, ' ')) {
            throw UploadRejectedException::nameInvalid($clientName);
        }

        return $base;
    }
}
