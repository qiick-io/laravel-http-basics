<?php

declare(strict_types=1);

/*
 * qiick/laravel-http-basics configuration.
 *
 * Defaults match the binding design (projects/qiick/packages/laravel-http-basics-design.md).
 * Boot-time validation of modes/TTLs lands with the feature PRs that read them.
 */

return [

    'request_id' => [
        'hidden_context_keys' => ['http_basics.request_id'],
        'accept_pattern' => '/\A[A-Za-z0-9\-_.:]{8,128}\z/D',
        /*
         * trusted_proxy | any | never
         */
        'accept_from' => env('HTTP_BASICS_REQUEST_ID_ACCEPT_FROM', 'trusted_proxy'),
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
         * @var list<string>
         */
        'machine_doors' => ['api/*', 'mcp', 'mcp/*'],
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
