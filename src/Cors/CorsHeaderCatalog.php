<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Cors;

/**
 * @api Fleet CORS header catalogues for public APIs.
 *
 * Products merge these into `config/cors.php` `exposed_headers` /
 * `allowed_headers`. Literal names only — never `*`.
 */
final class CorsHeaderCatalog
{
    /**
     * @return list<string>
     */
    public static function exposedHeaders(): array
    {
        return [
            'X-Request-Id',
            'X-RateLimit-Limit',
            'X-RateLimit-Remaining',
            'X-RateLimit-Reset',
            'Retry-After',
            'Deprecation',
            'Sunset',
            'Content-Language',
            'Vary',
            'Link',
            'Location',
            'Idempotent-Replayed',
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedRequestHeaders(): array
    {
        return [
            'Authorization',
            'Content-Type',
            'Accept',
            'Accept-Language',
            'Idempotency-Key',
            'X-Request-Id',
            'traceparent',
            'tracestate',
        ];
    }
}
