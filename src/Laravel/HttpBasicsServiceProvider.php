<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Laravel;

use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Qiick\HttpBasics\Config\PackageConfig;
use Qiick\HttpBasics\Config\PackageConfigValidator;
use Qiick\HttpBasics\Console\CheckHttpBasicsCommand;
use Qiick\HttpBasics\Console\PruneIdempotencyRecordsCommand;
use Qiick\HttpBasics\Contracts\CredentialBucketResolver;
use Qiick\HttpBasics\Contracts\DefaultLocaleResolver;
use Qiick\HttpBasics\Contracts\ErrorRenderer;
use Qiick\HttpBasics\Contracts\IdempotencyStore;
use Qiick\HttpBasics\Contracts\IdGenerator;
use Qiick\HttpBasics\Contracts\SupportedLocales;
use Qiick\HttpBasics\Contracts\TrustedProxyDetector;
use Qiick\HttpBasics\Cors\AllowedOriginsResolver;
use Qiick\HttpBasics\Cors\NullAllowedOriginsResolver;
use Qiick\HttpBasics\Errors\ErrorCodeResolver;
use Qiick\HttpBasics\Errors\ExceptionErrorCodeAugmenter;
use Qiick\HttpBasics\Errors\FrameworkErrorCodeResolver;
use Qiick\HttpBasics\Errors\ProblemDetailsErrorRenderer;
use Qiick\HttpBasics\Errors\ProblemInstanceResolver;
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
use Qiick\HttpBasics\RequestId\RequestIdPropagator;
use Qiick\HttpBasics\RequestId\RequestIdResolver;
use Qiick\HttpBasics\RequestId\UuidIdGenerator;
use Qiick\HttpBasics\Security\CspNonceProvider;
use Qiick\HttpBasics\Security\CspPolicyBuilder;
use Qiick\HttpBasics\Security\RequestCspNonceProvider;
use Qiick\HttpBasics\Security\SecurityHeaderBuilder;
use Qiick\HttpBasics\Security\SecurityHeadersPolicy;
use Qiick\HttpBasics\Security\ViteFriendlyCspPolicyBuilder;
use Qiick\HttpBasics\Uploads\ConfigUploadPolicyResolver;
use Qiick\HttpBasics\Uploads\FileNameSanitizer;
use Qiick\HttpBasics\Uploads\HardFloor;
use Qiick\HttpBasics\Uploads\MalwareScanner;
use Qiick\HttpBasics\Uploads\NullMalwareScanner;
use Qiick\HttpBasics\Uploads\UploadPolicyResolver;
use Qiick\HttpBasics\Uploads\UploadValidator;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputOption;

/**
 * @api Registers the package in a Laravel app. Auto-discovered through
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

        $this->app->singleton(TrustedProxyDetector::class, fn (Application $app): TrustedProxyDetector => new ConfigTrustedProxyDetector(
            $this->config($app),
            $this->settings($app)->bool('request_id.trust_wildcard_proxies'),
        ));

        $this->app->singleton(RequestIdResolver::class, function (Application $app): RequestIdResolver {
            $settings = $this->settings($app);

            return new RequestIdResolver(
                $app->make(IdGenerator::class),
                $app->make(TrustedProxyDetector::class),
                $settings->requestIdAcceptFrom(),
                $settings->stringList('request_id.hidden_context_keys'),
                'http_basics.request_id_source',
                $settings->string('request_id.accept_pattern'),
            );
        });

        $this->app->singleton(RequestIdPropagator::class, fn (Application $app): RequestIdPropagator => new RequestIdPropagator(
            $this->settings($app)->stringList('request_id.outbound.allowed_hosts'),
            $app->make(RequestIdResolver::class),
        ));

        $this->app->singleton(SupportedLocales::class, function (Application $app): SupportedLocales {
            $locales = $this->settings($app)->stringList('locale.supported');

            // No configured list: the app's own locales, without repeats.
            if ($locales === []) {
                $config = $this->config($app);
                $candidates = [$config->get('app.locale'), $config->get('app.fallback_locale')];
                $locales = array_values(array_unique(array_filter(
                    $candidates,
                    static fn (mixed $locale): bool => is_string($locale) && $locale !== '',
                )));
            }

            return new ConfigSupportedLocales($locales);
        });

        $this->app->singleton(DefaultLocaleResolver::class, function (Application $app): DefaultLocaleResolver {
            $locale = $this->config($app)->get('app.locale');

            return new ConfigDefaultLocaleResolver(is_string($locale) && $locale !== '' ? $locale : 'en');
        });

        $this->app->singleton(MachineDoorClassifier::class, fn (Application $app): MachineDoorClassifier => new MachineDoorClassifier(
            $this->settings($app)->stringList('locale.machine_doors'),
        ));

        $this->app->singleton(LocaleNegotiator::class, function (Application $app): LocaleNegotiator {
            $settings = $this->settings($app);

            return new LocaleNegotiator(
                $app->make(SupportedLocales::class),
                $app->make(DefaultLocaleResolver::class),
                $app->make(MachineDoorClassifier::class),
                $settings->string('locale.cookie'),
                $settings->string('locale.fallback'),
                $settings->int('locale.max_header_bytes'),
                $settings->int('locale.max_languages'),
            );
        });

        $this->app->singleton(CspNonceProvider::class, RequestCspNonceProvider::class);

        $this->app->singleton(CspPolicyBuilder::class, fn (Application $app): CspPolicyBuilder => new ViteFriendlyCspPolicyBuilder(
            $this->settings($app)->bool('security.csp.nonce') ? $app->make(CspNonceProvider::class) : null,
            $this->settings($app)->stringList('security.csp.script_hashes'),
        ));

        $this->app->singleton(SecurityHeadersPolicy::class, function (Application $app): SecurityHeadersPolicy {
            $settings = $this->settings($app);

            return new SecurityHeadersPolicy(
                enabled: $settings->bool('security.enabled'),
                xFrameOptions: $settings->string('security.x_frame_options'),
                referrerPolicy: $settings->string('security.referrer_policy'),
                permissionsPolicy: $settings->string('security.permissions_policy'),
                cspMode: $settings->cspMode(),
                csp: $settings->optionalString('security.csp.policy'),
                cspReportOnly: $settings->optionalString('security.csp.report_only_policy'),
                cspReportUri: $settings->optionalString('security.csp.report_uri'),
                hstsMaxAge: $settings->int('security.hsts.max_age'),
                hstsIncludeSubdomains: $settings->bool('security.hsts.include_subdomains'),
                hstsPreload: $settings->bool('security.hsts.preload'),
                baselineOnMachineDoors: $settings->bool('security.baseline_on_machine_doors'),
                cspNonce: $settings->bool('security.csp.nonce'),
                machineDoorPatterns: $settings->stringList('locale.machine_doors'),
            );
        });

        $this->app->singleton(SecurityHeaderBuilder::class, fn (Application $app): SecurityHeaderBuilder => new SecurityHeaderBuilder(
            $app->make(CspPolicyBuilder::class),
        ));

        $this->app->singleton(CredentialBucketResolver::class, NullCredentialBucketResolver::class);

        $this->app->singleton(Fingerprinter::class, Fingerprinter::class);

        // An unknown key format fails at boot with a clear message.
        $this->app->singleton(IdempotencyKeyValidator::class, fn (Application $app): IdempotencyKeyValidator => new IdempotencyKeyValidator(
            IdempotencyKeyFormat::from($this->settings($app)->string('idempotency.key_format')),
            $this->settings($app)->int('idempotency.max_key_length'),
        ));

        $this->app->singleton(AnonymousBucketResolver::class, function (Application $app): AnonymousBucketResolver {
            $settings = $this->settings($app);
            // Never trimmed or defaulted: the secret is used exactly as configured.
            $secret = $settings->value('idempotency.anonymous.secret');

            return new AnonymousBucketResolver(
                is_string($secret) ? $secret : '',
                PackageConfigValidator::normalizeAnonymousComponents($settings->stringList('idempotency.anonymous.components')),
            );
        });

        $this->app->singleton(ReplayHeaderSanitizer::class, fn (Application $app): ReplayHeaderSanitizer => new ReplayHeaderSanitizer(
            $this->settings($app)->stringList('idempotency.replay_headers'),
            $this->settings($app)->stringList('idempotency.denied_replay_headers'),
        ));

        $this->app->singleton(MalwareScanner::class, NullMalwareScanner::class);
        $this->app->singleton(HardFloor::class);
        $this->app->singleton(FileNameSanitizer::class);
        $this->app->singleton(UploadPolicyResolver::class, function (Application $app): UploadPolicyResolver {
            $settings = $this->settings($app);
            $contexts = $settings->value('uploads.contexts');
            $magic = $settings->value('uploads.magic_signatures');

            return new ConfigUploadPolicyResolver(
                is_array($contexts) ? $contexts : [],
                is_array($magic) ? $magic : [],
            );
        });
        $this->app->singleton(UploadValidator::class, fn (Application $app): UploadValidator => new UploadValidator(
            $app->make(UploadPolicyResolver::class),
            $app->make(FileNameSanitizer::class),
            $app->make(HardFloor::class),
            $app->make(MalwareScanner::class),
            $app->make(IdGenerator::class),
        ));
        $this->app->singleton(AllowedOriginsResolver::class, NullAllowedOriginsResolver::class);
        $this->app->singleton(ErrorCodeResolver::class, FrameworkErrorCodeResolver::class);

        $this->app->singleton(FleetErrorRenderer::class, fn (Application $app): FleetErrorRenderer => new FleetErrorRenderer(
            $this->settings($app)->optionalString('idempotency.documentation_url'),
            $app->make('translator'),
        ));

        $this->app->singleton(ProblemDetailsErrorRenderer::class, fn (Application $app): ProblemDetailsErrorRenderer => new ProblemDetailsErrorRenderer(
            $app->make(FleetErrorRenderer::class),
            $this->settings($app)->optionalString('errors.problem_type_base_uri'),
            $app->bound(ProblemInstanceResolver::class) ? $app->make(ProblemInstanceResolver::class) : null,
            $app->make('translator'),
        ));

        $this->app->singleton(ErrorRenderer::class, fn (Application $app): ErrorRenderer => $this->usesProblemDetails($app)
            ? $app->make(ProblemDetailsErrorRenderer::class)
            : $app->make(FleetErrorRenderer::class));

        $this->app->singleton(ExceptionErrorCodeAugmenter::class, fn (Application $app): ExceptionErrorCodeAugmenter => new ExceptionErrorCodeAugmenter(
            $app->make(ErrorCodeResolver::class),
            $app->make(MachineDoorClassifier::class),
            problemDetails: $this->usesProblemDetails($app) ? $app->make(ProblemDetailsErrorRenderer::class) : null,
        ));

        $this->app->singleton(IdempotencyStore::class, function (Application $app): IdempotencyStore {
            $settings = $this->settings($app);
            $cacheStoreName = $settings->optionalString('idempotency.cache_store');
            $cache = $this->cacheRepository($app->make(CacheFactory::class)->store($cacheStoreName));
            $ttl = $settings->int('idempotency.ttl_seconds');
            $lockTtl = $settings->int('idempotency.lock_ttl_seconds');
            $staleAfter = $settings->int('idempotency.stale_after_seconds');

            if ($settings->value('idempotency.store') !== 'database') {
                return new CacheIdempotencyStore($cache, $ttl, $lockTtl, $staleAfter);
            }

            return new DatabaseIdempotencyStore(
                $app->make('db')->connection($settings->optionalString('idempotency.database.connection')),
                $cache,
                $settings->string('idempotency.database.table'),
                $ttl,
                $lockTtl,
                $staleAfter,
            );
        });

        $this->app->bind(HandleIdempotencyMiddleware::class, function (Application $app): HandleIdempotencyMiddleware {
            $settings = $this->settings($app);

            return new HandleIdempotencyMiddleware(
                $app->make(IdempotencyStore::class),
                $app->make(Fingerprinter::class),
                $app->make(IdempotencyKeyValidator::class),
                $app->make(CredentialBucketResolver::class),
                $app->make(AnonymousBucketResolver::class),
                $settings->bool('idempotency.anonymous.enabled'),
                $app->make(ErrorRenderer::class),
                $app->make(ReplayHeaderSanitizer::class),
                $app->make(RequestIdResolver::class),
                $app->make(MachineDoorClassifier::class),
                $app->make(LocaleNegotiator::class),
                $settings->stringList('idempotency.methods'),
                $settings->bool('idempotency.require_json'),
                $settings->int('idempotency.max_body_bytes'),
                $settings->int('idempotency.max_response_bytes'),
                $settings->int('idempotency.lock_ttl_seconds'),
                $settings->int('idempotency.max_handler_seconds'),
                $settings->int('idempotency.stale_after_seconds'),
                // Set blank or null, the replay header is off; a missing key keeps it on.
                $settings->optionalString('idempotency.replay_header'),
            );
        });
    }

    public function boot(): void
    {
        // This command needs to finish boot so it can print every validation problem.
        if (! $this->isRunningCheckCommand()) {
            foreach ($this->app->make(PackageConfigValidator::class)->problems(shouldNormalizeCors: true) as $problem) {
                throw $problem;
            }
        }

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
                CheckHttpBasicsCommand::class,
                PruneIdempotencyRecordsCommand::class,
            ]);

            $settings = $this->settings($this->app);

            if ($settings->value('idempotency.store') === 'database' && $settings->bool('idempotency.schedule')) {
                $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                    $schedule->command(PruneIdempotencyRecordsCommand::class)
                        ->daily()
                        ->onOneServer()
                        ->withoutOverlapping();
                });
            }
        }
    }

    private function isRunningCheckCommand(): bool
    {
        $arguments = $_SERVER['argv'] ?? null;

        if (! $this->app->runningInConsole() || ! is_array($arguments) || ! in_array('http-basics:check', $arguments, true)) {
            return false;
        }

        // Bind the global options so a value such as --env http-basics:check cannot select the command.
        $definition = (new ConsoleApplication)->getDefinition();
        $definition->addOption(new InputOption('env', mode: InputOption::VALUE_OPTIONAL));

        try {
            $input = new ArgvInput(definition: $definition);
        } catch (ConsoleException) {
            return false;
        }

        return $input->getArgument('command') === 'http-basics:check';
    }

    private function usesProblemDetails(Application $app): bool
    {
        return $this->settings($app)->value('errors.format') === 'problem_details';
    }

    private function cacheRepository(mixed $cache): CacheRepository
    {
        if ($cache instanceof CacheRepository) {
            return $cache;
        }

        throw new \InvalidArgumentException('The idempotency cache store must be an Illuminate cache repository.');
    }

    private function config(Application $app): ConfigRepository
    {
        return $app->make(ConfigRepository::class);
    }

    private function settings(Application $app): PackageConfig
    {
        return new PackageConfig($this->config($app));
    }
}
