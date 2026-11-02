<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Discovery;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Exceptions\UndiscoveredServedModel;
use Tests\Fixtures\Support\Files\FloorPlan;
use Tests\Fixtures\Support\Files\InteractiveContent;
use Tests\Fixtures\Support\Files\LegacyFile;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Files\Video;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\TestCase;

#[CoversClass(ServedModels::class)]
final class ServedModelsTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    private string $bootstrap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrap = sys_get_temp_dir().'/compatibility-'.bin2hex(random_bytes(8));
        (new Filesystem)->ensureDirectoryExists("{$this->bootstrap}/cache");
        $this->app->useBootstrapPath($this->bootstrap);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->bootstrap);

        parent::tearDown();
    }

    #[Test]
    public function it_finds_every_classmap_model_that_serves_compatibility_and_nothing_else(): void
    {
        $this->assertSame(
            [FloorPlan::class, InteractiveContent::class, LegacyFile::class, Photo::class, Video::class, Listing::class],
            $this->app->make(ServedModels::class)->all(),
        );
    }

    #[Test]
    public function it_discovers_the_served_models_without_opening_a_database_connection(): void
    {
        foreach (array_keys(DB::getConnections()) as $connection) {
            DB::purge($connection);
        }

        $this->assertTrue($this->app->make(ServedModels::class)->contains(Listing::class));
        $this->assertSame([], DB::getConnections());
    }

    #[Test]
    public function it_scans_on_every_boot_in_testing_and_ignores_a_cache(): void
    {
        file_put_contents("{$this->bootstrap}/cache/compatibility.php", '<?php return [];');

        $this->assertTrue($this->app->make(ServedModels::class)->contains(Listing::class));
    }

    #[Test]
    public function it_refuses_to_list_the_served_models_outside_local_and_testing_without_a_cache(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectExceptionObject(UndiscoveredServedModel::withoutCache("{$this->bootstrap}/cache/compatibility.php"));

        $this->app->make(ServedModels::class)->all();
    }

    #[Test]
    public function it_reads_the_served_models_from_the_cache_outside_local_and_testing(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        Artisan::call('optimize', ['--except' => 'config,events,routes,views']);
        $cached = $this->app->make(ServedModels::class)->all();
        file_put_contents("{$this->bootstrap}/cache/compatibility.php", '<?php return ['.var_export(Photo::class, true).'];');
        $this->app->forgetInstance(ServedModels::class);
        $stale = $this->app->make(ServedModels::class);

        $this->assertSame([FloorPlan::class, InteractiveContent::class, LegacyFile::class, Photo::class, Video::class, Listing::class], $cached);
        $this->assertSame([Photo::class], $stale->all());
        $this->assertFalse($stale->contains(Listing::class));
    }
}
