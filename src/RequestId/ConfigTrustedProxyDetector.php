<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\RequestId;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Qiick\HttpBasics\Contracts\TrustedProxyDetector;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * @internal Computes proxy trust from the same sources TrustProxies uses
 * ({@see TrustProxies::at()}, a subclass `$proxies`, and `trustedproxy.proxies`),
 * without reading Request static state set by middleware order.
 *
 * Wildcard `*` / `**` / `['*']` never trusts client ids unless
 * `http-basics.request_id.trust_wildcard_proxies` is true (default false).
 * On Laravel Cloud / Forge / Vapor, a null proxy config is treated as `*`
 * (same as TrustProxies), still gated by that opt-in.
 */
final class ConfigTrustedProxyDetector implements TrustedProxyDetector
{
    public function __construct(
        private ConfigRepository $config,
        private bool $trustWildcardProxies = false,
    ) {}

    public function isTrusted(Request $request): bool
    {
        $proxies = $this->configuredProxies($request);

        if ($this->isWildcard($proxies)) {
            return $this->trustWildcardProxies;
        }

        $list = $this->normalizeProxyList($proxies);
        $remote = $request->server->get('REMOTE_ADDR');

        // IpUtils never matches an empty REMOTE_ADDR, an empty entry or an empty list.
        if (! is_string($remote)) {
            return false;
        }

        $resolved = [];
        foreach ($list as $trustedIp) {
            $resolved[] = $trustedIp === 'REMOTE_ADDR' ? $remote : $trustedIp;
        }

        return IpUtils::checkIp($remote, $resolved);
    }

    /**
     * Mirrors TrustProxies::setTrustedProxyIpAddresses resolution order:
     * usable TrustProxies::at() → subclass $proxies → config → Cloud/Forge/Vapor `*`.
     *
     * @return list<string>|string
     */
    private function configuredProxies(Request $request): array|string
    {
        $fromAt = $this->trustProxiesAt();
        if ($this->isUsableProxyConfig($fromAt)) {
            return $this->stringOrStringList($fromAt);
        }

        $fromInstance = $this->trustProxiesInstanceProxies();
        if ($this->isUsableProxyConfig($fromInstance)) {
            return $this->stringOrStringList($fromInstance);
        }

        $fromConfig = $this->config->get('trustedproxy.proxies');
        if ($this->isUsableProxyConfig($fromConfig)) {
            return $this->stringOrStringList($fromConfig);
        }

        if ($this->platformImpliesWildcard($request)) {
            return '*';
        }

        return [];
    }

    /**
     * Laravel's `?:` treats [] and '' as missing — fall through to the next source.
     *
     * @phpstan-assert-if-true non-empty-array<mixed>|non-empty-string $proxies
     */
    private function isUsableProxyConfig(mixed $proxies): bool
    {
        if ($proxies === null || $proxies === [] || $proxies === '') {
            return false;
        }

        return is_string($proxies) || is_array($proxies);
    }

    /**
     * @param  list<string>|string  $proxies
     */
    private function isWildcard(array|string $proxies): bool
    {
        if ($proxies === '*' || $proxies === '**') {
            return true;
        }

        return $proxies === ['*'] || $proxies === ['**'];
    }

    /**
     * @param  list<string>|string  $proxies
     * @return list<string>
     */
    private function normalizeProxyList(array|string $proxies): array
    {
        $list = is_string($proxies) ? array_map(trim(...), explode(',', $proxies)) : $proxies;

        return $this->expandPrivateSubnetTokens($list);
    }

    /**
     * Expand Symfony's PRIVATE_SUBNETS / private_ranges tokens the same way
     * {@see Request::setTrustedProxies()} does (into IpUtils::PRIVATE_RANGES).
     *
     * @param  list<string>  $proxies
     * @return list<string>
     */
    private function expandPrivateSubnetTokens(array $proxies): array
    {
        $expanded = [];
        foreach ($proxies as $proxy) {
            if ($proxy === 'PRIVATE_SUBNETS' || $proxy === 'private_ranges') {
                foreach (IpUtils::PRIVATE_RANGES as $range) {
                    $expanded[] = $range;
                }

                continue;
            }

            $expanded[] = $proxy;
        }

        return $expanded;
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

    /**
     * Same implicit-`*` platforms TrustProxies recognises when proxies are null.
     */
    private function platformImpliesWildcard(Request $request): bool
    {
        $isLaravelForge = $request->server->has('FORGE_SERVER_ID')
            && str_ends_with($request->host(), '.on-forge.com');
        $isLaravelVapor = isset($_ENV['VAPOR_SSM_PATH'])
            && str_ends_with($request->host(), '.on-vapor.com');

        return laravel_cloud() || $isLaravelForge || $isLaravelVapor;
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

    /**
     * Read a concrete TrustProxies subclass `$proxies` when the app registers one
     * (container binding or the conventional App\Http\Middleware\TrustProxies).
     *
     * @return array<mixed>|string
     */
    private function trustProxiesInstanceProxies(): array|string
    {
        $property = new \ReflectionProperty(TrustProxies::class, 'proxies');

        foreach ($this->trustProxiesInstances() as $instance) {
            /** @var array<int, string>|string|null $value */
            $value = $property->getValue($instance);

            if ($this->isUsableProxyConfig($value)) {
                return $value;
            }
        }

        return [];
    }

    /**
     * @return list<TrustProxies>
     */
    private function trustProxiesInstances(): array
    {
        $instances = [];

        if (function_exists('app')) {
            try {
                $application = app();
                if ($application->bound(TrustProxies::class)) {
                    $resolved = $application->make(TrustProxies::class);
                    if ($resolved instanceof TrustProxies) {
                        $instances[] = $resolved;
                    }
                }
            } catch (\Throwable) {
                // Container unavailable — fall through to conventional class.
            }
        }

        $appClass = $this->conventionalAppTrustProxiesClass();
        if ($appClass !== null) {
            $instances[] = new $appClass;
        }

        return $instances;
    }

    /**
     * @return class-string<TrustProxies>|null
     */
    private function conventionalAppTrustProxiesClass(): ?string
    {
        // is_subclass_of() autoloads the class and is false when it does not exist.
        $appTrustProxies = 'App\\Http\\Middleware\\TrustProxies';

        return is_subclass_of($appTrustProxies, TrustProxies::class) ? $appTrustProxies : null;
    }
}
