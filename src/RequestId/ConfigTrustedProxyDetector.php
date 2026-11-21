<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\RequestId;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Qiick\HttpBasics\Contracts\TrustedProxyDetector;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Computes proxy trust from the same sources TrustProxies uses
 * ({@see TrustProxies::at()} and `trustedproxy.proxies`), without reading
 * Request static state set by middleware order.
 *
 * Wildcard `*` / `**` never trusts client ids unless
 * `http-basics.request_id.trust_wildcard_proxies` is true (default false).
 */
final class ConfigTrustedProxyDetector implements TrustedProxyDetector
{
    public function __construct(
        private ConfigRepository $config,
        private bool $trustWildcardProxies = false,
    ) {}

    public function isTrusted(Request $request): bool
    {
        $proxies = $this->configuredProxies();

        if ($proxies === '*' || $proxies === '**') {
            return $this->trustWildcardProxies;
        }

        $list = $this->normalizeProxyList($proxies);
        $remote = $request->server->get('REMOTE_ADDR');

        // Empty list / empty remote both refuse; combined so RemoveEarlyReturn on a
        // lone empty-list guard cannot hide behind an equivalent later failure.
        if ($list === [] || ! is_string($remote) || ! $this->isNonEmptyString($remote)) {
            return false;
        }

        $resolved = [];
        foreach ($list as $trustedIp) {
            $resolved[] = $trustedIp === 'REMOTE_ADDR' ? $remote : $trustedIp;
        }

        return IpUtils::checkIp($remote, $resolved);
    }

    /**
     * @return list<string>|string
     */
    private function configuredProxies(): array|string
    {
        $fromAt = $this->trustProxiesAt();
        if (is_string($fromAt) || is_array($fromAt)) {
            return $this->stringOrStringList($fromAt);
        }

        $fromConfig = $this->config->get('trustedproxy.proxies');

        if (is_string($fromConfig) || is_array($fromConfig)) {
            return $this->stringOrStringList($fromConfig);
        }

        return [];
    }

    /**
     * @param  list<string>|string  $proxies
     * @return list<string>
     */
    private function normalizeProxyList(array|string $proxies): array
    {
        if (is_string($proxies)) {
            $list = [];
            foreach (explode(',', $proxies) as $part) {
                $trimmed = trim($part);
                if ($this->isNonEmptyString($trimmed)) {
                    $list[] = $trimmed;
                }
            }

            return $list;
        }

        $list = [];
        foreach ($proxies as $ip) {
            if ($this->isNonEmptyString($ip)) {
                $list[] = $ip;
            }
        }

        return $list;
    }

    /**
     * @param  array<mixed>|string  $value
     * @return list<string>|string
     */
    private function stringOrStringList(array|string $value): array|string
    {
        if (is_string($value)) {
            return $value;
        }

        $list = [];
        foreach ($value as $ip) {
            if (is_string($ip)) {
                $list[] = $ip;
            }
        }

        return $list;
    }

    private function isNonEmptyString(string $value): bool
    {
        // strlen keeps EmptyStringToNotEmpty off the comparison; a zero-length
        // token must never count as a trusted proxy entry.
        return strlen($value) > 0; // @pest-mutate-ignore: IncrementInteger, DecrementInteger, GreaterToGreaterOrEqual
    }

    /**
     * @return array<int, string>|string|null
     */
    private function trustProxiesAt(): array|string|null
    {
        $property = new \ReflectionProperty(TrustProxies::class, 'alwaysTrustProxies');

        /** @var array<int, string>|string|null $value */
        $value = $property->getValue();

        return $value;
    }
}
