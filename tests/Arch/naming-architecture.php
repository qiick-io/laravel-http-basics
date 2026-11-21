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
            // Design §3.1: public contract names without Contract suffix.
            'Qiick\HttpBasics\Contracts\IdGenerator',
            'Qiick\HttpBasics\Contracts\TrustedProxyDetector',
        ],
        'Enums' => [
            // Design §3.1: RequestIdSource / RequestIdAcceptFrom (not *Enum).
            'Qiick\HttpBasics\RequestId\RequestIdSource',
            'Qiick\HttpBasics\RequestId\RequestIdAcceptFrom',
        ],
    ],

    'suffixes' => [
        'Laravel' => 'ServiceProvider',
    ],

    'controllerPublicMethods' => [],
];
