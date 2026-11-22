<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Uploads;

/**
 * @api Maps a sniffed MIME type to a canonical storage extension.
 *
 * Storage names never inherit the client suffix; only these package-owned
 * extensions (each `^[a-z0-9]{1,10}$`, pinned by a test) are appended.
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
        return self::map()[strtolower(trim(explode(';', $mimeType)[0]))] ?? null;
    }
}
