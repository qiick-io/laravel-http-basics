<?php

declare(strict_types=1);

use Qiick\HttpBasics\Uploads\HardFloor;

/**
 * Frozen copies of the floor lists. Mutating HardFloor::EXTENSIONS (or MIME /
 * SVG lists) must fail these assertions — looping the production constants
 * would hide deletions.
 */
const HARD_FLOOR_EXTENSIONS = [
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

const HARD_FLOOR_SVG = ['svg', 'svgz'];

const HARD_FLOOR_BLOCKED_MIMES = [
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

it('keeps the seeded hard-floor extension catalogue intact', function (): void {
    expect(HardFloor::extensions())->toBe(HARD_FLOOR_EXTENSIONS)
        ->and(HardFloor::svgExtensions())->toBe(HARD_FLOOR_SVG)
        ->and(HardFloor::blockedMimeTypes())->toBe(HARD_FLOOR_BLOCKED_MIMES);
});

it('blocks every seeded extension and special basenames', function (): void {
    $floor = new HardFloor;

    foreach (HARD_FLOOR_EXTENSIONS as $extension) {
        expect($floor->isBlocked('payload.'.$extension))->toBeTrue();
        expect($floor->isBlocked('invoice.'.$extension.'.pdf'))->toBeTrue();
    }

    foreach (['.htaccess', 'htaccess', '.user.ini', 'user.ini', '.htpasswd', 'htpasswd', 'HTAccess', 'USER.INI'] as $name) {
        expect($floor->isBlocked($name))->toBeTrue();
    }

    expect($floor->isBlocked('photo.jpg'))->toBeFalse()
        ->and($floor->isBlocked('readme'))->toBeFalse()
        ->and($floor->isBlocked(''))->toBeFalse();
});

it('matches mangled and trailing-junk suffixes against the floor', function (): void {
    $floor = new HardFloor;

    expect($floor->isBlocked('a.php%00.jpg'))->toBeTrue()
        ->and($floor->isBlocked('a.php;.jpg'))->toBeTrue()
        ->and($floor->isBlocked('a.php::$DATA'))->toBeTrue()
        ->and($floor->isBlocked("a.php\u{00A0}"))->toBeTrue()
        ->and($floor->isBlocked('a.php!!!'))->toBeTrue()
        ->and($floor->isBlocked('%00.jpg'))->toBeFalse()
        ->and($floor->isBlocked('a.;jpg'))->toBeFalse();
});

it('normalises path separators and null bytes before suffix checks', function (): void {
    $floor = new HardFloor;

    expect($floor->isBlocked('dir\\evil.php'))->toBeTrue()
        ->and($floor->isBlocked('dir/evil.php'))->toBeTrue()
        ->and($floor->suffixes('a.PHP.JPG'))->toBe(['php', 'jpg'])
        ->and($floor->suffixes('noext'))->toBe([])
        ->and($floor->suffixes('.hidden'))->toBe(['hidden'])
        ->and($floor->suffixes('a..b.jpg'))->toBe(['b', 'jpg']);
});

it('detects svg suffixes and blocked sniffed MIME types', function (): void {
    $floor = new HardFloor;

    foreach (HARD_FLOOR_SVG as $extension) {
        expect($floor->isSvg('icon.'.$extension))->toBeTrue();
    }

    expect($floor->isSvg('icon.png'))->toBeFalse()
        ->and($floor->isSvg('icon.svg!!!'))->toBeTrue()
        ->and($floor->isSvg("icon.svg\u{00A0}"))->toBeTrue();

    foreach (HARD_FLOOR_BLOCKED_MIMES as $mime) {
        if ($mime === 'image/svg+xml') {
            expect($floor->isBlockedMime($mime, false))->toBeTrue()
                ->and($floor->isBlockedMime($mime, true))->toBeFalse();

            continue;
        }

        expect($floor->isBlockedMime($mime, false))->toBeTrue()
            ->and($floor->isBlockedMime($mime.'; charset=utf-8', true))->toBeTrue();
    }

    expect($floor->isBlockedMime('image/png', false))->toBeFalse()
        ->and($floor->isBlockedMime('TEXT/HTML', false))->toBeTrue()
        ->and($floor->isBlockedMime(' text/html ', false))->toBeTrue()
        ->and($floor->isBlockedMime(' text/html ; charset=utf-8', false))->toBeTrue();
});
