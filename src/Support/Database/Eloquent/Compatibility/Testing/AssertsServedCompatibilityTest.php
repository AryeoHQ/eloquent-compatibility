<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Testing;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Exception as PHPUnitException;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Discovery\ServedModels;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\Discovery\InheritedServed;
use Tests\Fixtures\Support\Discovery\ResolvableServedModels;
use Tests\Fixtures\Support\Files\FloorPlan;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\PlaceAssets\AssetFile;
use Tests\Fixtures\Support\PlaceAssets\PlaceAsset;
use Tests\Fixtures\Support\PlaceAssets\PlaceAssetTables;
use Tests\TestCase;

#[CoversTrait(AssertsServedCompatibility::class)]
final class AssertsServedCompatibilityTest extends TestCase
{
    use AssertsServedCompatibility;

    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        ListingTables::create();
        ListingTables::seed();
        $this->app->instance(ServedModels::class, ResolvableServedModels::of($this->app->make(ServedModels::class)));
    }

    protected function tearDown(): void
    {
        ListingTables::drop();
        PlaceAssetTables::drop();
        DB::statement('drop table if exists place_overlaps');

        parent::tearDown();
    }

    #[Test]
    public function it_passes_when_the_installed_view_is_the_view_the_map_compiles_and_a_native_create_is_a_member(): void
    {
        $this->install();

        $this->assertServesCompatibility(
            model: Listing::class,
            nativeCreate: self::job(),
        );
        $this->assertSame(3, DB::table('jobs')->count());
    }

    #[Test]
    public function it_fails_and_names_the_update_when_the_installed_view_differs_from_the_view_the_map_compiles(): void
    {
        $this->install();
        $definition = rtrim(trim((string) DB::scalar("select pg_get_viewdef('listings'::regclass)")), ';');
        DB::unprepared("create or replace view listings as select * from ({$definition}) as edited");

        $this->assertStringStartsWith(
            'The installed view listings differs from the view the map of '.Listing::class.' compiles. Run Compatibility::update('.Listing::class.'::class) in a migration.',
            $this->failureOf(
                model: Listing::class,
                nativeCreate: self::job(),
            ),
        );
    }

    #[Test]
    public function it_installs_inside_its_own_transaction_before_install_and_leaves_the_legacy_table_in_place(): void
    {
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();

        $this->assertServesCompatibility(
            model: Listing::class,
            nativeCreate: self::job(),
        );
        $this->assertSame('r', DB::scalar("select relkind::text from pg_class where oid = 'listings'::regclass"));
    }

    #[Test]
    public function it_fails_before_install_when_the_map_does_not_compile(): void
    {
        PlaceAssetTables::create();

        $this->assertSame(
            'The map of '.FloorPlan::class." does not compile: Compatibility map 'floorplans' is declared wrongly: floorplans has no column place_asset_id, the successor key servedFrom() names; Compatibility::prepare() adds it before install().",
            $this->failureOf(
                model: FloorPlan::class,
                nativeCreate: self::floorPlan(),
            ),
        );
    }

    #[Test]
    public function it_passes_for_a_floor_plan_whose_native_root_carries_its_morph_alias(): void
    {
        $this->installFloorPlans(classified: true);

        $this->assertServesCompatibility(
            model: FloorPlan::class,
            nativeCreate: self::floorPlan(),
        );
    }

    #[Test]
    public function it_fails_when_the_initializer_leaves_the_discriminator_of_a_backfilled_root_outside_when(): void
    {
        $this->installFloorPlans(classified: false);

        $this->assertStringStartsWith('compatibility:check finds a membership drift in floorplans.', $this->failureOf(
            model: FloorPlan::class,
            nativeCreate: self::floorPlan(),
        ));
    }

    #[Test]
    public function it_fails_when_a_native_create_leaves_the_discriminator_outside_when(): void
    {
        $this->installFloorPlans(classified: true);

        $this->assertStringStartsWith(
            'nativeCreate creates a PlaceAsset outside the when() of FloorPlan, so a native create inserts no floorplans row.',
            $this->failureOf(
                model: FloorPlan::class,
                nativeCreate: fn () => PlaceAsset::query()->create(['asset_type' => 'place_asset_file', 'asset_id' => AssetFile::query()->create(['filename' => 'pine-plan.pdf'])->getKey()]),
            ),
        );
    }

    #[Test]
    public function it_fails_when_two_maps_when_match_one_native_root(): void
    {
        $this->installFloorPlans(classified: true);
        Schema::create(
            table: 'place_overlaps',
            callback: function (Blueprint $table): void {
                $table->increments('id');
                $table->uuid('place_asset_id')->nullable();
            },
        );
        $overlap = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'place_overlaps';

            public static function compatibility(Compatibility $overlap): Compatibility
            {
                return $overlap->servedFrom(root: PlaceAsset::class, key: 'place_asset_id', successor: fn (Successor $asset) => $asset
                    ->when(fn (Builder $assets) => $assets->whereIn(
                        column: 'legacy_type',
                        values: ['floorplan', 'place_overlap'],
                    )));
            }
        };
        $cache = sys_get_temp_dir().'/compatibility-overlap-'.getmypid().'.php';
        file_put_contents(
            filename: $cache,
            data: '<?php return '.var_export(
                value: [FloorPlan::class, $overlap::class],
                return: true,
            ).';',
        );
        $this->app->instance(ServedModels::class, new ServedModels(
            cache: $cache,
            scans: false,
            withoutCache: false,
            files: new Filesystem,
        ));

        try {
            $failure = $this->failureOf(
                model: FloorPlan::class,
                nativeCreate: self::floorPlan(),
            );
        } finally {
            unlink($cache);
        }

        $this->assertStringStartsWith('nativeCreate creates a PlaceAsset that the when() of FloorPlan and of '.class_basename($overlap).' both match, so one root would gain a legacy row in each.', $failure);
    }

    #[Test]
    public function it_fails_and_names_a_sibling_map_that_does_not_compile(): void
    {
        $this->install();
        $this->app->forgetInstance(ServedModels::class);

        $this->assertStringStartsWith(
            'The map of '.InheritedServed::class.', which compatibility:check reads beside '.Listing::class." to prove that no shared successor serves two legacy records, does not compile: Compatibility map 'inherited_serveds' is declared wrongly: it says no servedFrom(), so it names no successor.",
            $this->failureOf(
                model: Listing::class,
                nativeCreate: self::job(),
            ),
        );
    }

    #[Test]
    public function it_fails_when_discovery_does_not_find_the_model(): void
    {
        $model = (new class extends Model implements Compatible
        {
            use ServesCompatibility;

            public static function compatibility(Compatibility $compatibility): Compatibility
            {
                return $compatibility;
            }
        })::class;

        $this->assertStringStartsWith(
            "Discovery does not find {$model}, so no successor event reaches it. Keep the class in the composer classmap, and run php artisan optimize after adding it.",
            $this->failureOf(
                model: $model,
                nativeCreate: self::job(),
            ),
        );
    }

    /**
     * @param  class-string<Model&Compatible>  $model
     * @param  Closure(): mixed  $nativeCreate
     */
    private function failureOf(string $model, Closure $nativeCreate): string
    {
        try {
            $this->assertServesCompatibility(
                model: $model,
                nativeCreate: $nativeCreate,
            );
        } catch (PHPUnitException $exception) {
            return $exception->getMessage();
        }

        $this->fail("assertServesCompatibility({$model}) passed.");
    }

    private function install(): void
    {
        Compatibility::prepare(Listing::class);
        ListingTables::backfill();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
    }

    private function installFloorPlans(bool $classified): void
    {
        PlaceAssetTables::create();
        PlaceAssetTables::seed();
        Compatibility::prepare(FloorPlan::class);
        $this->artisan(
            command: 'compatibility:backfill',
            parameters: ['model' => FloorPlan::class],
        )->assertSuccessful();

        if ($classified) {
            PlaceAssetTables::classify(FloorPlan::class);
        }

        Compatibility::install(
            model: FloorPlan::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
    }

    /**
     * @return Closure(): Job
     */
    private static function job(): Closure
    {
        return fn () => Job::query()->create(['name' => 'Birch Lodge', 'company_id' => ListingTables::COMPANY]);
    }

    /**
     * @return Closure(): PlaceAsset
     */
    private static function floorPlan(): Closure
    {
        return fn () => PlaceAsset::query()->create(['legacy_type' => 'floorplan', 'asset_type' => 'place_asset_file', 'asset_id' => AssetFile::query()->create(['filename' => 'pine-plan.pdf'])->getKey()]);
    }
}
