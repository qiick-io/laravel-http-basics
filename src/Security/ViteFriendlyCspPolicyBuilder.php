<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Security;

use Illuminate\Http\Request;

/**
 * @api Default CSP for Vite + Echo/Reverb style apps: `'self'` with pragmatic
 * script/style/connect allowances and `frame-ancestors 'none'`. Adds the Vite
 * hot-server origin when the Vite facade root exposes `devServerUrl()` (Laravel
 * 13+). Laravel 12 has no such method; feature-detect and skip the origin.
 */
final class ViteFriendlyCspPolicyBuilder implements CspPolicyBuilder
{
    private const string VITE_FACADE = 'Illuminate\Support\Facades\Vite';

    public function build(Request $request): string
    {
        $developmentServerOrigins = $this->viteDevOrigins();

        $scriptSources = array_merge(["'self'", "'unsafe-inline'", "'unsafe-eval'"], $developmentServerOrigins);
        $styleSources = array_merge(["'self'", "'unsafe-inline'"], $developmentServerOrigins);
        $fontSources = array_merge(["'self'", 'data:'], $developmentServerOrigins);
        $imageSources = array_merge(["'self'", 'data:', 'blob:', 'https:'], $developmentServerOrigins);

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $scriptSources),
            'style-src '.implode(' ', $styleSources),
            'img-src '.implode(' ', array_values(array_unique($imageSources))),
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
     * Resolves the Vite hot origin without importing Foundation types. Uses the
     * Support facade class name + `method_exists` so Laravel 12 (no
     * `devServerUrl`) and Laravel 13+ both analyse and run cleanly.
     *
     * @return list<string>
     */
    private function viteDevOrigins(): array
    {
        if (! class_exists(self::VITE_FACADE)) {
            return [];
        }

        $facadeClass = self::VITE_FACADE;

        try {
            $vite = $facadeClass::getFacadeRoot();
        } catch (\Throwable) {
            return [];
        }

        if (! is_object($vite) || ! method_exists($vite, 'devServerUrl')) {
            return [];
        }

        try {
            $url = \call_user_func([$vite, 'devServerUrl']);
        } catch (\Throwable) {
            return [];
        }

        return $this->originsOf([$url]);
    }

    /**
     * @param  list<mixed>  $urls
     * @return list<string>
     */
    private function originsOf(array $urls): array
    {
        $origins = [];

        foreach ($urls as $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }

            $scheme = parse_url($url, PHP_URL_SCHEME);
            $host = parse_url($url, PHP_URL_HOST);
            $port = parse_url($url, PHP_URL_PORT);

            if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
                continue;
            }

            $origins[] = $scheme.'://'.$host.(is_int($port) ? ':'.$port : '');
        }

        return $origins;
    }
}
