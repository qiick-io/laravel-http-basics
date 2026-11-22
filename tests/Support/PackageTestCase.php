<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Tests\Support;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;
use Qiick\HttpBasics\Laravel\HttpBasicsServiceProvider;

/**
 * Boots a Testbench Laravel app with the package provider. CI runs the suite
 * on Laravel 12 and 13.
 */
abstract class PackageTestCase extends TestCase
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [HttpBasicsServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $connection = getenv('DB_CONNECTION');

        if (! is_string($connection) || $connection === '' || $connection === 'sqlite') {
            $app['config']->set('database.default', 'sqlite');
            $app['config']->set('database.connections.sqlite', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);

            return;
        }

        $app['config']->set('database.default', $connection);
        $app['config']->set('database.connections.'.$connection, [
            'driver' => $connection === 'pgsql' ? 'pgsql' : ($connection === 'mariadb' ? 'mariadb' : 'mysql'),
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_PORT') ?: ($connection === 'pgsql' ? '5432' : '3306'),
            'database' => getenv('DB_DATABASE') ?: 'httpbasics',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : '',
            'charset' => $connection === 'pgsql' ? 'utf8' : 'utf8mb4',
            'collation' => $connection === 'pgsql' ? null : 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ]);
    }
}
