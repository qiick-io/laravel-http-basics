<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Qiick\HttpBasics\Contracts\IdGenerator;
use Qiick\HttpBasics\Contracts\TrustedProxyDetector;
use Qiick\HttpBasics\RequestId\ConfigTrustedProxyDetector;
use Qiick\HttpBasics\RequestId\RequestId;
use Qiick\HttpBasics\RequestId\RequestIdAcceptFrom;
use Qiick\HttpBasics\RequestId\RequestIdPropagator;
use Qiick\HttpBasics\RequestId\RequestIdResolver;
use Qiick\HttpBasics\RequestId\UuidIdGenerator;

/**
 * Registers the package in a Laravel app. Auto-discovered through
 * `extra.laravel.providers` in composer.json.
 *
 * Products keep their own error enums and Exception::render wiring (recipes only).
 */
final class HttpBasicsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/http-basics.php', 'http-basics');

        $this->app->singleton(IdGenerator::class, UuidIdGenerator::class);

        $this->app->singleton(TrustedProxyDetector::class, function (Application $app): TrustedProxyDetector {
            $config = $this->config($app);

            return new ConfigTrustedProxyDetector(
                $config,
                (bool) $config->get('http-basics.request_id.trust_wildcard_proxies', false),
            );
        });

        $this->app->singleton(RequestIdResolver::class, function (Application $app): RequestIdResolver {
            $config = $this->config($app);

            $acceptFromRaw = $config->get('http-basics.request_id.accept_from', 'trusted_proxy');
            if (! is_string($acceptFromRaw)) {
                throw new \InvalidArgumentException(
                    'http-basics.request_id.accept_from must be trusted_proxy, any, or never.',
                );
            }

            $acceptFrom = RequestIdAcceptFrom::tryFrom($acceptFromRaw);

            if ($acceptFrom === null) {
                throw new \InvalidArgumentException(
                    'http-basics.request_id.accept_from must be trusted_proxy, any, or never.',
                );
            }

            $keys = $config->get('http-basics.request_id.hidden_context_keys', []);
            if (! is_array($keys)) {
                $keys = [];
            }

            /** @var list<string> $hiddenKeys */
            $hiddenKeys = array_values(array_filter($keys, static fn (mixed $key): bool => is_string($key) && $key !== ''));

            $pattern = $config->get('http-basics.request_id.accept_pattern', RequestId::PATTERN);
            if (! is_string($pattern) || $pattern === '') {
                $pattern = RequestId::PATTERN;
            }

            return new RequestIdResolver(
                $app->make(IdGenerator::class),
                $app->make(TrustedProxyDetector::class),
                $acceptFrom,
                $hiddenKeys,
                'http_basics.request_id_source',
                $pattern,
            );
        });

        $this->app->singleton(RequestIdPropagator::class, function (Application $app): RequestIdPropagator {
            $hosts = $this->config($app)->get('http-basics.request_id.outbound.allowed_hosts', []);
            if (! is_array($hosts)) {
                $hosts = [];
            }

            /** @var list<string> $allowed */
            $allowed = array_values(array_filter($hosts, static fn (mixed $host): bool => is_string($host) && $host !== ''));

            return new RequestIdPropagator($allowed, $app->make(RequestIdResolver::class));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/http-basics.php' => config_path('http-basics.php'),
            ], 'http-basics-config');
        }
    }

    private function config(Application $app): ConfigRepository
    {
        return $app->make(ConfigRepository::class);
    }
}
