<?php

declare(strict_types=1);

/*
 * qiick/laravel-http-basics configuration.
 *
 * Defaults match the binding design (projects/qiick/packages/laravel-http-basics-design.md).
 * Boot-time validation of modes/TTLs lands with the feature PRs that read them.
 */

return [

    'errors' => [
        /*
         * fleet | problem_details
         * Chosen once per product (not per request). externa keeps fleet.
         */
        'format' => env('HTTP_BASICS_ERROR_FORMAT', 'fleet'),
        /*
         * When set, RFC 9457 `type` is this base URI plus the `error` code.
         * Null keeps `about:blank` and HTTP reason-phrase titles.
         */
        'problem_type_base_uri' => env('HTTP_BASICS_PROBLEM_TYPE_BASE_URI'),
    ],

    'uploads' => [
        'magic_signatures' => [
            'pdf' => ['255044462D'],
            'jpg' => ['FFD8FF'],
            'jpeg' => ['FFD8FF'],
            'png' => ['89504E470D0A1A0A'],
            'gif' => ['474946383761', '474946383961'],
        ],
        /*
         * @var array<string, array<string, mixed>>
         */
        'contexts' => [
            'files' => [
                'allowed_extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'txt'],
                'allowed_mime_types' => [
                    'application/pdf',
                    'image/png',
                    'image/jpeg',
                    'image/gif',
                    'image/webp',
                    'text/plain',
                ],
                'max_bytes' => 10 * 1024 * 1024,
                'allows_svg' => false,
                /*
                 * Archives (zip, docx, 7z, tar.gz, …) are refused unless true. A
                 * context that accepts them must validate every extracted file.
                 */
                'allows_archives' => false,
                'content_type_mismatch' => 'reject',
                'max_uncompressed_ratio' => 100,
                /*
                 * Absolute ceiling on the real inflated bytes of an archive, measured
                 * in streaming whatever its headers declare.
                 */
                'max_uncompressed_bytes' => 256 * 1024 * 1024,
            ],
        ],
    ],

    'request_id' => [
        /*
         * Optional dual-write aliases into hidden Context (e.g. externa).
         * The id is always also stored in visible Context as `request_id`.
         *
         * @var list<string>
         */
        'hidden_context_keys' => ['http_basics.request_id'],
        /*
         * Must require ≥1 alphanumeric (see RequestId::PATTERN). Override only
         * with a pattern that keeps that floor.
         */
        'accept_pattern' => '/\A(?=[A-Za-z0-9\-_.:]{8,128}$)(?=.*[A-Za-z0-9])[A-Za-z0-9\-_.:]+\z/D',
        /*
         * trusted_proxy | any | never
         */
        'accept_from' => env('HTTP_BASICS_REQUEST_ID_ACCEPT_FROM', 'trusted_proxy'),
        /*
         * When TrustProxies is `*` / `**` (Cloud/Forge/Vapor), client ids are
         * still rejected unless this is true.
         */
        'trust_wildcard_proxies' => (bool) env('HTTP_BASICS_TRUST_WILDCARD_PROXIES', false),
        'outbound' => [
            /*
             * Empty = never propagate. Explicit ['*'] = all hosts (documented opt-in).
             * Comma-separated hosts in HTTP_BASICS_OUTBOUND_HOSTS.
             *
             * @var list<string>
             */
            'allowed_hosts' => array_values(array_filter(array_map(
                static fn (string $host): string => trim($host),
                explode(',', (string) env('HTTP_BASICS_OUTBOUND_HOSTS', '')),
            ))),
        ],
    ],

    'locale' => [
        /*
         * Empty → [app.locale, app.fallback_locale] (unique, non-empty).
         * Comma-separated override via HTTP_BASICS_SUPPORTED_LOCALES.
         *
         * @var list<string>
         */
        'supported' => array_values(array_filter(array_map(
            static fn (string $locale): string => trim($locale),
            explode(',', (string) env('HTTP_BASICS_SUPPORTED_LOCALES', '')),
        ))),
        /*
         * @var list<string>
         */
        'machine_doors' => ['api', 'api/*', 'mcp', 'mcp/*'],
        'cookie' => 'locale',
        'fallback' => env('APP_FALLBACK_LOCALE', 'en'),
        'max_header_bytes' => (int) env('HTTP_BASICS_LOCALE_MAX_HEADER_BYTES', 1024),
        'max_languages' => (int) env('HTTP_BASICS_LOCALE_MAX_LANGUAGES', 20),
    ],

    'security' => [
        'enabled' => (bool) env('HTTP_BASICS_SECURITY_ENABLED', true),
        'x_frame_options' => env('HTTP_BASICS_X_FRAME_OPTIONS', 'DENY'),
        'referrer_policy' => env('HTTP_BASICS_REFERRER_POLICY', 'strict-origin-when-cross-origin'),
        'permissions_policy' => env(
            'HTTP_BASICS_PERMISSIONS_POLICY',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        ),
        'csp' => [
            /*
             * enforce | report-only | off | both
             */
            'mode' => env('HTTP_BASICS_CSP_MODE', 'enforce'),
            'policy' => env('HTTP_BASICS_CSP'),
            'report_only_policy' => env('HTTP_BASICS_CSP_REPORT_ONLY'),
            'report_uri' => env('HTTP_BASICS_CSP_REPORT_URI'),
            /*
             * Strict CSP (CSP3): a per-request nonce plus 'strict-dynamic' in the built
             * script-src, handed to Vite so its tags carry it; no 'unsafe-eval'. A policy
             * override (`policy` above) is used as written.
             */
            'nonce' => (bool) env('HTTP_BASICS_CSP_NONCE', false),
            /*
             * Hashes of allowed inline scripts, e.g. 'sha256-…' (base64).
             *
             * @var list<string>
             */
            'script_hashes' => [],
        ],
        'hsts' => [
            'max_age' => (int) env('HTTP_BASICS_HSTS_MAX_AGE', 0),
            'include_subdomains' => (bool) env('HTTP_BASICS_HSTS_SUBDOMAINS', false),
            'preload' => (bool) env('HTTP_BASICS_HSTS_PRELOAD', false),
        ],
        'baseline_on_machine_doors' => (bool) env('HTTP_BASICS_BASELINE_ON_API', true),
    ],

    'idempotency' => [
        'store' => env('HTTP_BASICS_IDEMPOTENCY_STORE', 'cache'),
        'cache_store' => env('HTTP_BASICS_IDEMPOTENCY_CACHE_STORE'),
        'database' => [
            'connection' => env('HTTP_BASICS_IDEMPOTENCY_CONNECTION'),
            'table' => env('HTTP_BASICS_IDEMPOTENCY_TABLE', 'idempotency_records'),
        ],
        'ttl_seconds' => (int) env('HTTP_BASICS_IDEMPOTENCY_TTL', 86400),
        'lock_ttl_seconds' => (int) env('HTTP_BASICS_IDEMPOTENCY_LOCK_TTL', 60),
        'max_handler_seconds' => (int) env('HTTP_BASICS_IDEMPOTENCY_MAX_HANDLER', 55),
        'stale_after_seconds' => (int) env('HTTP_BASICS_IDEMPOTENCY_STALE_AFTER', 60),
        /*
         * uuid | sf-string
         */
        'key_format' => env('HTTP_BASICS_IDEMPOTENCY_KEY_FORMAT', 'uuid'),
        'max_key_length' => 128,
        'max_body_bytes' => (int) env('HTTP_BASICS_IDEMPOTENCY_MAX_BODY_BYTES', 1048576),
        'max_response_bytes' => (int) env('HTTP_BASICS_IDEMPOTENCY_MAX_RESPONSE_BYTES', 1048576),
        /*
         * Header set on replayed responses (Stripe: Idempotent-Replayed: true).
         * Empty string or null disables it. Never stored in headers_json.
         */
        'replay_header' => env('HTTP_BASICS_IDEMPOTENCY_REPLAY_HEADER', 'Idempotent-Replayed'),
        /*
         * @var list<string>
         */
        'methods' => ['POST', 'PATCH'],
        'require_json' => true,
        'anonymous' => [
            'enabled' => (bool) env('HTTP_BASICS_IDEMPOTENCY_ANON_ENABLED', false),
            'secret' => env('HTTP_BASICS_IDEMPOTENCY_ANON_SECRET'),
            /*
             * Comma-separated: ip, user_agent, route
             *
             * @var list<string>
             */
            'components' => array_values(array_filter(array_map(
                static fn (string $part): string => trim($part),
                explode(',', (string) env('HTTP_BASICS_IDEMPOTENCY_ANON_COMPONENTS', 'ip,user_agent,route')),
            ))),
        ],
        'documentation_url' => env('HTTP_BASICS_IDEMPOTENCY_DOCS_URL'),
        /*
         * @var list<string>
         */
        'replay_headers' => [
            'Content-Type',
            'Content-Language',
            'Cache-Control',
            'Vary',
            'Retry-After',
            'Deprecation',
            'Sunset',
            'Link',
            'WWW-Authenticate',
            'Location',
        ],
        /*
         * @var list<string>
         */
        'denied_replay_headers' => [
            'Authorization',
            'Proxy-Authenticate',
            'Set-Cookie',
            'X-Api-Key',
            'X-Api-Secret',
            'X-Webhook-Signature',
        ],
    ],

];
