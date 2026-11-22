<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Cors;

/**
 * @api Boot-time CORS config checks for credentialed wildcards.
 *
 * Rejects `allowed_origins` containing `*` when `supports_credentials` is true,
 * and rejects `*` in `exposed_headers` when credentials are on (Fetch forbids
 * both for credentialed responses).
 */
final class CorsConfigValidator
{
    /**
     * @param  array<mixed, mixed>  $cors
     */
    public function validate(array $cors): void
    {
        $supportsCredentials = (bool) ($cors['supports_credentials'] ?? false);

        if (! $supportsCredentials) {
            return;
        }

        $origins = $cors['allowed_origins'] ?? [];

        if (is_array($origins) && in_array('*', $origins, true)) {
            throw new \InvalidArgumentException(
                'cors.allowed_origins must not contain * when supports_credentials is true.',
            );
        }

        $exposed = $cors['exposed_headers'] ?? [];

        if (is_array($exposed) && in_array('*', $exposed, true)) {
            throw new \InvalidArgumentException(
                'cors.exposed_headers must not contain * when supports_credentials is true.',
            );
        }
    }
}
