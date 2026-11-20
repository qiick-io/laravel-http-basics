<?php

declare(strict_types=1);

it('merges the package defaults into the http-basics config', function (): void {
    expect(config('http-basics.request_id.accept_from'))->toBe('trusted_proxy');
    expect(config('http-basics.request_id.outbound.allowed_hosts'))->toBe([]);
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

it('lets an app publish the config file', function (): void {
    $this->artisan('vendor:publish', ['--tag' => 'http-basics-config', '--force' => true])
        ->assertSuccessful();

    expect(config_path('http-basics.php'))->toBeFile();

    unlink(config_path('http-basics.php'));
});
