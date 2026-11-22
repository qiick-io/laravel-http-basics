<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Uploads;

/**
 * @api Always-blocked filename suffixes (every suffix is checked, not only the last).
 *
 * Seeded from externa's hard floor. `a.php.jpg` is rejected because `php` appears.
 */
final class HardFloor
{
    /**
     * @var list<string>
     */
    public const EXTENSIONS = [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'pht',
        'exe', 'bat', 'cmd', 'com', 'msi', 'scr',
        'js', 'mjs', 'vbs', 'wsf', 'hta', 'cgi', 'pl',
        'asp', 'aspx', 'jsp',
        'sh', 'bash', 'ps1',
        'dll', 'so',
        'html', 'htm', 'shtml', 'shtm',
        'xml', 'xhtml', 'xht', 'mht', 'xsl',
    ];

    /**
     * @var list<string>
     */
    public const SVG_EXTENSIONS = ['svg', 'svgz'];

    public function isBlocked(string $fileName): bool
    {
        foreach ($this->suffixes($fileName) as $suffix) {
            if (in_array($suffix, self::EXTENSIONS, true)) {
                return true;
            }
        }

        return false;
    }

    public function isSvg(string $fileName): bool
    {
        foreach ($this->suffixes($fileName) as $suffix) {
            if (in_array($suffix, self::SVG_EXTENSIONS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function suffixes(string $fileName): array
    {
        $base = basename(str_replace(["\0", '\\'], ['', '/'], $fileName));
        $parts = explode('.', $base);

        if (count($parts) < 2) {
            return [];
        }

        array_shift($parts);

        return array_values(array_filter(
            array_map(static fn (string $part): string => strtolower($part), $parts),
            static fn (string $part): bool => $part !== '',
        ));
    }
}
