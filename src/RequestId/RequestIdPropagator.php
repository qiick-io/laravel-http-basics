<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\RequestId;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * @api Propagates only when allowed_hosts is non-empty and host[:port] matches.
 * Default allowed_hosts=[] → never propagates. Explicit ['*'] = all hosts (documented opt-in).
 *
 * Decision happens at send time from the actual request URI (including redirect
 * follow-ups). Caller-supplied X-Request-Id (any case) always wins on the first
 * hop; this never appends a second value. Cross-host/port redirects strip the
 * header and re-decide from the allowlist for the new URI.
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
     * including each redirect hop. The $url argument is ignored for allowlisting.
     */
    public function applyTo(PendingRequest $pending, string $url = ''): PendingRequest
    {
        return $pending->withMiddleware(function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
                /** @var array<string, mixed> $guzzleOptions */
                $guzzleOptions = $options;
                $maxRedirects = $this->maxRedirects($guzzleOptions);
                // Disable Guzzle's built-in redirect follower so we re-decide per hop.
                $guzzleOptions['allow_redirects'] = false;

                /** @var callable(RequestInterface, array<string, mixed>): PromiseInterface $nextHandler */
                $nextHandler = $handler;

                return $this->sendHop($nextHandler, $this->decorate($request, callerWins: true), $guzzleOptions, $maxRedirects);
            };
        });
    }

    /**
     * @param  callable(RequestInterface, array<string, mixed>): PromiseInterface  $handler
     * @param  array<string, mixed>  $options
     */
    private function sendHop(callable $handler, RequestInterface $request, array $options, int $maxRedirects): PromiseInterface
    {
        return $handler($request, $options)->then(
            function (ResponseInterface $response) use ($handler, $request, $options, $maxRedirects): PromiseInterface {
                if ($maxRedirects <= 0 || ! $this->isRedirect($response)) {
                    return Create::promiseFor($response);
                }

                $location = $response->getHeaderLine('Location');
                if ($location === '') {
                    return Create::promiseFor($response);
                }

                $nextUri = UriResolver::resolve($request->getUri(), Utils::uriFor($location));
                $next = $this->redirectRequest($request, $response->getStatusCode(), $nextUri);

                // Cross-host/port: strip and re-decide. Same host: still re-decide
                // so an allowlist miss cannot keep a prior hop's header.
                $next = $this->decorate($next, callerWins: false);

                return $this->sendHop($handler, $next, $options, $maxRedirects - 1);
            },
        );
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

    private function redirectRequest(RequestInterface $request, int $status, UriInterface $nextUri): RequestInterface
    {
        $next = $request->withUri($nextUri);

        if (in_array($status, [302, 303], true) && $request->getMethod() !== 'HEAD') {
            return $next->withMethod('GET');
        }

        return $next;
    }

    private function isRedirect(ResponseInterface $response): bool
    {
        return in_array($response->getStatusCode(), [301, 302, 303, 307, 308], true);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function maxRedirects(array $options): int
    {
        $allow = $options['allow_redirects'] ?? true;

        if ($allow === false) {
            return 0; // @pest-mutate-ignore: DecrementInteger
        }

        if (is_array($allow) && isset($allow['max']) && is_int($allow['max'])) {
            // Floor at zero so a negative configured max cannot loop forever.
            return $allow['max'] < 0 ? 0 : $allow['max']; // @pest-mutate-ignore: IncrementInteger, DecrementInteger
        }

        return 5; // @pest-mutate-ignore: IncrementInteger, DecrementInteger
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
