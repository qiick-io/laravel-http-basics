<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Laravel;

use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Qiick\HttpBasics\Console\PruneIdempotencyRecordsCommand;
use Qiick\HttpBasics\Contracts\CredentialBucketResolver;
use Qiick\HttpBasics\Contracts\DefaultLocaleResolver;
use Qiick\HttpBasics\Contracts\ErrorRenderer;
use Qiick\HttpBasics\Contracts\IdempotencyStore;
use Qiick\HttpBasics\Contracts\IdGenerator;
use Qiick\HttpBasics\Contracts\SupportedLocales;
use Qiick\HttpBasics\Contracts\TrustedProxyDetector;
use Qiick\HttpBasics\Idempotency\AnonymousBucketResolver;
use Qiick\HttpBasics\Idempotency\CacheIdempotencyStore;
use Qiick\HttpBasics\Idempotency\DatabaseIdempotencyStore;
use Qiick\HttpBasics\Idempotency\Fingerprinter;
use Qiick\HttpBasics\Idempotency\FleetErrorRenderer;
use Qiick\HttpBasics\Idempotency\HandleIdempotencyMiddleware;
use Qiick\HttpBasics\Idempotency\IdempotencyKeyFormat;
use Qiick\HttpBasics\Idempotency\IdempotencyKeyValidator;
use Qiick\HttpBasics\Idempotency\NullCredentialBucketResolver;
use Qiick\HttpBasics\Idempotency\ReplayHeaderSanitizer;
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
use Qiick\HttpBasics\Security\CspMode;
use Qiick\HttpBasics\Security\CspPolicyBuilder;
use Qiick\HttpBasics\Security\SecurityHeaderBuilder;
use Qiick\HttpBasics\Security\SecurityHeadersPolicy;
use Qiick\HttpBasics\Security\ViteFriendlyCspPolicyBuilder;

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
            $patterns = $this->config($app)->get('http-basics.locale.machine_doors', ['api', 'api/*', 'mcp', 'mcp/*']);
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

        $this->app->singleton(CspPolicyBuilder::class, ViteFriendlyCspPolicyBuilder::class);

        $this->app->singleton(SecurityHeadersPolicy::class, function (Application $app): SecurityHeadersPolicy {
            $config = $this->config($app);

            $cspModeRaw = $config->get('http-basics.security.csp.mode', 'enforce');
            if (! is_string($cspModeRaw)) {
                throw new \InvalidArgumentException(
                    'http-basics.security.csp.mode must be enforce, report-only, off, or both.',
                );
            }

            $cspMode = CspMode::tryFrom($cspModeRaw);
            if ($cspMode === null) {
                throw new \InvalidArgumentException(
                    'http-basics.security.csp.mode must be enforce, report-only, off, or both.',
                );
            }

            $patterns = $config->get('http-basics.locale.machine_doors', ['api/*', 'mcp', 'mcp/*']);
            if (! is_array($patterns)) {
                $patterns = [];
            }

            /** @var list<string> $machineDoorPatterns */
            $machineDoorPatterns = array_values(array_filter(
                $patterns,
                static fn (mixed $pattern): bool => is_string($pattern) && $pattern !== '',
            ));

            $csp = $config->get('http-basics.security.csp.policy');
            $cspReportOnly = $config->get('http-basics.security.csp.report_only_policy');
            $cspReportUri = $config->get('http-basics.security.csp.report_uri');

            $xFrame = $config->get('http-basics.security.x_frame_options', 'DENY');
            $referrer = $config->get('http-basics.security.referrer_policy', 'strict-origin-when-cross-origin');
            $permissions = $config->get(
                'http-basics.security.permissions_policy',
                'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            );

            $hstsMaxAgeRaw = $config->get('http-basics.security.hsts.max_age', 0);
            $hstsMaxAge = 0;
            if (is_int($hstsMaxAgeRaw)) {
                $hstsMaxAge = $hstsMaxAgeRaw;
            } elseif (is_string($hstsMaxAgeRaw) && is_numeric($hstsMaxAgeRaw)) {
                $hstsMaxAge = (int) $hstsMaxAgeRaw;
            }

            return new SecurityHeadersPolicy(
                enabled: (bool) $config->get('http-basics.security.enabled', true),
                xFrameOptions: is_string($xFrame) && $xFrame !== '' ? $xFrame : 'DENY',
                referrerPolicy: is_string($referrer) && $referrer !== '' ? $referrer : 'strict-origin-when-cross-origin',
                permissionsPolicy: is_string($permissions) && $permissions !== ''
                    ? $permissions
                    : 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
                cspMode: $cspMode,
                csp: is_string($csp) && trim($csp) !== '' ? trim($csp) : null,
                cspReportOnly: is_string($cspReportOnly) && trim($cspReportOnly) !== '' ? trim($cspReportOnly) : null,
                cspReportUri: is_string($cspReportUri) && trim($cspReportUri) !== '' ? trim($cspReportUri) : null,
                hstsMaxAge: $hstsMaxAge,
                hstsIncludeSubdomains: (bool) $config->get('http-basics.security.hsts.include_subdomains', false),
                hstsPreload: (bool) $config->get('http-basics.security.hsts.preload', false),
                baselineOnMachineDoors: (bool) $config->get('http-basics.security.baseline_on_machine_doors', true),
                machineDoorPatterns: $machineDoorPatterns,
            );
        });

        $this->app->singleton(SecurityHeaderBuilder::class, function (Application $app): SecurityHeaderBuilder {
            return new SecurityHeaderBuilder($app->make(CspPolicyBuilder::class));
        });

        $this->app->singleton(CredentialBucketResolver::class, NullCredentialBucketResolver::class);

        $this->app->singleton(Fingerprinter::class, Fingerprinter::class);

        $this->app->singleton(IdempotencyKeyValidator::class, function (Application $app): IdempotencyKeyValidator {
            $config = $this->config($app);
            $formatRaw = $config->get('http-basics.idempotency.key_format', 'uuid');
            $format = is_string($formatRaw) ? IdempotencyKeyFormat::tryFrom($formatRaw) : null;
            if ($format === null) {
                $format = IdempotencyKeyFormat::Uuid;
            }

            $maxLength = $config->get('http-basics.idempotency.max_key_length', 128);

            return new IdempotencyKeyValidator(
                $format,
                is_int($maxLength) ? $maxLength : 128,
            );
        });

        $this->app->singleton(AnonymousBucketResolver::class, function (Application $app): AnonymousBucketResolver {
            $config = $this->config($app);
            $secret = $config->get('http-basics.idempotency.anonymous.secret', '');
            $components = $config->get('http-basics.idempotency.anonymous.components', ['ip', 'user_agent', 'route']);
            if (! is_array($components)) {
                $components = [];
            }

            /** @var list<string> $parts */
            $parts = array_values(array_filter($components, static fn (mixed $c): bool => is_string($c) && $c !== ''));

            return new AnonymousBucketResolver(
                is_string($secret) ? $secret : '',
                $this->normalizeAnonComponents($parts),
            );
        });

        $this->app->singleton(ReplayHeaderSanitizer::class, function (Application $app): ReplayHeaderSanitizer {
            $config = $this->config($app);

            $replay = $config->get('http-basics.idempotency.replay_headers', []);
            $denied = $config->get('http-basics.idempotency.denied_replay_headers', []);

            /** @var list<string> $replayHeaders */
            $replayHeaders = is_array($replay)
                ? array_values(array_filter($replay, static fn (mixed $h): bool => is_string($h) && $h !== ''))
                : [];
            /** @var list<string> $deniedHeaders */
            $deniedHeaders = is_array($denied)
                ? array_values(array_filter($denied, static fn (mixed $h): bool => is_string($h) && $h !== ''))
                : [];

            return new ReplayHeaderSanitizer($replayHeaders, $deniedHeaders);
        });

        $this->app->singleton(ErrorRenderer::class, function (Application $app): ErrorRenderer {
            $url = $this->config($app)->get('http-basics.idempotency.documentation_url');

            return new FleetErrorRenderer(
                is_string($url) ? $url : null,
                $app->make('translator'),
            );
        });

        $this->app->singleton(IdempotencyStore::class, function (Application $app): IdempotencyStore {
            $config = $this->config($app);
            $driver = $config->get('http-basics.idempotency.store', 'cache');
            $ttl = $this->configInt($config, 'http-basics.idempotency.ttl_seconds', 86400);
            $lockTtl = $this->configInt($config, 'http-basics.idempotency.lock_ttl_seconds', 60);
            $staleAfter = $this->configInt($config, 'http-basics.idempotency.stale_after_seconds', 60);

            if ($driver === 'database') {
                $connectionName = $config->get('http-basics.idempotency.database.connection');
                $table = $config->get('http-basics.idempotency.database.table', 'idempotency_records');
                $connection = is_string($connectionName) && $connectionName !== ''
                    ? $app->make('db')->connection($connectionName)
                    : $app->make('db')->connection();

                $cacheStoreName = $config->get('http-basics.idempotency.cache_store');
                $cache = is_string($cacheStoreName) && $cacheStoreName !== ''
                    ? $app->make(CacheFactory::class)->store($cacheStoreName)
                    : $app->make(CacheFactory::class)->store();

                return new DatabaseIdempotencyStore(
                    $connection,
                    $this->cacheRepository($cache),
                    is_string($table) && $table !== '' ? $table : 'idempotency_records',
                    $ttl,
                    $lockTtl,
                    $staleAfter,
                );
            }

            $cacheStoreName = $config->get('http-basics.idempotency.cache_store');
            $cache = is_string($cacheStoreName) && $cacheStoreName !== ''
                ? $app->make(CacheFactory::class)->store($cacheStoreName)
                : $app->make(CacheFactory::class)->store();

            return new CacheIdempotencyStore($this->cacheRepository($cache), $ttl, $lockTtl, $staleAfter);
        });

        $this->app->bind(HandleIdempotencyMiddleware::class, function (Application $app): HandleIdempotencyMiddleware {
            $config = $this->config($app);
            $methods = $config->get('http-basics.idempotency.methods', ['POST', 'PATCH']);
            if (! is_array($methods)) {
                $methods = ['POST', 'PATCH'];
            }

            /** @var list<string> $methodList */
            $methodList = array_values(array_filter(
                $methods,
                static fn (mixed $method): bool => is_string($method) && $method !== '',
            ));

            $replayHeaderRaw = $config->get(
                'http-basics.idempotency.replay_header',
                HandleIdempotencyMiddleware::DEFAULT_REPLAY_HEADER,
            );
            $replayHeader = is_string($replayHeaderRaw) && $replayHeaderRaw !== ''
                ? $replayHeaderRaw
                : null;

            return new HandleIdempotencyMiddleware(
                $app->make(IdempotencyStore::class),
                $app->make(Fingerprinter::class),
                $app->make(IdempotencyKeyValidator::class),
                $app->make(CredentialBucketResolver::class),
                $app->make(AnonymousBucketResolver::class),
                (bool) $config->get('http-basics.idempotency.anonymous.enabled', false),
                $app->make(ErrorRenderer::class),
                $app->make(ReplayHeaderSanitizer::class),
                $app->make(RequestIdResolver::class),
                $app->make(MachineDoorClassifier::class),
                $app->make(LocaleNegotiator::class),
                $methodList,
                (bool) $config->get('http-basics.idempotency.require_json', true),
                $this->configInt($config, 'http-basics.idempotency.max_body_bytes', 1048576),
                $this->configInt($config, 'http-basics.idempotency.max_response_bytes', 1048576),
                $this->configInt($config, 'http-basics.idempotency.lock_ttl_seconds', 60),
                $this->configInt($config, 'http-basics.idempotency.max_handler_seconds', 55),
                $this->configInt($config, 'http-basics.idempotency.stale_after_seconds', 60),
                $replayHeader,
            );
        });
    }

    public function boot(): void
    {
        $this->validateIdempotencyConfig();

        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'http-basics');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/http-basics.php' => config_path('http-basics.php'),
            ], 'http-basics-config');

            $this->publishes([
                __DIR__.'/../../database/migrations/2026_11_22_000001_create_idempotency_records_table.php' => database_path('migrations/2026_11_22_000001_create_idempotency_records_table.php'),
            ], 'http-basics-migrations');

            $this->publishes([
                __DIR__.'/../../lang' => $this->app->langPath('vendor/http-basics'),
            ], 'http-basics-lang');

            $this->commands([
                PruneIdempotencyRecordsCommand::class,
            ]);
        }
    }

    private function validateIdempotencyConfig(): void
    {
        $config = $this->config($this->app);

        $ttl = $this->configInt($config, 'http-basics.idempotency.ttl_seconds', 86400);
        if ($ttl <= 0) {
            throw new \InvalidArgumentException('http-basics.idempotency.ttl_seconds must be greater than 0.');
        }

        $maxBody = $this->configInt($config, 'http-basics.idempotency.max_body_bytes', 1048576);
        if ($maxBody <= 0) {
            throw new \InvalidArgumentException('http-basics.idempotency.max_body_bytes must be greater than 0.');
        }

        $maxResponse = $this->configInt($config, 'http-basics.idempotency.max_response_bytes', 1048576);
        if ($maxResponse <= 0) {
            throw new \InvalidArgumentException('http-basics.idempotency.max_response_bytes must be greater than 0.');
        }

        $lockTtl = $this->configInt($config, 'http-basics.idempotency.lock_ttl_seconds', 60);
        $maxHandler = $this->configInt($config, 'http-basics.idempotency.max_handler_seconds', 55);
        if ($lockTtl < $maxHandler) {
            throw new \InvalidArgumentException(
                'http-basics.idempotency.lock_ttl_seconds must be at least max_handler_seconds.',
            );
        }

        $formatRaw = $config->get('http-basics.idempotency.key_format', 'uuid');
        if (! is_string($formatRaw) || IdempotencyKeyFormat::tryFrom($formatRaw) === null) {
            throw new \InvalidArgumentException('http-basics.idempotency.key_format must be uuid or sf-string.');
        }

        $store = $config->get('http-basics.idempotency.store', 'cache');
        if (! is_string($store) || ! in_array($store, ['cache', 'database'], true)) {
            throw new \InvalidArgumentException('http-basics.idempotency.store must be cache or database.');
        }

        if ((bool) $config->get('http-basics.idempotency.anonymous.enabled', false)) {
            $secret = $config->get('http-basics.idempotency.anonymous.secret');
            if (! is_string($secret) || strlen($secret) < 32) {
                throw new \InvalidArgumentException(
                    'http-basics.idempotency.anonymous.secret must be at least 32 bytes when anonymous idempotency is enabled.',
                );
            }

            $components = $config->get('http-basics.idempotency.anonymous.components', []);
            if (! is_array($components) || $components === []) {
                throw new \InvalidArgumentException(
                    'http-basics.idempotency.anonymous.components must not be empty when anonymous idempotency is enabled.',
                );
            }

            /** @var list<string> $componentList */
            $componentList = array_values(array_filter(
                $components,
                static fn (mixed $component): bool => is_string($component) && $component !== '',
            ));

            $this->normalizeAnonComponents($componentList);
        }
    }

    private function cacheRepository(mixed $cache): CacheRepository
    {
        if ($cache instanceof CacheRepository) {
            return $cache;
        }

        throw new \InvalidArgumentException('The idempotency cache store must be an Illuminate cache repository.');
    }

    private function configInt(ConfigRepository $config, string $key, int $default): int
    {
        $value = $config->get($key, $default);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @param  list<string>  $components
     * @return list<'ip'|'user_agent'|'route'>
     */
    private function normalizeAnonComponents(array $components): array
    {
        $allowed = ['ip', 'user_agent', 'route'];
        $normalized = [];

        foreach ($components as $component) {
            if (! in_array($component, $allowed, true)) {
                throw new \InvalidArgumentException(
                    'http-basics.idempotency.anonymous.components may only contain ip, user_agent, and route.',
                );
            }

            if (! in_array($component, $normalized, true)) {
                $normalized[] = $component;
            }
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException(
                'http-basics.idempotency.anonymous.components must contain at least one valid component.',
            );
        }

        return $normalized;
    }

    private function config(Application $app): ConfigRepository
    {
        return $app->make(ConfigRepository::class);
    }
}
