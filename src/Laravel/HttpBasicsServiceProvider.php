<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Qiick\HttpBasics\Contracts\DefaultLocaleResolver;
use Qiick\HttpBasics\Contracts\IdGenerator;
use Qiick\HttpBasics\Contracts\SupportedLocales;
use Qiick\HttpBasics\Contracts\TrustedProxyDetector;
use Qiick\HttpBasics\Locale\ConfigDefaultLocaleResolver;
use Qiick\HttpBasics\Locale\ConfigSupportedLocales;
use Qiick\HttpBasics\Locale\LocaleNegotiator;
use Qiick\HttpBasics\Locale\MachineDoorClassifier;
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

        $this->app->singleton(SupportedLocales::class, function (Application $app): SupportedLocales {
            $config = $this->config($app);
            $configured = $config->get('http-basics.locale.supported', []);
            if (! is_array($configured)) {
                $configured = [];
            }

            /** @var list<string> $locales */
            $locales = array_values(array_filter($configured, static fn (mixed $locale): bool => is_string($locale) && $locale !== ''));

            if ($locales === []) {
                $appLocale = $config->get('app.locale', 'en');
                $fallback = $config->get('app.fallback_locale', 'en');
                foreach ([$appLocale, $fallback] as $candidate) {
                    if (is_string($candidate) && $candidate !== '' && ! in_array($candidate, $locales, true)) {
                        $locales[] = $candidate;
                    }
                }
            }

            return new ConfigSupportedLocales($locales);
        });

        $this->app->singleton(DefaultLocaleResolver::class, function (Application $app): DefaultLocaleResolver {
            $locale = $this->config($app)->get('app.locale', 'en');

            return new ConfigDefaultLocaleResolver(is_string($locale) && $locale !== '' ? $locale : 'en');
        });

        $this->app->singleton(MachineDoorClassifier::class, function (Application $app): MachineDoorClassifier {
            $patterns = $this->config($app)->get('http-basics.locale.machine_doors', ['api/*', 'mcp', 'mcp/*']);
            if (! is_array($patterns)) {
                $patterns = [];
            }

            /** @var list<string> $list */
            $list = array_values(array_filter($patterns, static fn (mixed $pattern): bool => is_string($pattern) && $pattern !== ''));

            return new MachineDoorClassifier($list);
        });

        $this->app->singleton(LocaleNegotiator::class, function (Application $app): LocaleNegotiator {
            $config = $this->config($app);

            $cookie = $config->get('http-basics.locale.cookie', 'locale');
            $fallback = $config->get('http-basics.locale.fallback', 'en');
            $maxHeaderBytes = $config->get('http-basics.locale.max_header_bytes', 1024);
            $maxLanguages = $config->get('http-basics.locale.max_languages', 20);

            return new LocaleNegotiator(
                $app->make(SupportedLocales::class),
                $app->make(DefaultLocaleResolver::class),
                $app->make(MachineDoorClassifier::class),
                is_string($cookie) && $cookie !== '' ? $cookie : 'locale',
                is_string($fallback) && $fallback !== '' ? $fallback : 'en',
                is_int($maxHeaderBytes) ? $maxHeaderBytes : 1024,
                is_int($maxLanguages) ? $maxLanguages : 20,
            );
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
