<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;

/**
 * @api Default CSP for Vite + Echo/Reverb style apps: `'self'` with pragmatic
 * script/style/connect allowances and `frame-ancestors 'none'`. Adds the Vite
 * hot-server origin when `Illuminate\Support\Facades\Vite` is available.
 */
final class ViteFriendlyCspPolicyBuilder implements CspPolicyBuilder
{
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
     * @return list<string>
     */
    private function viteDevOrigins(): array
    {
        if (! class_exists(Vite::class)) {
            return [];
        }

        try {
            $url = Vite::devServerUrl();
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
