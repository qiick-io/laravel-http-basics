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
     * @return array<string, string>
     */
    public function extract(Response $response): array
    {
        $denied = array_map(strtolower(...), $this->deniedHeaders);
        $allowed = array_map(strtolower(...), $this->replayAllowlist);

        /** @var array<string, string> $headers */
        $headers = [];
        foreach ($response->headers->allPreserveCase() as $name => $values) {
            if (! is_string($name)) {
                continue;
            }

            $lower = strtolower($name);
            if (str_starts_with($lower, 'x-ratelimit-')) {
                continue;
            }
            if (in_array($lower, $denied, true)) {
                continue;
            }
            if (! in_array($lower, $allowed, true)) {
                continue;
            }

            $valueList = is_array($values) ? $values : [$values];
            $headers[$name] = implode(', ', array_map(
                static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
                $valueList,
            ));
        }

        return $headers;
    }

    public function responseHasDeniedHeaders(Response $response): bool
    {
        $denied = array_map(strtolower(...), $this->deniedHeaders);

        foreach ($response->headers->allPreserveCase() as $name => $values) {
            if (in_array(strtolower($name), $denied, true)) {
                return true;
            }

            if (strcasecmp($name, 'Set-Cookie') === 0 && $values !== []) {
                return true;
            }
        }

        return false;
    }
}
