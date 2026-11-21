<?php

declare(strict_types=1);

/**
 * This repo's settings for the shared `NamingArchitectureTest.php` (qiick standards).
 *
 * Written once by the sync, never overwritten or checked: it is this repo's own.
 * Package-only rules (Laravel namespace, no Foundation in src/, @api surface) are
 * in `PackageArchitectureTest.php`.
 *
 * Public API names follow the binding design (not the shared Contract/Enum suffix
 * table): design-mandated types are listed under ignoring with reasons as they land.
 */
return [
    'namespace' => 'Qiick\HttpBasics',

    'ignoring' => [
        'Contracts' => [
            // Design §3.1 / §3.2: public contract names without Contract suffix.
            'Qiick\HttpBasics\Contracts\IdGenerator',
            'Qiick\HttpBasics\Contracts\TrustedProxyDetector',
            'Qiick\HttpBasics\Contracts\SupportedLocales',
            'Qiick\HttpBasics\Contracts\DefaultLocaleResolver',
        ],
        'Enums' => [
            // Design §3.1 / §3.2 / §3.3: source enums without Enum suffix.
            'Qiick\HttpBasics\RequestId\RequestIdSource',
            'Qiick\HttpBasics\RequestId\RequestIdAcceptFrom',
            'Qiick\HttpBasics\Locale\LocaleSource',
            'Qiick\HttpBasics\Security\CspMode',
        ],
    ],

    'suffixes' => [
        'Laravel' => 'ServiceProvider',
    ],

    'controllerPublicMethods' => [],
];
