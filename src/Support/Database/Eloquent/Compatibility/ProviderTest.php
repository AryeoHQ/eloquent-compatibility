<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
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
    public function it_connects_to_the_postgresql_major_version_postgres_version_names(): void
    {
        $connection = DB::connection();

        $this->assertSame('pgsql', $connection->getDriverName());
        $this->assertMatchesRegularExpression('/^\d+$/', (string) Env::get('POSTGRES_VERSION'));

        $selected = (int) Env::get('POSTGRES_VERSION');
        $server = intdiv((int) $connection->scalar('show server_version_num'), 10000);

        $this->assertSame($selected, $server);
    }
}
