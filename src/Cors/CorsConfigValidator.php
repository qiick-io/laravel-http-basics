<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Cors;

/**
 * @api Boot-time CORS config checks for credentialed wildcards and origin shape.
 *
 * With `supports_credentials`, Fruitcake treats any `*` in an origin string and
 * any `allowed_origins_patterns` entry as a reflector; both are rejected here.
 * Concrete origins are normalised through {@see OriginNormalizer}.
 */
final class CorsConfigValidator
{
    public function __construct(
        private OriginNormalizer $originNormalizer = new OriginNormalizer,
    ) {}

    /**
     * @param  array<mixed, mixed>  $cors
     * @return array<mixed, mixed> CORS config with normalised concrete origins
     */
    public function validate(array $cors): array
    {
        $supportsCredentials = (bool) ($cors['supports_credentials'] ?? false);

        $origins = $cors['allowed_origins'] ?? [];
        $normalizedOrigins = [];

        if (is_array($origins)) {
            foreach ($origins as $origin) {
                if (! is_string($origin)) {
                    throw new \InvalidArgumentException(
                        'cors.allowed_origins entries must be strings.',
                    );
                }

                if (str_contains($origin, '*')) {
                    if ($supportsCredentials) {
                        throw new \InvalidArgumentException(
                            'cors.allowed_origins must not contain wildcards when supports_credentials is true.',
                        );
                    }

                    $normalizedOrigins[] = $origin;

                    continue;
                }

                $normalized = $this->originNormalizer->normalize($origin);

                if ($normalized === null) {
                    throw new \InvalidArgumentException(
                        'cors.allowed_origins contains an invalid origin: '.$origin,
                    );
                }

                $normalizedOrigins[] = $normalized;
            }
        }

        $cors['allowed_origins'] = $normalizedOrigins;

        $patterns = $cors['allowed_origins_patterns'] ?? [];

        if ($supportsCredentials && is_array($patterns) && $patterns !== []) {
            throw new \InvalidArgumentException(
                'cors.allowed_origins_patterns must be empty when supports_credentials is true (patterns are not host-anchored allowlists).',
            );
        }

        $exposed = $cors['exposed_headers'] ?? [];

        if ($supportsCredentials && is_array($exposed) && in_array('*', $exposed, true)) {
            throw new \InvalidArgumentException(
                'cors.exposed_headers must not contain * when supports_credentials is true.',
            );
        }

        return $cors;
    }
}
