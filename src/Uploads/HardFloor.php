<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Uploads;

/**
 * @api Always-blocked filename suffixes (every suffix is checked, not only the last).
 *
 * Seeded from externa's hard floor plus the adversarial review gaps. Mangled
 * tokens (`php%00`, `php;`, `php::$DATA`) match by their leading `[a-z0-9]+` run.
 * Takes the whole client name, not a basename: a separator or NUL only adds
 * suffixes, so it can make a name stricter, never looser.
 */
final class HardFloor
{
    /**
     * @return list<string>
     */
    public static function extensions(): array
    {
        return [
            'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8',
            'pht', 'phps', 'phpt', 'phtm', 'pgif', 'inc',
            'exe', 'bat', 'cmd', 'com', 'msi', 'scr', 'pif', 'cpl', 'msc',
            'js', 'mjs', 'jse', 'vbs', 'vbe', 'wsf', 'hta', 'cgi', 'pl',
            'asp', 'aspx', 'jsp', 'cfm',
            'sh', 'bash', 'ps1', 'psm1',
            'dll', 'so',
            'html', 'htm', 'shtml', 'shtm',
            'xml', 'xhtml', 'xht', 'mht', 'xsl',
            'htaccess', 'htpasswd', 'ini',
            'jar', 'war', 'py', 'rb', 'lnk', 'reg', 'scf', 'url', 'iso',
            'docm', 'xlsm', 'swf',
        ];
    }

    /**
     * @return list<string>
     */
    public static function svgExtensions(): array
    {
        return ['svg', 'svgz'];
    }

    /**
     * Sniffed MIME types that are always refused (unless SVG is explicitly allowed).
     *
     * @return list<string>
     */
    public static function blockedMimeTypes(): array
    {
        return [
            'text/html',
            'application/xhtml+xml',
            'text/xml',
            'application/xml',
            'image/svg+xml',
            'text/x-php',
            'application/x-httpd-php',
            'application/x-php',
            'application/php',
            'application/x-dosexec',
            'application/x-executable',
            'application/x-sharedlib',
            'application/x-mach-binary',
            'application/java-archive',
            'application/x-msdownload',
            'application/vnd.microsoft.portable-executable',
        ];
    }

    public function isBlocked(string $fileName): bool
    {
        // Dotless names have no suffixes; dotted forms (.htaccess / user.ini) hit extensions().
        if (in_array(strtolower($fileName), ['htaccess', 'htpasswd'], true)) {
            return true;
        }

        return $this->hasSuffixIn($fileName, self::extensions());
    }

    public function isSvg(string $fileName): bool
    {
        return $this->hasSuffixIn($fileName, self::svgExtensions());
    }

    public function isBlockedMime(string $mimeType, bool $allowsSvg): bool
    {
        $mime = strtolower(trim(explode(';', $mimeType)[0]));

        if ($mime === 'image/svg+xml') {
            return ! $allowsSvg;
        }

        return in_array($mime, self::blockedMimeTypes(), true);
    }

    /**
     * Lower-cased, non-empty dot-separated suffixes after the first segment.
     *
     * @return list<string>
     */
    public function suffixes(string $fileName): array
    {
        $parts = explode('.', $fileName);
        array_shift($parts);

        return array_values(array_filter(
            array_map(strtolower(...), $parts),
            static fn (string $part): bool => $part !== '',
        ));
    }

    /**
     * A suffix matches by its leading `[a-z0-9]+` run, so whatever follows it
     * (`php%00`, `php;`, `php::$data`, `php<NBSP>`, `php!!!`, a path or a NUL) cannot
     * hide a listed extension from the floor.
     *
     * @param  list<string>  $extensions
     */
    private function hasSuffixIn(string $fileName, array $extensions): bool
    {
        foreach ($this->suffixes($fileName) as $suffix) {
            if (preg_match('/\A[a-z0-9]+/', $suffix, $matches) === 1 && in_array($matches[0], $extensions, true)) {
                return true;
            }
        }

        return false;
    }
}
