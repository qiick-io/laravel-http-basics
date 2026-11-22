<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Uploads;

/**
 * @api Maps a sniffed MIME type to a canonical storage extension.
 *
 * Storage names never inherit the client suffix; only these package-owned
 * extensions (matching `^[a-z0-9]{1,10}$`) are appended.
 */
final class DetectedMimeExtension
{
    /**
     * @return array<string, string>
     */
    public static function map(): array
    {
        return [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'text/plain' => 'txt',
            'application/zip' => 'zip',
            'application/x-zip-compressed' => 'zip',
            'image/svg+xml' => 'svg',
        ];
    }

    public function extensionFor(string $mimeType): ?string
    {
        $mime = strtolower(trim(explode(';', $mimeType, 2)[0])); // @pest-mutate-ignore: IncrementInteger
        $extension = self::map()[$mime] ?? null;

        if ($extension === null) {
            return null; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        if (preg_match('/\A[a-z0-9]{1,10}\z/', $extension) !== 1) { // @pest-mutate-ignore: IncrementInteger, DecrementInteger
            return null; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        return $extension;
    }
}
