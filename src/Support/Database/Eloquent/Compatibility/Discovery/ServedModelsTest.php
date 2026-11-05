<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Discovery;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Exceptions\UndiscoveredServedModel;
use Tests\Fixtures\Support\Discovery\InheritedServed;
use Tests\Fixtures\Support\Discovery\TraitServed;
use Tests\Fixtures\Support\Files\FloorPlan;
use Tests\Fixtures\Support\Files\InteractiveContent;
use Tests\Fixtures\Support\Files\LegacyFile;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Files\Video;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\PlaceAssets\Brochure;
use Tests\Fixtures\Support\PlaceAssets\Image;
use Tests\Fixtures\Support\PlaceAssets\InteractiveContent as PlaceInteractiveContent;
use Tests\Fixtures\Support\PlaceAssets\Video as PlaceVideo;
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

        $this->bootstrap = realpath(sys_get_temp_dir()).'/compatibility-'.bin2hex(random_bytes(8));
        (new Filesystem)->ensureDirectoryExists("{$this->bootstrap}/cache");
        $this->app->useBootstrapPath($this->bootstrap);
        $this->app->forgetInstance(ServedModels::class);
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
            [InheritedServed::class, TraitServed::class, FloorPlan::class, InteractiveContent::class, LegacyFile::class, Photo::class, Video::class, Listing::class, Brochure::class, Image::class, PlaceInteractiveContent::class, PlaceVideo::class],
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
    public function it_discovers_the_served_models_of_a_path_repository_package_whether_composer_symlinks_or_mirrors_it_and_no_other_vendor_package(): void
    {
        $vendor = "{$this->bootstrap}/vendor";
        $suffix = bin2hex(random_bytes(4));
        $classmap = [
            "Acme\\Symlinked\\Served{$suffix}" => $this->package("{$this->bootstrap}/packages/symlinked", 'Acme\\Symlinked', "Served{$suffix}"),
            "Acme\\Mirrored\\Served{$suffix}" => $this->package("{$vendor}/acme/mirrored", 'Acme\\Mirrored', "Served{$suffix}"),
            "Acme\\Registry\\Served{$suffix}" => $this->package("{$vendor}/acme/registry", 'Acme\\Registry', "Served{$suffix}"),
        ];
        symlink("{$this->bootstrap}/packages/symlinked", "{$vendor}/acme/symlinked");
        $classmap["Acme\\Symlinked\\Served{$suffix}"] = "{$vendor}/acme/symlinked/src/Served{$suffix}.php";
        (new Filesystem)->ensureDirectoryExists("{$vendor}/composer");
        file_put_contents("{$vendor}/composer/installed.json", json_encode(['packages' => [
            ['name' => 'acme/mirrored', 'dist' => ['type' => 'path', 'url' => '../packages/mirrored'], 'install-path' => '../acme/mirrored'],
            ['name' => 'acme/registry', 'dist' => ['type' => 'zip', 'url' => 'https://repo.example/acme/registry.zip'], 'install-path' => '../acme/registry'],
            ['name' => 'acme/symlinked', 'dist' => ['type' => 'path', 'url' => '../packages/symlinked'], 'install-path' => '../acme/symlinked'],
        ]], JSON_THROW_ON_ERROR));
        $served = $this->served(vendor: $vendor, classmap: $classmap);

        $this->assertSame(
            [true, true, false],
            [in_array("Acme\\Symlinked\\Served{$suffix}", $served, true), in_array("Acme\\Mirrored\\Served{$suffix}", $served, true), in_array("Acme\\Registry\\Served{$suffix}", $served, true)],
        );
    }

    #[Test]
    public function it_lists_no_model_of_a_registry_package_that_composer_installers_places_outside_the_vendor_directory(): void
    {
        $vendor = "{$this->bootstrap}/vendor";
        $suffix = bin2hex(random_bytes(4));
        $classmap = [
            "Acme\\Plugin\\Served{$suffix}" => $this->package("{$this->bootstrap}/plugins/acme-plugin", 'Acme\\Plugin', "Served{$suffix}"),
            "Acme\\Dependent\\Served{$suffix}" => $this->package("{$this->bootstrap}/plugins/acme-dependent", 'Acme\\Dependent', "Served{$suffix}"),
        ];
        (new Filesystem)->ensureDirectoryExists("{$vendor}/composer");
        file_put_contents("{$vendor}/composer/installed.json", json_encode(['packages' => [
            ['name' => 'acme/plugin', 'dist' => ['type' => 'zip', 'url' => 'https://repo.example/acme/plugin.zip'], 'install-path' => '../../plugins/acme-plugin'],
            ['name' => 'acme/dependent', 'dist' => ['type' => 'zip', 'url' => 'https://repo.example/acme/dependent.zip'], 'install-path' => '../../plugins/acme-dependent', 'require' => ['aryeo/eloquent-compatibility' => '^1.0']],
        ]], JSON_THROW_ON_ERROR));
        $served = $this->served(vendor: $vendor, classmap: $classmap);

        $this->assertSame(
            [false, false],
            [in_array("Acme\\Plugin\\Served{$suffix}", $served, true), in_array("Acme\\Dependent\\Served{$suffix}", $served, true)],
        );
    }

    #[Test]
    public function it_discovers_an_application_model_that_inherits_serving_from_a_registry_package_and_no_model_of_that_package(): void
    {
        $vendor = "{$this->bootstrap}/vendor";
        $suffix = bin2hex(random_bytes(4));
        $serves = '\\Support\\Database\\Eloquent\\Compatibility\\Provides\\ServesCompatibility';
        $compatible = 'implements \\Support\\Database\\Eloquent\\Compatibility\\Contracts\\Compatible';
        $map = 'public static function compatibility(\\Support\\Database\\Eloquent\\Compatibility\\Compatibility $compatibility): \\Support\\Database\\Eloquent\\Compatibility\\Compatibility { return $compatibility; }';
        $classmap = [
            "Acme\\Base\\Record{$suffix}" => $this->source("{$vendor}/acme/base/src/Record{$suffix}.php", "namespace Acme\\Base; abstract class Record{$suffix} extends \\Illuminate\\Database\\Eloquent\\Model {$compatible} { use {$serves}; {$map} }"),
            "Acme\\Base\\Serves{$suffix}" => $this->source("{$vendor}/acme/base/src/Serves{$suffix}.php", "namespace Acme\\Base; trait Serves{$suffix} { use {$serves}; }"),
            "Acme\\Base\\Shipped{$suffix}" => $this->source("{$vendor}/acme/base/src/Shipped{$suffix}.php", "namespace Acme\\Base; final class Shipped{$suffix} extends Record{$suffix} {}"),
            "App\\Models\\Inherited{$suffix}" => $this->source("{$this->bootstrap}/app/Models/Inherited{$suffix}.php", "namespace App\\Models; use Acme\\Base\\Record{$suffix}; final class Inherited{$suffix} extends Record{$suffix} {}"),
            "App\\Models\\Traited{$suffix}" => $this->source("{$this->bootstrap}/app/Models/Traited{$suffix}.php", "namespace App\\Models; use Acme\\Base\\Serves{$suffix}; final class Traited{$suffix} extends \\Illuminate\\Database\\Eloquent\\Model {$compatible} { use Serves{$suffix}; {$map} }"),
        ];
        (new Filesystem)->ensureDirectoryExists("{$vendor}/composer");
        file_put_contents("{$vendor}/composer/installed.json", json_encode(['packages' => [
            ['name' => 'acme/base', 'dist' => ['type' => 'zip', 'url' => 'https://repo.example/acme/base.zip'], 'install-path' => '../acme/base', 'require' => ['aryeo/eloquent-compatibility' => '^1.0']],
        ]], JSON_THROW_ON_ERROR));
        $served = $this->served(vendor: $vendor, classmap: $classmap);

        $this->assertSame(
            [true, true, false],
            [in_array("App\\Models\\Inherited{$suffix}", $served, true), in_array("App\\Models\\Traited{$suffix}", $served, true), in_array("Acme\\Base\\Shipped{$suffix}", $served, true)],
        );
    }

    #[Test]
    public function it_discovers_an_application_model_that_an_optimized_classmap_lists_through_the_vendor_directory(): void
    {
        $vendor = "{$this->bootstrap}/vendor";
        $suffix = bin2hex(random_bytes(4));
        $this->package("{$this->bootstrap}/app/Models", 'App\\Models', "Optimized{$suffix}");
        (new Filesystem)->ensureDirectoryExists("{$vendor}/composer");

        $this->assertContains("App\\Models\\Optimized{$suffix}", $this->served(vendor: $vendor, classmap: ["App\\Models\\Optimized{$suffix}" => "{$vendor}/composer/../../app/Models/src/Optimized{$suffix}.php"]));
    }

    #[Test]
    public function it_reads_the_application_files_once_per_process_while_the_registered_classmaps_keep_their_size(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $file = $this->package("{$this->bootstrap}/app/Models", 'App\\Models', "Scanned{$suffix}");
        $loader = new ClassLoader("{$this->bootstrap}/vendor");
        $loader->addClassMap(["App\\Models\\Scanned{$suffix}" => $file]);
        $loader->register();

        try {
            $first = $this->app->make(ServedModels::class)->all();
            unlink($file);
            $this->app->forgetInstance(ServedModels::class);
            $second = $this->app->make(ServedModels::class)->all();
        } finally {
            $loader->unregister();
        }

        $this->assertContains("App\\Models\\Scanned{$suffix}", $first);
        $this->assertSame($first, $second);
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
        $this->app->forgetInstance(ServedModels::class);
        $cached = $this->app->make(ServedModels::class)->all();
        file_put_contents("{$this->bootstrap}/cache/compatibility.php", '<?php return ['.var_export(Photo::class, true).'];');
        $this->app->forgetInstance(ServedModels::class);
        $stale = $this->app->make(ServedModels::class);

        $this->assertSame([InheritedServed::class, TraitServed::class, FloorPlan::class, InteractiveContent::class, LegacyFile::class, Photo::class, Video::class, Listing::class, Brochure::class, Image::class, PlaceInteractiveContent::class, PlaceVideo::class], $cached);
        $this->assertSame([Photo::class], $stale->all());
        $this->assertFalse($stale->contains(Listing::class));
    }

    #[Test]
    public function it_skips_a_cached_class_that_no_longer_exists(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        file_put_contents("{$this->bootstrap}/cache/compatibility.php", '<?php return '.var_export([Photo::class, 'Tests\Fixtures\Support\Files\RemovedPhoto'], true).';');

        $this->assertSame([Photo::class], $this->app->make(ServedModels::class)->all());
    }

    /**
     * @param  array<string, string>  $classmap
     * @return list<string>
     */
    private function served(string $vendor, array $classmap): array
    {
        $loader = new ClassLoader($vendor);
        $loader->addClassMap($classmap);
        $loader->register();

        try {
            return $this->app->make(ServedModels::class)->all();
        } finally {
            $loader->unregister();
        }
    }

    private function source(string $file, string $code): string
    {
        (new Filesystem)->ensureDirectoryExists(dirname($file));
        file_put_contents($file, "<?php\n\n{$code}\n");

        return $file;
    }

    private function package(string $directory, string $namespace, string $class): string
    {
        (new Filesystem)->ensureDirectoryExists("{$directory}/src");
        file_put_contents("{$directory}/src/{$class}.php", <<<PHP
            <?php

            namespace {$namespace};

            final class {$class} extends \Illuminate\Database\Eloquent\Model implements \Support\Database\Eloquent\Compatibility\Contracts\Compatible
            {
                use \Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;

                public static function compatibility(\Support\Database\Eloquent\Compatibility\Compatibility \$compatibility): \Support\Database\Eloquent\Compatibility\Compatibility
                {
                    return \$compatibility;
                }
            }
            PHP);

        return "{$directory}/src/{$class}.php";
    }
}
