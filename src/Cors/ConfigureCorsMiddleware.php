<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Cors;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api Writes normalised `cors.allowed_origins` for the current request before HandleCors.
 *
 * Resolver output is passed through {@see OriginNormalizer}; credentialed configs
 * drop every wildcard. Sets `Vary: Origin` when the resolver overwrote the list.
 * Origin enforcement (API-key gates) stays in the product (externa).
 */
final class ConfigureCorsMiddleware
{
    public function __construct(
        private AllowedOriginsResolver $origins,
        private OriginNormalizer $originNormalizer = new OriginNormalizer,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supportsCredentials = (bool) config('cors.supports_credentials', false);
        $resolved = $this->origins->origins($request);
        $didOverride = $resolved !== null;

        if ($didOverride) {
            config([
                'cors.allowed_origins' => $this->normalizeOriginList($resolved, $supportsCredentials),
            ]);
        } elseif ($supportsCredentials) {
            // Defence in depth when boot validation was skipped or config mutated later.
            $current = config('cors.allowed_origins', []);
            $patterns = config('cors.allowed_origins_patterns', []);

            if (is_array($current)) {
                config([
                    'cors.allowed_origins' => $this->normalizeOriginList($current, true),
                ]);
            }

            if (is_array($patterns) && $patterns !== []) {
                config(['cors.allowed_origins_patterns' => []]);
            }
        }

        $response = $next($request);

        if ($didOverride) {
            $response->headers->set('Vary', $this->mergeVaryOrigin($response->headers->get('Vary')));
        }

        return $response;
    }

    /**
     * @param  list<mixed>|array<mixed>  $origins
     * @return list<string>
     */
    private function normalizeOriginList(array $origins, bool $supportsCredentials): array
    {
        $normalized = [];

        foreach ($origins as $origin) {
            if (! is_string($origin)) {
                continue;
            }

            if (str_contains($origin, '*')) {
                if ($supportsCredentials) {
                    continue;
                }

                $normalized[] = $origin;

                continue;
            }

            $clean = $this->originNormalizer->normalize($origin);

            if ($clean !== null) {
                $normalized[] = $clean;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function mergeVaryOrigin(?string $existing): string
    {
        if ($existing === null || trim($existing) === '') {
            return 'Origin';
        }

        $tokens = array_map('trim', explode(',', $existing));

        if (! in_array('Origin', $tokens, true)) {
            $tokens[] = 'Origin';
        }

        return implode(', ', $tokens);
    }
}
