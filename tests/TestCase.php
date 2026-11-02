<?php

declare(strict_types=1);

namespace Tests;

use Composer\Autoload\ClassLoader;
use FilesystemIterator;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Support\Database\Eloquent\Compatibility\Provider;

abstract class TestCase extends Testbench\TestCase
{
    use RefreshDatabase;

    protected $enablesPackageDiscoveries = true;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::optimizeClassmap();
    }

    public static function optimizeClassmap(): void
    {
        $classmap = [];

        foreach ([dirname(__DIR__).'/src' => '', __DIR__.'/Fixtures' => 'Tests\\Fixtures\\'] as $directory => $namespace) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof SplFileInfo && fnmatch('*.php', $file->getFilename()) && ! fnmatch('*Test.php', $file->getFilename()) && ! fnmatch('*TestCases.php', $file->getFilename())) {
                    $classmap[$namespace.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($directory) + 1))] = $file->getPathname();
                }
            }
        }

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $loader->addClassMap($classmap);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->beforeApplicationDestroyed(function (): void {
            foreach (DB::getConnections() as $connection) {
                $connection->disconnect();
            }
        });
    }

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
        $app['config']->set('cache.default', 'array');
    }
}
