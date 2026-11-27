<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;

/**
 * @api Default CSP for Vite + Echo/Reverb style apps: `'self'` with pragmatic
 * script/style/connect allowances and `frame-ancestors 'none'`. Adds the Vite
 * hot-server origin when the Vite facade root exposes `devServerUrl()` (Laravel
 * 13+). Laravel 12 has no such method; feature-detect and skip the origin. With a
 * nonce provider, the script-src becomes a strict CSP (S2).
 */
final class ViteFriendlyCspPolicyBuilder implements CspPolicyBuilder
{
    /**
     * @param  CspNonceProvider|null  $nonces  set: a strict script-src around the request nonce
     * @param  list<string>  $scriptHashes  `sha256-…` / `sha384-…` / `sha512-…` sources for inline scripts
     *
     * @throws \InvalidArgumentException when a hash is not a base64 CSP hash source
     */
    public function __construct(
        private ?CspNonceProvider $nonces = null,
        private array $scriptHashes = [],
    ) {
        foreach ($scriptHashes as $hash) {
            // The value lands inside the CSP header: base64 only, so it cannot add a source.
            if (preg_match('/\Asha(256|384|512)-[A-Za-z0-9+\/]+={0,2}\z/', $hash) !== 1) {
                throw new \InvalidArgumentException('http-basics.security.csp.script_hashes must hold sha256-, sha384- or sha512- base64 hashes.');
            }
        }
    }

    public function build(Request $request): string
    {
        $developmentServerOrigins = $this->viteDevOrigins();
        $hashSources = array_map(static fn (string $hash): string => "'{$hash}'", $this->scriptHashes);

        $scriptSources = $this->nonces === null
            ? ["'self'", "'unsafe-inline'", "'unsafe-eval'", ...$hashSources, ...$developmentServerOrigins]
            // Strict CSP (CSP3): the nonce and 'strict-dynamic' decide what runs, so no
            // 'unsafe-eval'; 'unsafe-inline' only reaches pre-CSP2 browsers, which ignore nonces.
            : ["'self'", "'nonce-".$this->nonces->nonceFor($request)."'", "'strict-dynamic'", ...$hashSources, ...$developmentServerOrigins, "'unsafe-inline'"];
        $styleSources = array_merge(["'self'", "'unsafe-inline'"], $developmentServerOrigins);
        $fontSources = array_merge(["'self'", 'data:'], $developmentServerOrigins);
        $imageSources = array_merge(["'self'", 'data:', 'blob:', 'https:'], $developmentServerOrigins);

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $scriptSources),
            'style-src '.implode(' ', $styleSources),
            'img-src '.implode(' ', $imageSources),
            'font-src '.implode(' ', $fontSources),
            "connect-src 'self' ws: wss: http: https:",
            "media-src 'self' blob:",
            "worker-src 'self' blob:",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }

    /**
     * Resolves the Vite hot origin without importing Foundation types: Laravel 12's
     * Vite has no `devServerUrl()`, Laravel 13's has, and an app without Vite bound
     * throws on resolution. Any of those yields no extra origin.
     *
     * @return list<string>
     */
    private function viteDevOrigins(): array
    {
        try {
            $vite = Vite::getFacadeRoot();
            $url = is_callable([$vite, 'devServerUrl']) ? \call_user_func([$vite, 'devServerUrl']) : null;
        } catch (\Throwable) {
            return [];
        }

        return $this->originOf($url);
    }

    /**
     * The http(s) origin of the Vite dev server URL, or none for anything else.
     *
     * @return list<string>
     */
    private function originOf(mixed $url): array
    {
        if (! is_string($url)) {
            return [];
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host']) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)) {
            return [];
        }

        return [$parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')];
    }
}
