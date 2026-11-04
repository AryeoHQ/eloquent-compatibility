<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Support\Database\Eloquent\Compatibility\Discovery\ServedModels;
use Support\Database\Eloquent\Compatibility\Exceptions\UndiscoveredServedModel;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\TestCase;

#[CoversClass(Provider::class)]
class ProviderTest extends TestCase
{
    #[Test]
    public function it_boots_the_provider_and_registers_the_sql_command(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(Provider::class));
        $this->assertArrayHasKey('compatibility:sql', Artisan::all());
    }

    #[Test]
    public function it_caches_the_served_models_on_optimize_and_removes_the_cache_on_optimize_clear(): void
    {
        $bootstrap = sys_get_temp_dir().'/compatibility-'.bin2hex(random_bytes(8));
        (new Filesystem)->ensureDirectoryExists("{$bootstrap}/cache");
        $this->app->useBootstrapPath($bootstrap);
        $this->app->forgetInstance(ServedModels::class);

        try {
            Artisan::call('optimize', ['--except' => 'config,events,routes,views']);
            $optimized = is_file("{$bootstrap}/cache/compatibility.php");
            Artisan::call('optimize:clear', ['--except' => 'config,cache,compiled,events,routes,views']);
            $cleared = is_file("{$bootstrap}/cache/compatibility.php");
        } finally {
            (new Filesystem)->deleteDirectory($bootstrap);
        }

        $this->assertSame([true, false], [$optimized, $cleared]);
    }

    #[Test]
    #[TestWith(['about'])]
    #[TestWith(['--env', 'optimize', 'about'])]
    public function it_fails_the_boot_outside_local_and_testing_without_a_cache(string ...$command): void
    {
        $bootstrap = $this->temporaryBootstrap();

        try {
            $booted = $this->bootInChild($bootstrap, $command, '');
        } finally {
            (new Filesystem)->deleteDirectory($bootstrap);
        }

        $this->assertSame(UndiscoveredServedModel::class.': '.UndiscoveredServedModel::withoutCache("{$bootstrap}/cache/compatibility.php")->getMessage(), $booted);
    }

    #[Test]
    #[TestWith(['optimize'])]
    #[TestWith(['optimize:clear'])]
    #[TestWith(['compatibility:cache'])]
    #[TestWith(['compatibility:clear'])]
    #[TestWith(['package:discover'])]
    #[TestWith(['--no-ansi', 'compatibility:cache'])]
    #[TestWith(['--env=staging', '-n', 'optimize'])]
    #[TestWith(['--env', 'staging', 'optimize'])]
    public function it_boots_the_command_that_writes_clears_or_discovers_without_a_cache_even_when_a_provider_instantiates_a_served_model(string ...$command): void
    {
        $bootstrap = $this->temporaryBootstrap();

        try {
            $booted = $this->bootInChild($bootstrap, $command, ' $app->booting(fn () => new '.Listing::class.');');
            $cached = $booted === 'booted' ? require "{$bootstrap}/cache/compatibility.php" : null;
        } finally {
            (new Filesystem)->deleteDirectory($bootstrap);
        }

        $this->assertSame('booted', $booted);
        $this->assertIsArray($cached);
        $this->assertContains(Listing::class, $cached);
    }

    #[Test]
    public function it_connects_to_the_postgresql_major_version_postgres_version_names(): void
    {
        $connection = DB::connection();

        $this->assertSame('pgsql', $connection->getDriverName());
        $this->assertMatchesRegularExpression('/^\d+$/', (string) Env::get('POSTGRES_VERSION'));

        $selected = (int) Env::get('POSTGRES_VERSION');
        $server = intdiv((int) $connection->scalar('show server_version_num'), 10000);

        $this->assertSame($selected, $server);
    }

    private function temporaryBootstrap(): string
    {
        $bootstrap = sys_get_temp_dir().'/compatibility-'.bin2hex(random_bytes(8));
        (new Filesystem)->ensureDirectoryExists("{$bootstrap}/cache");

        return $bootstrap;
    }

    /**
     * @param  list<string>  $command
     */
    private function bootInChild(string $bootstrap, array $command, string $resolving): string
    {
        $child = 'require '.var_export(dirname(__DIR__, 5).'/vendor/autoload.php', true).';'
            .' Tests\\TestCase::optimizeClassmap();'
            .' $_SERVER[\'argv\'] = '.var_export(['artisan', ...$command], true).';'
            .' try {'
            .' Orchestra\\Testbench\\Foundation\\Application::create(null, function ($app): void { $app->useBootstrapPath('.var_export($bootstrap, true).');'.$resolving.' });'
            .' Illuminate\\Support\\Facades\\Artisan::call(\'compatibility:cache\');'
            .' echo \'booted\';'
            .' } catch (Throwable $exception) { echo $exception::class, \': \', $exception->getMessage(); }';
        $process = proc_open([PHP_BINARY, '-r', $child], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['APP_ENV' => 'staging'] + getenv());

        if ($process === false) {
            $this->fail('The child application did not start.');
        }

        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        proc_close($process);

        return trim($output);
    }
}
