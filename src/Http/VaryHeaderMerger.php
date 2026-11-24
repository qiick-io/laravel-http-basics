<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Http;

use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * @internal Adds a token to `Vary` across every `Vary` line a response carries. A
 * handler or another middleware may have added several lines; reading only the
 * first and setting it back would drop the others, and a shared cache would then
 * serve one variant (one tied to a cookie, say) to everyone.
 */
final class VaryHeaderMerger
{
    /**
     * Rewrites `Vary` as one line: every token once, compared case-insensitively,
     * in the spelling it last appeared in, then the new token if missing.
     */
    public static function add(ResponseHeaderBag $headers, string $token): void
    {
        $varyTokens = [];

        foreach ($headers->all('vary') as $varyLine) {
            foreach (explode(',', (string) $varyLine) as $varyToken) {
                $trimmedToken = trim($varyToken);

                if ($trimmedToken !== '') {
                    $varyTokens[strtolower($trimmedToken)] = $trimmedToken;
                }
            }
        }

        $varyTokens[strtolower($token)] ??= $token;

        $headers->set('Vary', implode(', ', $varyTokens));
    }
}
