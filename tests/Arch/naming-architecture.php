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
            'Qiick\HttpBasics\Contracts\CredentialBucketResolver',
            'Qiick\HttpBasics\Contracts\IdempotencyStore',
            'Qiick\HttpBasics\Contracts\ErrorRenderer',
            // Design M10/S7: upload seams without Contract/Enum suffix.
            'Qiick\HttpBasics\Uploads\UploadPolicy',
            'Qiick\HttpBasics\Uploads\UploadPolicyResolver',
            'Qiick\HttpBasics\Uploads\MalwareScanner',
            // Design M9: CORS seams without Contract suffix.
            'Qiick\HttpBasics\Cors\AllowedOriginsResolver',
            // Design §3.5: public contract names without Contract suffix.
            'Qiick\HttpBasics\Errors\ErrorCodeResolver',
            'Qiick\HttpBasics\Errors\ExceptionRespondBinder',
        ],
        'Enums' => [
            // Design §3.1 / §3.2 / §3.3: source enums without Enum suffix.
            'Qiick\HttpBasics\RequestId\RequestIdSource',
            'Qiick\HttpBasics\RequestId\RequestIdAcceptFrom',
            'Qiick\HttpBasics\Locale\LocaleSource',
            'Qiick\HttpBasics\Security\CspMode',
            'Qiick\HttpBasics\Idempotency\ClaimOutcome',
            'Qiick\HttpBasics\Errors\ErrorCode',
            'Qiick\HttpBasics\Idempotency\IdempotencyKeyFormat',
            'Qiick\HttpBasics\Uploads\ScanResult',
            'Qiick\HttpBasics\Uploads\MismatchMode',
        ],
    ],

    'suffixes' => [
        'Laravel' => 'ServiceProvider',
        'Console' => 'Command',
    ],

    'controllerPublicMethods' => [],
];
