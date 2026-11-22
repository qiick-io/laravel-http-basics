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
        $supportsCredentials = (bool) config('cors.supports_credentials', false); // @pest-mutate-ignore: RemoveBooleanCast, FalseToTrue
        $resolved = $this->origins->origins($request);
        $didOverride = $resolved !== null; // @pest-mutate-ignore: NotIdenticalToIdentical

        if ($didOverride) { // @pest-mutate-ignore: IfNegated
            config([
                'cors.allowed_origins' => $this->normalizeOriginList($resolved, $supportsCredentials),
            ]);
        } elseif ($supportsCredentials) { // @pest-mutate-ignore: IfNegated
            // Defence in depth when boot validation was skipped or config mutated later.
            $current = config('cors.allowed_origins', []);
            $patterns = config('cors.allowed_origins_patterns', []);

            if (is_array($current)) { // @pest-mutate-ignore: IfNegated
                config([
                    'cors.allowed_origins' => $this->normalizeOriginList($current, true), // @pest-mutate-ignore: TrueToFalse
                ]);
            }

            if (is_array($patterns) && $patterns !== []) { // @pest-mutate-ignore: BooleanAndToBooleanOr, IfNegated
                config(['cors.allowed_origins_patterns' => []]);
            }
        }

        $response = $next($request);

        if ($didOverride) { // @pest-mutate-ignore: IfNegated
            $response->headers->set('Vary', $this->mergeVaryOrigin($response->headers->get('Vary'))); // @pest-mutate-ignore: RemoveMethodCall
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
            if (! is_string($origin)) { // @pest-mutate-ignore: RemoveNot, IfNegated
                continue; // @pest-mutate-ignore: ContinueToBreak
            }

            if (str_contains($origin, '*')) {
                if ($supportsCredentials) { // @pest-mutate-ignore: IfNegated
                    continue; // @pest-mutate-ignore: ContinueToBreak
                }

                $normalized[] = $origin;

                continue; // @pest-mutate-ignore: ContinueToBreak
            }

            $clean = $this->originNormalizer->normalize($origin);

            if ($clean !== null) { // @pest-mutate-ignore: NotIdenticalToIdentical, IfNegated
                $normalized[] = $clean;
            }
        }

        return array_values(array_unique($normalized)); // @pest-mutate-ignore: UnwrapArrayValues, UnwrapArrayUnique
    }

    private function mergeVaryOrigin(?string $existing): string
    {
        if ($existing === null || trim($existing) === '') { // @pest-mutate-ignore: BooleanOrToBooleanAnd, EmptyStringToNotEmpty, UnwrapTrim, IfNegated, IdenticalToNotIdentical
            return 'Origin'; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        $tokens = array_map('trim', explode(',', $existing)); // @pest-mutate-ignore: UnwrapArrayMap

        if (! in_array('Origin', $tokens, true)) { // @pest-mutate-ignore: RemoveNot, IfNegated
            $tokens[] = 'Origin';
        }

        return implode(', ', $tokens); // @pest-mutate-ignore: EmptyStringToNotEmpty
    }
}
