<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the package in a Laravel app. Auto-discovered through
 * `extra.laravel.providers` in composer.json.
 *
 * Runtime middleware and stores are bound as feature PRs land. Products keep
 * their own error enums and Exception::render wiring (recipes only).
 */
final class HttpBasicsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/http-basics.php', 'http-basics');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/http-basics.php' => config_path('http-basics.php'),
            ], 'http-basics-config');
        }
    }
}
