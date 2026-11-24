<?php

declare(strict_types=1);

/*
 * Every binding of HttpBasicsServiceProvider reads its own config key and passes
 * it to the right constructor argument; every boot check refuses its bad value.
 */

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Qiick\HttpBasics\Contracts\DefaultLocaleResolver;
use Qiick\HttpBasics\Contracts\ErrorRenderer;
use Qiick\HttpBasics\Contracts\IdempotencyStore;
use Qiick\HttpBasics\Contracts\SupportedLocales;
use Qiick\HttpBasics\Contracts\TrustedProxyDetector;
use Qiick\HttpBasics\Errors\ExceptionErrorCodeAugmenter;
use Qiick\HttpBasics\Errors\ProblemDetailsErrorRenderer;
use Qiick\HttpBasics\Errors\ProblemInstanceResolver;
use Qiick\HttpBasics\Idempotency\AnonymousBucketResolver;
use Qiick\HttpBasics\Idempotency\CacheIdempotencyStore;
use Qiick\HttpBasics\Idempotency\DatabaseIdempotencyStore;
use Qiick\HttpBasics\Idempotency\FleetErrorRenderer;
use Qiick\HttpBasics\Idempotency\HandleIdempotencyMiddleware;
use Qiick\HttpBasics\Idempotency\IdempotencyKeyFormat;
use Qiick\HttpBasics\Idempotency\IdempotencyKeyValidator;
use Qiick\HttpBasics\Idempotency\ReplayHeaderSanitizer;
use Qiick\HttpBasics\Laravel\HttpBasicsServiceProvider;
use Qiick\HttpBasics\Locale\LocaleNegotiator;
use Qiick\HttpBasics\Locale\MachineDoorClassifier;
use Qiick\HttpBasics\RequestId\RequestIdAcceptFrom;
use Qiick\HttpBasics\RequestId\RequestIdPropagator;
use Qiick\HttpBasics\RequestId\RequestIdResolver;
use Qiick\HttpBasics\Security\CspMode;
use Qiick\HttpBasics\Security\SecurityHeadersPolicy;
use Qiick\HttpBasics\Uploads\UploadPolicyResolver;

/**
 * Reads a private property: the bindings expose no getters for what they were built with.
 */
function wiredProperty(object $object, string $property): mixed
{
    return (fn (): mixed => $this->{$property})->call($object);
}

function bootProvider(): void
{
    /** @var HttpBasicsServiceProvider $provider */
    $provider = app()->getProvider(HttpBasicsServiceProvider::class);
    $provider->boot();
}

it('wires the request-id services from config', function (): void {
    config([
        'http-basics.request_id.trust_wildcard_proxies' => true,
        'http-basics.request_id.accept_from' => 'never',
        'http-basics.request_id.hidden_context_keys' => ['a.key', ''],
        'http-basics.request_id.accept_pattern' => '/\A[a-z]{8}\z/',
        'http-basics.request_id.outbound.allowed_hosts' => ['api.test'],
    ]);
    $resolver = app(RequestIdResolver::class);

    expect(wiredProperty(app(TrustedProxyDetector::class), 'trustWildcardProxies'))->toBeTrue()
        ->and(wiredProperty($resolver, 'acceptFrom'))->toBe(RequestIdAcceptFrom::Never)
        ->and(wiredProperty($resolver, 'hiddenContextKeys'))->toBe(['a.key'])
        ->and(wiredProperty($resolver, 'acceptPattern'))->toBe('/\A[a-z]{8}\z/')
        ->and(wiredProperty($resolver, 'provenanceContextKey'))->toBe('http_basics.request_id_source')
        ->and(wiredProperty($resolver, 'proxies'))->toBe(app(TrustedProxyDetector::class))
        ->and(wiredProperty(app(RequestIdPropagator::class), 'allowedHosts'))->toBe(['api.test'])
        ->and(wiredProperty(app(RequestIdPropagator::class), 'resolver'))->toBe($resolver);
});

it('refuses an unknown accept_from', function (mixed $acceptFrom): void {
    config(['http-basics.request_id.accept_from' => $acceptFrom]);

    expect(fn () => app(RequestIdResolver::class))
        ->toThrow(InvalidArgumentException::class, 'http-basics.request_id.accept_from must be trusted_proxy, any, or never.');
})->with(['unknown' => ['sometimes'], 'not a string' => [['never']]]);

it('wires locale negotiation from config', function (): void {
    config([
        'http-basics.locale.supported' => ['it', 'en'],
        'http-basics.locale.machine_doors' => ['v1/*'],
        'http-basics.locale.cookie' => 'lang',
        'http-basics.locale.fallback' => 'it',
        'http-basics.locale.max_header_bytes' => 512,
        'http-basics.locale.max_languages' => 5,
        'app.locale' => 'it',
    ]);
    $negotiator = app(LocaleNegotiator::class);

    expect(wiredProperty(app(SupportedLocales::class), 'locales'))->toBe(['it', 'en'])
        ->and(wiredProperty(app(DefaultLocaleResolver::class), 'locale'))->toBe('it')
        ->and(wiredProperty(app(MachineDoorClassifier::class), 'patterns'))->toBe(['v1/*'])
        ->and(wiredProperty($negotiator, 'supported'))->toBe(app(SupportedLocales::class))
        ->and(wiredProperty($negotiator, 'default'))->toBe(app(DefaultLocaleResolver::class))
        ->and(wiredProperty($negotiator, 'doors'))->toBe(app(MachineDoorClassifier::class))
        ->and(wiredProperty($negotiator, 'cookieName'))->toBe('lang')
        ->and(wiredProperty($negotiator, 'fallbackLocale'))->toBe('it')
        ->and(wiredProperty($negotiator, 'maxHeaderBytes'))->toBe(512)
        ->and(wiredProperty($negotiator, 'maxLanguages'))->toBe(5);
});

it('falls back to the app locales, once each, when no locale list is configured', function (mixed $appLocale, mixed $fallbackLocale, array $expected): void {
    config(['http-basics.locale.supported' => [], 'app.locale' => $appLocale, 'app.fallback_locale' => $fallbackLocale]);

    expect(wiredProperty(app(SupportedLocales::class), 'locales'))->toBe($expected);
})->with([
    'two locales' => ['it', 'en', ['it', 'en']],
    'the same locale twice' => ['en', 'en', ['en']],
    'unusable values' => ['', null, []],
    'an unusable app locale' => ['', 'en', ['en']],
]);

it('keeps the first of each repeated anonymous component, as a list', function (): void {
    config(['http-basics.idempotency.anonymous.components' => ['ip', 'ip', 'route']]);

    expect(wiredProperty(app(AnonymousBucketResolver::class), 'components'))->toBe(['ip', 'route']);
});

it('defaults to en when the app locale is unusable', function (mixed $appLocale): void {
    config(['app.locale' => $appLocale]);

    expect(wiredProperty(app(DefaultLocaleResolver::class), 'locale'))->toBe('en');
})->with(['', null]);

it('wires the security headers policy from config', function (): void {
    config([
        'http-basics.security.enabled' => false,
        'http-basics.security.x_frame_options' => 'SAMEORIGIN',
        'http-basics.security.referrer_policy' => 'no-referrer',
        'http-basics.security.permissions_policy' => 'camera=()',
        'http-basics.security.csp.mode' => 'both',
        'http-basics.security.csp.policy' => " default-src 'none' ",
        'http-basics.security.csp.report_only_policy' => "script-src 'self'",
        'http-basics.security.csp.report_uri' => 'https://report.test',
        'http-basics.security.hsts.max_age' => '600',
        'http-basics.security.hsts.include_subdomains' => true,
        'http-basics.security.hsts.preload' => true,
        'http-basics.security.baseline_on_machine_doors' => false,
        'http-basics.locale.machine_doors' => ['v1/*'],
    ]);

    expect(app(SecurityHeadersPolicy::class))->toEqual(new SecurityHeadersPolicy(
        enabled: false,
        xFrameOptions: 'SAMEORIGIN',
        referrerPolicy: 'no-referrer',
        permissionsPolicy: 'camera=()',
        cspMode: CspMode::Both,
        csp: "default-src 'none'",
        cspReportOnly: "script-src 'self'",
        cspReportUri: 'https://report.test',
        hstsMaxAge: 600,
        hstsIncludeSubdomains: true,
        hstsPreload: true,
        baselineOnMachineDoors: false,
        machineDoorPatterns: ['v1/*'],
    ));
});

it('refuses an unknown CSP mode', function (mixed $mode): void {
    config(['http-basics.security.csp.mode' => $mode]);

    expect(fn () => app(SecurityHeadersPolicy::class))
        ->toThrow(InvalidArgumentException::class, 'http-basics.security.csp.mode must be enforce, report-only, off, or both.');
})->with(['unknown' => ['loud'], 'null' => [null]]);

it('wires the idempotency helpers from config', function (): void {
    config([
        'http-basics.idempotency.key_format' => 'sf-string',
        'http-basics.idempotency.max_key_length' => 64,
        'http-basics.idempotency.anonymous.secret' => '  untrimmed-secret  ',
        'http-basics.idempotency.anonymous.components' => ['route', 'ip', 'route'],
        'http-basics.idempotency.replay_headers' => ['Content-Type'],
        'http-basics.idempotency.denied_replay_headers' => ['X-Secret'],
        'http-basics.idempotency.documentation_url' => 'https://docs.test',
        'http-basics.uploads.contexts' => ['files' => ['max_bytes' => 10]],
        'http-basics.uploads.magic_signatures' => ['pdf' => ['25504446']],
    ]);

    expect(wiredProperty(app(IdempotencyKeyValidator::class), 'format'))->toBe(IdempotencyKeyFormat::SfString)
        ->and(wiredProperty(app(IdempotencyKeyValidator::class), 'maxLength'))->toBe(64)
        ->and(wiredProperty(app(AnonymousBucketResolver::class), 'secret'))->toBe('  untrimmed-secret  ')
        ->and(wiredProperty(app(AnonymousBucketResolver::class), 'components'))->toBe(['route', 'ip'])
        ->and(wiredProperty(app(ReplayHeaderSanitizer::class), 'replayAllowlist'))->toBe(['Content-Type'])
        ->and(wiredProperty(app(ReplayHeaderSanitizer::class), 'deniedHeaders'))->toBe(['X-Secret'])
        ->and(wiredProperty(app(FleetErrorRenderer::class), 'documentationUrl'))->toBe('https://docs.test')
        ->and(wiredProperty(app(FleetErrorRenderer::class), 'translator'))->toBe(app('translator'))
        ->and(wiredProperty(app(UploadPolicyResolver::class), 'contexts'))->toBe(['files' => ['max_bytes' => 10]])
        ->and(wiredProperty(app(UploadPolicyResolver::class), 'defaultMagic'))->toBe(['pdf' => ['25504446']]);
});

it('treats unusable secrets and upload maps as empty', function (): void {
    config([
        'http-basics.idempotency.anonymous.secret' => null,
        'http-basics.uploads.contexts' => 'files',
        'http-basics.uploads.magic_signatures' => 'pdf',
    ]);

    expect(wiredProperty(app(AnonymousBucketResolver::class), 'secret'))->toBe('')
        ->and(wiredProperty(app(UploadPolicyResolver::class), 'contexts'))->toBe([])
        ->and(wiredProperty(app(UploadPolicyResolver::class), 'defaultMagic'))->toBe([]);
});

it('picks the error renderer and the augmenter mode from errors.format', function (string $format, string $renderer, bool $hasProblemDetails): void {
    config(['http-basics.errors.format' => $format, 'http-basics.errors.problem_type_base_uri' => 'https://errors.test/']);

    expect(app(ErrorRenderer::class))->toBeInstanceOf($renderer)
        ->and(wiredProperty(app(ExceptionErrorCodeAugmenter::class), 'problemDetails') !== null)->toBe($hasProblemDetails)
        ->and(wiredProperty(app(ExceptionErrorCodeAugmenter::class), 'machineDoors'))->toBe(app(MachineDoorClassifier::class))
        ->and(wiredProperty(app(ProblemDetailsErrorRenderer::class), 'problemTypeBaseUri'))->toBe('https://errors.test/')
        ->and(wiredProperty(app(ProblemDetailsErrorRenderer::class), 'fleet'))->toBe(app(FleetErrorRenderer::class))
        ->and(wiredProperty(app(ProblemDetailsErrorRenderer::class), 'translator'))->toBe(app('translator'))
        ->and(wiredProperty(app(ProblemDetailsErrorRenderer::class), 'instances'))->toBeNull();
})->with([
    'fleet' => ['fleet', FleetErrorRenderer::class, false],
    'problem details' => ['problem_details', ProblemDetailsErrorRenderer::class, true],
]);

it('hands the problem renderer a bound instance resolver', function (): void {
    $instances = new class implements ProblemInstanceResolver
    {
        public function instance(Request $request, string $errorCode): ?string
        {
            return null;
        }
    };
    app()->instance(ProblemInstanceResolver::class, $instances);

    expect(wiredProperty(app(ProblemDetailsErrorRenderer::class), 'instances'))->toBe($instances);
});

it('builds the cache store from config', function (): void {
    config([
        'http-basics.idempotency.store' => 'cache',
        'http-basics.idempotency.cache_store' => 'array',
        'http-basics.idempotency.ttl_seconds' => 100,
        'http-basics.idempotency.lock_ttl_seconds' => 20,
        'http-basics.idempotency.stale_after_seconds' => 10,
    ]);
    $store = app(IdempotencyStore::class);

    expect($store)->toBeInstanceOf(CacheIdempotencyStore::class)
        ->and(wiredProperty($store, 'cache'))->toBe(cache()->store('array'))
        ->and(wiredProperty($store, 'ttlSeconds'))->toBe(100)
        ->and(wiredProperty($store, 'lockTtlSeconds'))->toBe(20)
        ->and(wiredProperty($store, 'staleAfterSeconds'))->toBe(10);
});

it('builds the database store from config', function (): void {
    config([
        'database.connections.idempotency' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'http-basics.idempotency.store' => 'database',
        'http-basics.idempotency.database.connection' => 'idempotency',
        'http-basics.idempotency.database.table' => 'idempotency_custom',
        'http-basics.idempotency.cache_store' => 'array',
        'http-basics.idempotency.ttl_seconds' => 100,
        'http-basics.idempotency.lock_ttl_seconds' => 20,
        'http-basics.idempotency.stale_after_seconds' => 10,
    ]);
    $store = app(IdempotencyStore::class);

    expect($store)->toBeInstanceOf(DatabaseIdempotencyStore::class)
        ->and(wiredProperty($store, 'connection')->getName())->toBe('idempotency')
        ->and(wiredProperty($store, 'cache'))->toBe(cache()->store('array'))
        ->and(wiredProperty($store, 'table'))->toBe('idempotency_custom')
        ->and(wiredProperty($store, 'ttlSeconds'))->toBe(100)
        ->and(wiredProperty($store, 'lockTtlSeconds'))->toBe(20)
        ->and(wiredProperty($store, 'staleAfterSeconds'))->toBe(10);
});

it('wires the idempotency middleware from config', function (): void {
    config([
        'http-basics.idempotency.anonymous.enabled' => true,
        'http-basics.idempotency.anonymous.secret' => str_repeat('s', 32),
        'http-basics.idempotency.methods' => ['PUT', ''],
        'http-basics.idempotency.require_json' => false,
        'http-basics.idempotency.max_body_bytes' => 111,
        'http-basics.idempotency.max_response_bytes' => 222,
        'http-basics.idempotency.lock_ttl_seconds' => 33,
        'http-basics.idempotency.max_handler_seconds' => 22,
        'http-basics.idempotency.stale_after_seconds' => 44,
        'http-basics.idempotency.replay_header' => 'X-Replayed',
    ]);
    $middleware = app(HandleIdempotencyMiddleware::class);

    expect(wiredProperty($middleware, 'store'))->toBe(app(IdempotencyStore::class))
        ->and(wiredProperty($middleware, 'keyValidator'))->toBe(app(IdempotencyKeyValidator::class))
        ->and(wiredProperty($middleware, 'anonymousBuckets'))->toBe(app(AnonymousBucketResolver::class))
        ->and(wiredProperty($middleware, 'anonymousEnabled'))->toBeTrue()
        ->and(wiredProperty($middleware, 'errorRenderer'))->toBe(app(ErrorRenderer::class))
        ->and(wiredProperty($middleware, 'headerSanitizer'))->toBe(app(ReplayHeaderSanitizer::class))
        ->and(wiredProperty($middleware, 'requestIds'))->toBe(app(RequestIdResolver::class))
        ->and(wiredProperty($middleware, 'machineDoors'))->toBe(app(MachineDoorClassifier::class))
        ->and(wiredProperty($middleware, 'locale'))->toBe(app(LocaleNegotiator::class))
        ->and(wiredProperty($middleware, 'methods'))->toBe(['PUT'])
        ->and(wiredProperty($middleware, 'requireJson'))->toBeFalse()
        ->and(wiredProperty($middleware, 'maxBodyBytes'))->toBe(111)
        ->and(wiredProperty($middleware, 'maxResponseBytes'))->toBe(222)
        ->and(wiredProperty($middleware, 'defaultLockTtlSeconds'))->toBe(33)
        ->and(wiredProperty($middleware, 'defaultMaxHandlerSeconds'))->toBe(22)
        ->and(wiredProperty($middleware, 'retryAfterSeconds'))->toBe(44)
        ->and(wiredProperty($middleware, 'replayHeaderName'))->toBe('X-Replayed');
});

it('turns the replay header off when it is empty', function (): void {
    config(['http-basics.idempotency.replay_header' => '']);

    expect(wiredProperty(app(HandleIdempotencyMiddleware::class), 'replayHeaderName'))->toBeNull();
});

it('refuses idempotency config that cannot work, at boot', function (array $config, string $message): void {
    config($config);

    expect(fn () => bootProvider())->toThrow(InvalidArgumentException::class, $message);
})->with([
    'ttl zero' => [['http-basics.idempotency.ttl_seconds' => 0], 'http-basics.idempotency.ttl_seconds must be greater than 0.'],
    'body cap zero' => [['http-basics.idempotency.max_body_bytes' => 0], 'http-basics.idempotency.max_body_bytes must be greater than 0.'],
    'response cap zero' => [['http-basics.idempotency.max_response_bytes' => 0], 'http-basics.idempotency.max_response_bytes must be greater than 0.'],
    'lock not above handler' => [['http-basics.idempotency.lock_ttl_seconds' => 30, 'http-basics.idempotency.max_handler_seconds' => 30], 'lock_ttl_seconds must be greater than max_handler_seconds.'],
    'unknown key format' => [['http-basics.idempotency.key_format' => 'ulid'], 'key_format must be uuid or sf-string.'],
    'key format not a string' => [['http-basics.idempotency.key_format' => ['uuid']], 'key_format must be uuid or sf-string.'],
    'unknown store' => [['http-basics.idempotency.store' => 'redis'], 'store must be cache or database.'],
    'anon secret too short' => [['http-basics.idempotency.anonymous.enabled' => true, 'http-basics.idempotency.anonymous.secret' => str_repeat('s', 31)], 'secret must be at least 32 bytes'],
    'anon secret missing' => [['http-basics.idempotency.anonymous.enabled' => true, 'http-basics.idempotency.anonymous.secret' => null], 'secret must be at least 32 bytes'],
    'anon unknown component' => [['http-basics.idempotency.anonymous.enabled' => true, 'http-basics.idempotency.anonymous.secret' => str_repeat('s', 32), 'http-basics.idempotency.anonymous.components' => ['ip', 'cookie']], 'may only contain ip, user_agent, and route.'],
    'anon no component' => [['http-basics.idempotency.anonymous.enabled' => true, 'http-basics.idempotency.anonymous.secret' => str_repeat('s', 32), 'http-basics.idempotency.anonymous.components' => ['']], 'must contain at least one valid component.'],
    'unknown error format' => [['http-basics.errors.format' => 'xml'], 'http-basics.errors.format must be fleet or problem_details.'],
]);

it('accepts the smallest valid idempotency config, and skips anonymous checks when it is off', function (): void {
    config([
        'http-basics.idempotency.ttl_seconds' => 1,
        'http-basics.idempotency.max_body_bytes' => 1,
        'http-basics.idempotency.max_response_bytes' => 1,
        'http-basics.idempotency.lock_ttl_seconds' => 2,
        'http-basics.idempotency.max_handler_seconds' => 1,
        'http-basics.idempotency.anonymous.enabled' => false,
        'http-basics.idempotency.anonymous.secret' => 'short',
        'http-basics.idempotency.anonymous.components' => ['cookie'],
    ]);

    bootProvider();

    config(['http-basics.idempotency.anonymous.enabled' => true, 'http-basics.idempotency.anonymous.secret' => str_repeat('s', 32), 'http-basics.idempotency.anonymous.components' => ['ip']]);

    bootProvider();

    expect(true)->toBeTrue();
});

it('normalises the cors config at boot', function (): void {
    config(['cors' => ['paths' => ['api/*'], 'allowed_origins' => ['HTTPS://App.Example.com'], 'supports_credentials' => false]]);

    bootProvider();

    expect(config('cors.allowed_origins'))->toBe(['https://app.example.com']);
});

it('leaves a cors config that is not a list alone', function (): void {
    config(['cors' => 'off']);

    bootProvider();

    expect(config('cors'))->toBe('off');
});

it('registers the prune command and publishes the lang files', function (): void {
    expect(array_key_exists('http-basics:prune-idempotency', Artisan::all()))->toBeTrue()
        ->and(ServiceProvider::pathsToPublish(HttpBasicsServiceProvider::class, 'http-basics-lang'))
        ->toBe([dirname(__DIR__, 2).'/src/Laravel/../../lang' => app()->langPath('vendor/http-basics')]);
});

it('refuses a cache store that is not an Illuminate repository', function (): void {
    $factory = Mockery::mock(Factory::class);
    $factory->shouldReceive('store')->andReturn(Mockery::mock(Repository::class));
    app()->instance(Factory::class, $factory);

    expect(fn () => app(IdempotencyStore::class))
        ->toThrow(InvalidArgumentException::class, 'The idempotency cache store must be an Illuminate cache repository.');
});
