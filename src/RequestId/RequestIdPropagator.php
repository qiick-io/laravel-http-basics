<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\RequestId;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\PendingRequest;
use Psr\Http\Message\RequestInterface;

/**
 * @api Propagates only when allowed_hosts is non-empty and host[:port] matches.
 * Default allowed_hosts=[] → never propagates. Explicit ['*'] = all hosts (documented opt-in).
 *
 * Decision happens at send time from the actual request URI (including redirect
 * follow-ups). Caller-supplied X-Request-Id (any case) always wins on the first
 * hop; this never appends a second value. Cross-host/port redirects strip the
 * header and re-decide from the allowlist for the new URI.
 *
 * Redirect following is left to Guzzle's `allow_redirects` (which strips
 * Authorization and Cookie across origins). This middleware only adjusts
 * X-Request-Id on each hop that Guzzle actually sends.
 */
final class RequestIdPropagator
{
    /**
     * @param  list<string>  $allowedHosts  Host or host:port entries; `*` = all.
     */
    public function __construct(
        private array $allowedHosts,
        private RequestIdResolver $resolver,
    ) {}

    /**
     * @return array<string, string>
     */
    public function headersForHost(string $host, ?int $port = null): array
    {
        if ($this->allowedHosts === [] || ! $this->hostIsAllowed($host, $port)) {
            return [];
        }

        $current = $this->resolver->current();
        if ($current === null) {
            return [];
        }

        return $current->outboundHeaders();
    }

    /**
     * Registers Guzzle middleware that decides from the URI about to be sent,
     * including each redirect hop Guzzle follows. The $url argument is ignored
     * for allowlisting. Guzzle's allow_redirects is left intact so credentials
     * are stripped across origins.
     */
    public function applyTo(PendingRequest $pending, string $url = ''): PendingRequest
    {
        return $pending->withMiddleware(function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
                /** @var array<string, mixed> $guzzleOptions */
                $guzzleOptions = $options;
                // __redirect_count is set by Guzzle before each followed hop.
                $isFirstHop = ! isset($guzzleOptions['__redirect_count']);

                /** @var callable(RequestInterface, array<string, mixed>): PromiseInterface $nextHandler */
                $nextHandler = $handler;

                return $nextHandler($this->decorate($request, callerWins: $isFirstHop), $guzzleOptions);
            };
        });
    }

    private function decorate(RequestInterface $request, bool $callerWins): RequestInterface
    {
        if ($callerWins && $this->psrHasRequestId($request)) {
            return $request;
        }

        $request = $this->psrWithoutRequestId($request);

        $uri = $request->getUri();
        $host = $uri->getHost();
        $port = $uri->getPort();
        if ($port === null) {
            $port = $uri->getScheme() === 'https' ? 443 : 80;
        }

        $headers = $this->headersForHost($host, $port);
        if ($headers === []) {
            return $request;
        }

        return $request->withHeader(RequestId::HEADER, $headers[RequestId::HEADER]);
    }

    private function hostIsAllowed(string $host, ?int $port): bool
    {
        foreach ($this->allowedHosts as $allowed) {
            if ($allowed === '*') {
                return true;
            }

            if (str_contains($allowed, ':')) {
                $allowedHost = substr($allowed, 0, (int) strrpos($allowed, ':'));
                $allowedPort = substr($allowed, (int) strrpos($allowed, ':') + 1);
                if (strcasecmp($allowedHost, $host) === 0
                    && is_numeric($allowedPort)
                    && $port !== null
                    && (int) $allowedPort === $port) {
                    return true;
                }

                continue;
            }

            if (strcasecmp($allowed, $host) === 0) {
                return true;
            }
        }

        return false;
    }

    private function psrHasRequestId(RequestInterface $request): bool
    {
        foreach ($request->getHeaders() as $name => $values) {
            if (strcasecmp($name, RequestId::HEADER) !== 0) {
                continue;
            }

            foreach ($values as $value) {
                if (is_string($value) && $value !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    private function psrWithoutRequestId(RequestInterface $request): RequestInterface
    {
        foreach (array_keys($request->getHeaders()) as $name) {
            if (strcasecmp($name, RequestId::HEADER) === 0) {
                return $request->withoutHeader($name);
            }
        }

        return $request;
    }
}
