<?php

declare(strict_types=1);

use Qiick\HttpBasics\Laravel\HttpBasicsServiceProvider;

it('fails boot when anonymous idempotency is enabled without a long enough secret', function (): void {
    config()->set('http-basics.idempotency.anonymous.enabled', true);
    config()->set('http-basics.idempotency.anonymous.secret', 'short');

    /** @var HttpBasicsServiceProvider $provider */
    $provider = $this->app->getProvider(HttpBasicsServiceProvider::class);

    expect(fn () => $provider->boot())->toThrow(InvalidArgumentException::class);
});

it('fails boot when lock ttl is shorter than max handler seconds', function (): void {
    config()->set('http-basics.idempotency.lock_ttl_seconds', 10);
    config()->set('http-basics.idempotency.max_handler_seconds', 20);

    /** @var HttpBasicsServiceProvider $provider */
    $provider = $this->app->getProvider(HttpBasicsServiceProvider::class);

    expect(fn () => $provider->boot())->toThrow(InvalidArgumentException::class);
});

it('fails boot when lock ttl equals max handler seconds', function (): void {
    config()->set('http-basics.idempotency.lock_ttl_seconds', 30);
    config()->set('http-basics.idempotency.max_handler_seconds', 30);

    /** @var HttpBasicsServiceProvider $provider */
    $provider = $this->app->getProvider(HttpBasicsServiceProvider::class);

    expect(fn () => $provider->boot())->toThrow(InvalidArgumentException::class);
});

it('rejects retention beyond the erasure TTL limit', function (string $setting): void {
    config()->set('http-basics.idempotency.'.$setting, 86401);

    /** @var HttpBasicsServiceProvider $provider */
    $provider = $this->app->getProvider(HttpBasicsServiceProvider::class);

    expect(fn () => $provider->boot())->toThrow(InvalidArgumentException::class, 'must expire within 24 hours');
})->with(['ttl_seconds', 'lock_ttl_seconds']);
