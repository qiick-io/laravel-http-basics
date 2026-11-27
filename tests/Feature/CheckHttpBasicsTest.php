<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepositoryContract;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Qiick\HttpBasics\Tests\Support\FaultyCacheRepository;

it('reports a healthy cache-backed configuration', function (): void {
    config(['cache.default' => 'array']);

    $this->artisan('http-basics:check')
        ->expectsOutput('HTTP Basics configuration and stores are ready.')
        ->assertSuccessful();
});

it('reports each invalid key even when Artisan boots with invalid config', function (): void {
    config([
        'cache.default' => 'array',
        'http-basics.idempotency.ttl_seconds' => 0,
        'http-basics.errors.format' => 'unknown',
    ]);

    $originalArguments = $_SERVER['argv'];
    $_SERVER['argv'] = ['artisan', '--no-ansi', '--env', 'testing', 'http-basics:check'];

    try {
        bootProvider();
    } finally {
        $_SERVER['argv'] = $originalArguments;
    }

    $this->artisan('http-basics:check')
        ->expectsOutput('http-basics.idempotency.ttl_seconds must be greater than 0.')
        ->expectsOutput('http-basics.errors.format must be fleet or problem_details.')
        ->assertExitCode(1);
});

it('still fails boot when the CLI arguments do not select a valid check invocation', function (array $commandArguments): void {
    config(['http-basics.errors.format' => 'unknown']);

    $originalArguments = $_SERVER['argv'];
    $_SERVER['argv'] = ['artisan', ...$commandArguments];

    try {
        expect(bootProvider(...))->toThrow(InvalidArgumentException::class, 'http-basics.errors.format must be fleet or problem_details.');
    } finally {
        $_SERVER['argv'] = $originalArguments;
    }
})->with([
    'check name is an option value' => [['--env', 'http-basics:check', 'list']],
    'unknown option prevents command selection' => [['--unknown', 'http-basics:check']],
]);

it('identifies a configured cache store that resolves but cannot be read', function (): void {
    $cache = new FaultyCacheRepository(new ArrayStore);
    $cache->isGetThrowing = true;
    Cache::extend('check-offline', fn (): FaultyCacheRepository => $cache);
    config([
        'cache.stores.check-offline' => ['driver' => 'check-offline'],
        'http-basics.idempotency.cache_store' => 'check-offline',
    ]);

    $this->artisan('http-basics:check')
        ->expectsOutput('http-basics.idempotency.cache_store (check-offline) is unreachable.')
        ->assertExitCode(1);
});

it('requires an Illuminate cache repository with a lock-capable store', function (bool $isIlluminateRepository): void {
    $factory = Mockery::mock(CacheFactory::class);
    $repository = $isIlluminateRepository
        ? new Repository(Mockery::mock(Store::class))
        : Mockery::mock(CacheRepositoryContract::class);
    $factory->expects('store')->with(null)->andReturn($repository);
    $this->app->instance(CacheFactory::class, $factory);

    $this->artisan('http-basics:check')
        ->expectsOutput('http-basics.idempotency.cache_store (default) must be an Illuminate cache repository with atomic locks.')
        ->assertExitCode(1);
})->with([true, false]);

it('uses a unique namespaced key for the read-only cache probe', function (): void {
    $cache = Mockery::mock(ArrayStore::class)->makePartial();
    $cache->expects('get')->with(Mockery::on(static fn (string $key): bool => Str::startsWith($key, 'http-basics:check:')
        && Str::isUuid(Str::after($key, 'http-basics:check:'))))->andReturnNull();
    Cache::extend('probe-store', fn (): Repository => new Repository($cache));
    config([
        'cache.stores.probe-store' => ['driver' => 'probe-store'],
        'http-basics.idempotency.cache_store' => 'probe-store',
    ]);

    $this->artisan('http-basics:check')->assertSuccessful();
});

it('keeps cache diagnostics on one line', function (): void {
    config(['http-basics.idempotency.cache_store' => "missing\r\nstore"]);

    $this->artisan('http-basics:check')
        ->expectsOutput('http-basics.idempotency.cache_store (missing  store) is unreachable.')
        ->assertExitCode(1);
});

it('checks the configured database connection and table', function (): void {
    config([
        'cache.default' => 'array',
        'database.connections.check-database' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'http-basics.idempotency.store' => 'database',
        'http-basics.idempotency.database.connection' => 'check-database',
        'http-basics.idempotency.database.table' => 'custom_idempotency_records',
    ]);

    $this->artisan('http-basics:check')
        ->expectsOutput('http-basics.idempotency.database.table (check-database.custom_idempotency_records) is unreachable.')
        ->assertExitCode(1);

    Schema::connection('check-database')->create('custom_idempotency_records', static function (Blueprint $table): void {
        $table->id();
    });

    $this->artisan('http-basics:check')->assertSuccessful();
});
