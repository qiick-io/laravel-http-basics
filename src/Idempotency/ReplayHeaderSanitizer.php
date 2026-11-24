<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

use Symfony\Component\HttpFoundation\Response;

/**
 * Filters response headers safe to persist and replay.
 */
final class ReplayHeaderSanitizer
{
    /**
     * @param  list<string>  $replayAllowlist
     * @param  list<string>  $deniedHeaders
     */
    public function __construct(
        private array $replayAllowlist,
        private array $deniedHeaders,
    ) {}

    /**
     * Allowlisted headers only, never a denied one, and never a rate-limit counter:
     * those describe the original request, so a replay must not repeat them.
     *
     * @return array<string, string>
     */
    public function extract(Response $response): array
    {
        $denied = array_map(strtolower(...), $this->deniedHeaders);
        $allowed = array_map(strtolower(...), $this->replayAllowlist);
        $headers = [];

        // all() keys are lower-case; header names are case-insensitive on the wire.
        foreach ($response->headers->all() as $name => $values) {
            if (str_starts_with($name, 'x-ratelimit-') || in_array($name, $denied, true) || ! in_array($name, $allowed, true)) {
                continue;
            }

            $headers[$name] = implode(', ', $values);
        }

        return $headers;
    }

    /**
     * A response carrying a denied header, or any cookie, is never stored: replaying
     * it would hand one client's session or secret to the next.
     */
    public function responseHasDeniedHeaders(Response $response): bool
    {
        $denied = array_map(strtolower(...), $this->deniedHeaders);

        foreach (array_keys($response->headers->all()) as $name) {
            if ($name === 'set-cookie' || in_array($name, $denied, true)) {
                return true;
            }
        }

        return false;
    }
}
