<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Cors;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api Writes normalised `cors.allowed_origins` for the current request before HandleCors.
 *
 * Resolver output is passed through {@see OriginNormalizer}. Boot validation can be
 * switched off and resolver data is runtime input, so every credentialed request
 * re-applies the guard here: wildcard origins and every `allowed_origins_patterns`
 * entry are dropped, whichever branch produced the origin list. Sets `Vary: Origin`
 * when the resolver overwrote the list, because the ACAO then depends on the request.
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
        $supportsCredentials = (bool) config('cors.supports_credentials');
        $resolvedOrigins = $this->origins->origins($request);
        $didOverride = $resolvedOrigins !== null;

        if ($didOverride) {
            config(['cors.allowed_origins' => $this->normalizeOriginList($resolvedOrigins, $supportsCredentials)]);
        }

        if ($supportsCredentials) {
            // Defence in depth when boot validation was skipped or config mutated later.
            if (! $didOverride) {
                $configuredOrigins = config('cors.allowed_origins');

                config(['cors.allowed_origins' => $this->normalizeOriginList(is_array($configuredOrigins) ? $configuredOrigins : [], true)]);
            }

            config(['cors.allowed_origins_patterns' => []]);
        }

        $response = $next($request);

        if ($didOverride) {
            $response->headers->set('Vary', $this->mergeVaryOrigin($response->headers->all('vary')));
        }

        return $response;
    }

    /**
     * @param  array<mixed>  $origins
     * @return list<string>
     */
    private function normalizeOriginList(array $origins, bool $supportsCredentials): array
    {
        $normalizedOrigins = [];

        foreach ($origins as $origin) {
            if (! is_string($origin)) {
                continue;
            }

            if (str_contains($origin, '*')) {
                if (! $supportsCredentials) {
                    $normalizedOrigins[] = $origin;
                }

                continue;
            }

            $normalizedOrigin = $this->originNormalizer->normalize($origin);

            if ($normalizedOrigin !== null) {
                $normalizedOrigins[] = $normalizedOrigin;
            }
        }

        return array_values(array_unique($normalizedOrigins));
    }

    /**
     * Merges `Origin` into every existing `Vary` line: a response may carry several
     * `Vary` header lines, and dropping one (for example `Cookie`) would let a shared
     * cache serve one user's variant to another.
     *
     * @param  list<string|null>  $varyLines
     */
    private function mergeVaryOrigin(array $varyLines): string
    {
        $varyTokens = [];

        foreach ($varyLines as $varyLine) {
            foreach (explode(',', (string) $varyLine) as $varyToken) {
                $trimmedToken = trim($varyToken);

                if ($trimmedToken !== '') {
                    $varyTokens[strtolower($trimmedToken)] = $trimmedToken;
                }
            }
        }

        $varyTokens['origin'] ??= 'Origin';

        return implode(', ', $varyTokens);
    }
}
