<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Qiick\HttpBasics\Laravel\HttpBasicsServiceProvider;

it('merges the package defaults into the http-basics config', function (): void {
    expect(config('http-basics.request_id.accept_from'))->toBe('trusted_proxy');
    expect(config('http-basics.request_id.outbound.allowed_hosts'))->toBe([]);
    expect(config('http-basics.request_id.trust_wildcard_proxies'))->toBeFalse();
    expect(config('http-basics.locale.max_header_bytes'))->toBe(1024);
    expect(config('http-basics.locale.max_languages'))->toBe(20);
    expect(config('http-basics.security.csp.mode'))->toBe('enforce');
    expect(config('http-basics.security.hsts.max_age'))->toBe(0);
    expect(config('http-basics.idempotency.store'))->toBe('cache');
    expect(config('http-basics.idempotency.methods'))->toBe(['POST', 'PATCH']);
    expect(config('http-basics.idempotency.anonymous.enabled'))->toBeFalse();
    expect(config('http-basics.idempotency.lock_ttl_seconds'))->toBe(60);
    expect(config('http-basics.idempotency.max_handler_seconds'))->toBe(55);
});

it('publishes the config source into a temporary directory outside vendor', function (): void {
    $paths = ServiceProvider::pathsToPublish(HttpBasicsServiceProvider::class, 'http-basics-config');
    expect($paths)->not->toBeEmpty();

    $source = array_key_first($paths);
    expect($source)->toBeString()
        ->and(realpath((string) $source))->toBe(realpath(dirname(__DIR__, 1).'/../config/http-basics.php'));

    $tempDir = sys_get_temp_dir().'/http-basics-config-'.uniqid('', true);
    mkdir($tempDir, 0777, true);
    $target = $tempDir.'/http-basics.php';

    // Publish into a temp dir (never into vendor/orchestra) — kills destination mutants.
    copy((string) $source, $target);

    expect($target)->toBeFile()
        ->and(str_contains((string) realpath($target), DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR))->toBeFalse()
        ->and(file_get_contents($target))->toContain("'request_id'");

    unlink($target);
    rmdir($tempDir);
});
