<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Cors;

/**
 * @api Normalises browser Origin values to scheme://host[:port].
 *
 * Rejects userinfo, path, query, fragment, and the string `null`.
 */
final class OriginNormalizer
{
    public function normalize(string $origin): ?string
    {
        $trimmed = trim($origin);

        if ($trimmed === '' || strtolower($trimmed) === 'null') {
            return null;
        }

        $parts = parse_url($trimmed);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? strtolower($parts['scheme']) : null;
        $host = isset($parts['host']) && is_string($parts['host']) ? strtolower($parts['host']) : null;

        if ($scheme === null || $host === null) {
            return null;
        }

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $port = isset($parts['port']) && is_int($parts['port']) ? $parts['port'] : null;
        $defaultPort = $scheme === 'https' ? 443 : 80;

        if ($port === null || $port === $defaultPort) {
            return $scheme.'://'.$host;
        }

        return $scheme.'://'.$host.':'.$port;
    }
}
