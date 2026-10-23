<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench;
use RuntimeException;
use Support\Database\Eloquent\Compatibility\Provider;

abstract class TestCase extends Testbench\TestCase
{
    use RefreshDatabase;

    protected $enablesPackageDiscoveries = true;

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            Provider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $connection = env('DB_CONNECTION', 'pgsql');
        $driver = $app['config']->get("database.connections.{$connection}.driver");

        if ($driver !== 'pgsql') {
            throw new RuntimeException("The package supports PostgreSQL only, and DB_CONNECTION [{$connection}] selects the [{$driver}] driver.");
        }

        $app['config']->set('database.default', $connection);
    }
}
