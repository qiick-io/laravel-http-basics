<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Uploads;

/**
 * @api Always-blocked filename suffixes (every suffix is checked, not only the last).
 *
 * Seeded from externa's hard floor plus the adversarial review gaps. Mangled
 * tokens (`php%00`, `php;`, `php::$DATA`) match after taking the leading
 * `[a-z0-9]+` run when the suffix contains `%`, `;`, `:`, or NBSP.
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
        $base = $this->normalizedBaseName($fileName);

        // Dotless basenames have no suffixes; dotted forms (.htaccess / user.ini) hit extensions().
        if (in_array(strtolower($base), ['htaccess', 'htpasswd'], true)) { // @pest-mutate-ignore: UnwrapStrtolower
            return true;
        }

        foreach ($this->suffixes($fileName) as $suffix) {
            if ($this->suffixIsBlocked($suffix)) {
                return true;
            }
        }

        return false;
    }

    public function isSvg(string $fileName): bool
    {
        foreach ($this->suffixes($fileName) as $suffix) {
            $token = $this->matchToken($suffix);

            if ($token !== '' && in_array($token, self::svgExtensions(), true)) { // @pest-mutate-ignore: EmptyStringToNotEmpty
                return true;
            }
        }

        return false;
    }

    public function isBlockedMime(string $mimeType, bool $allowsSvg): bool
    {
        $mime = strtolower(trim(explode(';', $mimeType, 2)[0])); // @pest-mutate-ignore: IncrementInteger

        if ($mime === 'image/svg+xml') {
            return ! $allowsSvg;
        }

        return in_array($mime, self::blockedMimeTypes(), true);
    }

    /**
     * @return list<string>
     */
    public function suffixes(string $fileName): array
    {
        $base = $this->normalizedBaseName($fileName);
        $parts = explode('.', $base);

        if (count($parts) < 2) { // @pest-mutate-ignore: LessThanToLessThanOrEqual, DecrementInteger, IncrementInteger
            return []; // @pest-mutate-ignore: RemoveEarlyReturn, AlwaysReturnEmptyArray
        }

        array_shift($parts);

        return array_values(array_filter(
            array_map(static fn (string $part): string => strtolower($part), $parts),
            static fn (string $part): bool => $part !== '', // @pest-mutate-ignore: EmptyStringToNotEmpty
        ));
    }

    private function suffixIsBlocked(string $suffix): bool
    {
        $token = $this->matchToken($suffix);

        return $token !== '' && in_array($token, self::extensions(), true); // @pest-mutate-ignore: EmptyStringToNotEmpty, BooleanAndToBooleanOr
    }

    /**
     * Leading `[a-z0-9]+` when the suffix is mangled; otherwise the bare suffix
     * after trimming trailing non-alnum junk. Empty string means "no token".
     */
    private function matchToken(string $suffix): string
    {
        if ($suffix === '') { // @pest-mutate-ignore: EmptyStringToNotEmpty
            return ''; // @pest-mutate-ignore: EmptyStringToNotEmpty, RemoveEarlyReturn
        }

        if (preg_match('/[%:;]|\x{00A0}/u', $suffix) === 1) {
            if (preg_match('/\A[a-z0-9]+/', $suffix, $matches) === 1) {
                return $matches[0];
            }

            return ''; // @pest-mutate-ignore: EmptyStringToNotEmpty, RemoveEarlyReturn
        }

        return preg_replace('/[^a-z0-9]+$/u', '', $suffix) ?? ''; // @pest-mutate-ignore: EmptyStringToNotEmpty
    }

    private function normalizedBaseName(string $fileName): string
    {
        // NUL and Windows separators; equivalent mutants on Unix runners where basename ignores `\`.
        return basename(str_replace(["\0", '\\'], ['', '/'], $fileName)); // @pest-mutate-ignore: RemoveArrayItem, EmptyStringToNotEmpty, UnwrapStrReplace
    }
}
