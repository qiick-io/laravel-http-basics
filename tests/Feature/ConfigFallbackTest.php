<?php

declare(strict_types=1);

/*
 * What a product's config does when a key is blank, null, invalid or missing.
 * Blank or null security headers fall back to the safe default, never to an
 * empty header or a per-request exception; a key missing from a block a product
 * published or wrote by hand takes the shipped default; a present but invalid
 * enum fails at boot, not on the first request.
 */

use Illuminate\Support\Env;
use Qiick\HttpBasics\Contracts\ErrorRenderer;
use Qiick\HttpBasics\Contracts\IdempotencyStore;
use Qiick\HttpBasics\Idempotency\CacheIdempotencyStore;
use Qiick\HttpBasics\Idempotency\FleetErrorRenderer;
use Qiick\HttpBasics\Idempotency\HandleIdempotencyMiddleware;
use Qiick\HttpBasics\Idempotency\IdempotencyKeyFormat;
use Qiick\HttpBasics\Idempotency\IdempotencyKeyValidator;
use Qiick\HttpBasics\Idempotency\IdempotencyRecord;
use Qiick\HttpBasics\Locale\LocaleNegotiator;
use Qiick\HttpBasics\RequestId\RequestIdAcceptFrom;
use Qiick\HttpBasics\RequestId\RequestIdResolver;
use Qiick\HttpBasics\Security\CspMode;
use Qiick\HttpBasics\Security\SecurityHeadersPolicy;

/**
 * Sets an environment variable the way a `.env` line does, so a default that
 * re-reads the environment would see the same blank or `null` value.
 */
function setEnvironmentVariable(string $name, string $value): void
{
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

function clearEnvironmentVariable(string $name): void
{
    putenv($name);
    unset($_ENV[$name], $_SERVER[$name]);
}

dataset('security header keys', [
    'x_frame_options' => ['HTTP_BASICS_X_FRAME_OPTIONS', 'x_frame_options', 'xFrameOptions', 'DENY'],
    'referrer_policy' => ['HTTP_BASICS_REFERRER_POLICY', 'referrer_policy', 'referrerPolicy', 'strict-origin-when-cross-origin'],
    'permissions_policy' => ['HTTP_BASICS_PERMISSIONS_POLICY', 'permissions_policy', 'permissionsPolicy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()'],
]);

it('keeps the safe security header when its env var is blank or null', function (string $environmentVariable, string $configKey, string $property, string $safeDefault): void {
    foreach (['', 'null'] as $environmentValue) {
        setEnvironmentVariable($environmentVariable, $environmentValue);

        try {
            config(["http-basics.security.{$configKey}" => Env::get($environmentVariable, 'unused')]);
            app()->forgetInstance(SecurityHeadersPolicy::class);

            expect(app(SecurityHeadersPolicy::class)->{$property})->toBe($safeDefault);
        } finally {
            clearEnvironmentVariable($environmentVariable);
        }
    }
})->with('security header keys');

it('keeps the shipped table and fallback locale when their env vars are blank', function (): void {
    setEnvironmentVariable('HTTP_BASICS_IDEMPOTENCY_TABLE', '');
    setEnvironmentVariable('APP_FALLBACK_LOCALE', '');

    try {
        config([
            'http-basics.idempotency.store' => 'database',
            'http-basics.idempotency.database.table' => '',
            'http-basics.locale.fallback' => '',
        ]);

        expect(wiredProperty(app(IdempotencyStore::class), 'table'))->toBe('idempotency_records')
            ->and((new IdempotencyRecord)->getTable())->toBe('idempotency_records')
            ->and(wiredProperty(app(LocaleNegotiator::class), 'fallbackLocale'))->toBe('en');
    } finally {
        clearEnvironmentVariable('HTTP_BASICS_IDEMPOTENCY_TABLE');
        clearEnvironmentVariable('APP_FALLBACK_LOCALE');
    }
});

it('gives a security block published without its newer keys the shipped defaults', function (): void {
    config(['http-basics.security' => ['enabled' => true, 'csp' => ['policy' => "default-src 'self'"]]]);

    bootProvider();
    $policy = app(SecurityHeadersPolicy::class);

    expect($policy->cspMode)->toBe(CspMode::Enforce)
        ->and($policy->csp)->toBe("default-src 'self'")
        ->and($policy->xFrameOptions)->toBe('DENY')
        ->and($policy->referrerPolicy)->toBe('strict-origin-when-cross-origin')
        ->and($policy->hstsMaxAge)->toBe(0)
        ->and($policy->baselineOnMachineDoors)->toBeTrue();
});

it('gives the other blocks published without their newer keys the shipped defaults', function (): void {
    config([
        'http-basics.request_id' => ['hidden_context_keys' => []],
        'http-basics.errors' => [],
        'http-basics.idempotency' => ['ttl_seconds' => 600],
    ]);

    bootProvider();

    expect(wiredProperty(app(RequestIdResolver::class), 'acceptFrom'))->toBe(RequestIdAcceptFrom::TrustedProxy)
        ->and(app(ErrorRenderer::class))->toBeInstanceOf(FleetErrorRenderer::class)
        ->and(app(IdempotencyStore::class))->toBeInstanceOf(CacheIdempotencyStore::class)
        ->and(wiredProperty(app(IdempotencyKeyValidator::class), 'format'))->toBe(IdempotencyKeyFormat::Uuid)
        ->and(wiredProperty(app(HandleIdempotencyMiddleware::class), 'replayHeaderName'))->toBe('Idempotent-Replayed');
});

it('turns the replay header off only when it is set blank or null', function (mixed $configured): void {
    config(['http-basics.idempotency.replay_header' => $configured]);

    expect(wiredProperty(app(HandleIdempotencyMiddleware::class), 'replayHeaderName'))->toBeNull();
})->with([
    'blank' => [''],
    'null' => [null],
]);

it('refuses an invalid csp mode or request-id source at boot', function (string $key, mixed $configured, string $message): void {
    config([$key => $configured]);

    expect(fn () => bootProvider())->toThrow(InvalidArgumentException::class, $message);
})->with([
    'csp mode' => ['http-basics.security.csp.mode', 'strict', 'http-basics.security.csp.mode must be enforce, report-only, off, or both.'],
    'csp mode null' => ['http-basics.security.csp.mode', null, 'http-basics.security.csp.mode must be enforce, report-only, off, or both.'],
    'accept from' => ['http-basics.request_id.accept_from', 'everyone', 'http-basics.request_id.accept_from must be trusted_proxy, any, or never.'],
]);

it('refuses anonymous components that are not a list at boot', function (): void {
    config([
        'http-basics.idempotency.anonymous.enabled' => true,
        'http-basics.idempotency.anonymous.secret' => str_repeat('s', 32),
        'http-basics.idempotency.anonymous.components' => 'ip',
    ]);

    expect(fn () => bootProvider())->toThrow(
        InvalidArgumentException::class,
        'http-basics.idempotency.anonymous.components must contain at least one valid component.',
    );
});

it('reads an hsts max age only from an integer or a numeric string', function (): void {
    config(['http-basics.security.hsts.max_age' => 31536000.9]);

    expect(app(SecurityHeadersPolicy::class)->hstsMaxAge)->toBe(0);
});
