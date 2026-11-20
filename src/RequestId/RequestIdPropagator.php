<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\RequestId;

use Illuminate\Http\Client\PendingRequest;

/**
 * @api Propagates only when allowed_hosts is non-empty and host matches.
 * Default allowed_hosts=[] → never propagates. Explicit ['*'] = all hosts (documented opt-in).
 * Caller-supplied X-Request-Id on the PendingRequest always wins.
 */
final class RequestIdPropagator
{
    /**
     * @param  list<string>  $allowedHosts
     */
    public function __construct(
        private array $allowedHosts,
        private RequestIdResolver $resolver,
    ) {}

    /**
     * @return array<string, string>
     */
    public function headersForHost(string $host): array
    {
        if ($this->allowedHosts === [] || ! $this->hostIsAllowed($host)) {
            return [];
        }

        return $this->resolver->current()->outboundHeaders();
    }

    public function applyTo(PendingRequest $pending, string $url): PendingRequest
    {
        $host = parse_url($url, PHP_URL_HOST);
        // parse_url(..., PHP_URL_HOST) returns false/null when absent — never ''.
        if (! is_string($host)) {
            return $pending;
        }

        if ($this->pendingAlreadyHasRequestId($pending)) {
            return $pending;
        }

        $headers = $this->headersForHost($host);
        if ($headers === []) {
            return $pending;
        }

        // replaceHeaders overwrites an empty placeholder; withHeaders merges
        // recursively and would leave ['', 'mesh-id'].
        return $pending->replaceHeaders($headers);
    }

    private function hostIsAllowed(string $host): bool
    {
        foreach ($this->allowedHosts as $allowed) {
            if ($allowed === '*') {
                return true;
            }

            if (strcasecmp($allowed, $host) === 0) {
                return true;
            }
        }

        return false;
    }

    private function pendingAlreadyHasRequestId(PendingRequest $pending): bool
    {
        $options = $pending->getOptions();
        $headers = $options['headers'] ?? [];

        if (! is_array($headers)) {
            return false;
        }

        foreach ($headers as $name => $value) {
            if (! is_string($name)) {
                continue;
            }

            if (strcasecmp($name, RequestId::HEADER) === 0) {
                if (is_string($value) && $value !== '') {
                    return true;
                }

                if (is_array($value)) {
                    foreach ($value as $item) {
                        if (is_string($item) && $item !== '') {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }
}
