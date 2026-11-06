<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Definition\Targets;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\FloorPlan;
use Tests\Fixtures\Support\Files\LegacyFile;
use Tests\Fixtures\Support\Files\Link;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\JobSite;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\Listings\Place;
use Tests\Fixtures\Support\Listings\SyncListingToMls;
use Tests\Fixtures\Support\PlaceAssets\AssetFile;
use Tests\Fixtures\Support\PlaceAssets\PlaceAsset;
use Tests\Fixtures\Support\PlaceAssets\PlaceAssetTables;
use Tests\TestCase;

#[CoversClass(Compatibility::class)]
final class CompatibilityTest extends TestCase
{
    /** @var class-string<Model> */
    public static string $reporting = Model::class;

    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        ListingTables::drop();
        PlaceAssetTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_resolves_the_listing_map_without_a_database_connection(): void
    {
        foreach (array_keys(DB::getConnections()) as $connection) {
            DB::purge($connection);
        }

        $map = Compatibility::of(Listing::class)->resolve();

        $this->assertSame([], DB::getConnections());
        $this->assertSame([Job::class, 'job_id', ['created_at', 'updated_at', 'deleted_at', 'title', 'company_id', 'price', 'status', 'street', 'city', 'latitude']], [$map->primary->model, $map->successorKey, array_keys($map->attributes)]);
    }

    #[Test]
    public function it_resolves_each_map_over_a_shared_successor_by_its_own_successor_key_without_a_database_connection(): void
    {
        foreach (array_keys(DB::getConnections()) as $connection) {
            DB::purge($connection);
        }

        $roots = array_map(
            callback: fn (string $model) => [Compatibility::of($model)->resolve()->primary->model, Compatibility::of($model)->resolve()->successorKey],
            array: [Photo::class, LegacyFile::class, FloorPlan::class],
        );

        $this->assertSame([], DB::getConnections());
        $this->assertSame([[File::class, 'file_id'], [File::class, 'file_id'], [PlaceAsset::class, 'place_asset_id']], $roots);
    }

    #[Test]
    public function it_names_a_missing_legacy_table_or_a_table_that_is_no_view_before_any_operation_writes(): void
    {
        $refusals = [];

        foreach (['install' => fn () => Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(5),
        ), 'update' => fn () => Compatibility::update(Listing::class), 'rollback' => fn () => Compatibility::rollback(Listing::class)] as $operation) {
            try {
                $operation();
            } catch (InvalidCompatibilityMap $refusal) {
                $refusals[] = $refusal->getMessage();
            }
        }

        $this->assertSame([
            "Compatibility map 'listings' is declared wrongly: the legacy table listings does not exist.",
            "Compatibility map 'listings' is declared wrongly: listings is not a view, so update() has nothing to replace; install() creates it.",
            "Compatibility map 'listings' is declared wrongly: listings is not a view, so rollback() has nothing to reverse.",
        ], $refusals);
    }

    #[Test]
    public function it_prepares_installs_rolls_back_and_retires_a_listing_with_its_identity_on_the_retained_table(): void
    {
        ListingTables::create();
        ListingTables::seed();
        $identity = $this->identities('listings');

        Compatibility::prepare(Listing::class);
        Compatibility::prepare(Listing::class);
        $prepared = [$this->constraints('listings'), DB::select("select legacy_type, pass from compatibility_backfills where legacy_type = 'listings'"), DB::scalar("select to_regclass('listings_backfill') is not null")];
        $this->artisan(
            command: 'compatibility:backfill',
            parameters: ['model' => Listing::class],
        )->assertSuccessful();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $installed = [$this->relkind('listings'), $this->relkind('listings_retained'), $this->identities('listings'), $this->constraints('listings_retained'), DB::scalar("select count(*) from compatibility_backfills where legacy_type = 'listings'"), DB::scalar("select to_regclass('listings_backfill') is not null"), $this->referencedBy('listing_photos')];
        $titles = Listing::withTrashed()->orderBy('id')->pluck('title')->all();
        Compatibility::rollback(Listing::class);
        Compatibility::rollback(Listing::class);
        $rolledBack = [$this->relkind('listings'), $this->relkind('listings_retained'), $this->identities('listings'), $this->constraints('listings'), DB::table('listings')->orderBy('id')->pluck('title')->all(), $this->referencedBy('listing_photos')];
        Compatibility::prepare(Listing::class);
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        DB::statement('drop table listing_photos, listing_shares');
        Compatibility::retire(Listing::class);
        Compatibility::retire(Listing::class);

        $this->assertSame([['listings_job_id_unique u', 'listings_pkey p', 'listings_uuid_unique u'], [['legacy_type' => 'listings', 'pass' => 0]], true], [$prepared[0], array_map(
            callback: fn (object $row) => (array) $row,
            array: $prepared[1],
        ), $prepared[2]]);
        $this->assertSame(['v', 'r', $identity, ['listings_job_id_unique u', 'listings_pkey p', 'listings_retained_job_id_foreign f', 'listings_retained_job_id_not_null c', 'listings_uuid_unique u'], 0, true, 'listings_retained'], $installed);
        $this->assertSame(['Oak House', 'Pine Flat', 'Elm Cottage'], $titles);
        $this->assertSame(['r', null, $identity, ['listings_job_id_unique u', 'listings_pkey p', 'listings_uuid_unique u'], ['Oak House', 'Pine Flat', 'Elm Cottage'], 'listings'], $rolledBack);
        $this->assertSame([null, null, false, 0], [$this->relkind('listings'), $this->relkind('listings_retained'), DB::scalar("select to_regclass('listings_backfill') is not null"), DB::scalar("select count(*) from compatibility_backfills where legacy_type = 'listings'")]);
        $this->assertSame(3, DB::table('jobs')->count());
    }

    #[Test]
    public function it_prepares_installs_rolls_back_and_retires_a_floor_plan_whose_successor_key_prepare_adds(): void
    {
        PlaceAssetTables::create();
        PlaceAssetTables::seed();
        $identity = $this->identities('floorplans');

        Compatibility::prepare(FloorPlan::class);
        $prepared = [DB::scalar("select format_type(atttypid, atttypmod) from pg_attribute where attrelid = 'floorplans'::regclass and attname = 'place_asset_id'"), $this->constraints('floorplans')];
        $this->artisan(
            command: 'compatibility:backfill',
            parameters: ['model' => FloorPlan::class],
        )->assertSuccessful();
        Compatibility::install(
            model: FloorPlan::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $installed = [$this->identities('floorplans'), FloorPlan::query()->orderBy('id')->get(['file_name', 'url'])->toArray(), $this->constraints('floorplans_retained')];
        Compatibility::rollback(FloorPlan::class);
        $rolledBack = [$this->relkind('floorplans'), $this->identities('floorplans'), DB::table('floorplans')->orderBy('id')->get(['file_name', 'mime_type', 'url'])->map(fn (object $row) => (array) $row)->all()];
        Compatibility::prepare(FloorPlan::class);
        Compatibility::install(
            model: FloorPlan::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        Compatibility::retire(FloorPlan::class);

        $this->assertSame(['uuid', ['floorplans_pkey p', 'floorplans_place_asset_id_unique u', 'floorplans_uuid_unique u']], $prepared);
        $this->assertSame([$identity, [['file_name' => 'oak-plan.pdf', 'url' => null], ['file_name' => null, 'url' => 'https://plans.example/elm']], ['floorplans_pkey p', 'floorplans_place_asset_id_unique u', 'floorplans_retained_place_asset_id_foreign f', 'floorplans_retained_place_asset_id_not_null c', 'floorplans_uuid_unique u']], $installed);
        $this->assertSame(['r', $identity, [['file_name' => 'oak-plan.pdf', 'mime_type' => 'application/pdf', 'url' => null], ['file_name' => null, 'mime_type' => null, 'url' => 'https://plans.example/elm']]], $rolledBack);
        $this->assertSame([null, null, 2], [$this->relkind('floorplans'), $this->relkind('floorplans_retained'), DB::table('place_assets')->count()]);
    }

    #[Test]
    public function it_leaves_the_columns_of_every_successor_table_unchanged_through_prepare_and_install(): void
    {
        ListingTables::create();
        ListingTables::seed();
        PlaceAssetTables::create();
        PlaceAssetTables::seed();
        $before = $this->columns(['jobs', 'places', 'job_sites', 'place_assets', 'asset_files', 'asset_links']);

        foreach ([Listing::class, FloorPlan::class] as $model) {
            Compatibility::prepare($model);
            $this->artisan(
                command: 'compatibility:backfill',
                parameters: ['model' => $model],
            )->assertSuccessful();
            Compatibility::install(
                model: $model,
                statementTimeout: CarbonInterval::seconds(30),
            );
        }

        $this->assertNotSame([], $before['jobs']);
        $this->assertSame($before, $this->columns(['jobs', 'places', 'job_sites', 'place_assets', 'asset_files', 'asset_links']));
    }

    #[Test]
    public function it_lets_a_native_insert_name_a_missing_root_and_keeps_the_legacy_row_through_a_successor_delete_until_install_adds_the_cascade(): void
    {
        ListingTables::create();
        ListingTables::seed();
        Compatibility::prepare(Listing::class);
        $this->artisan(
            command: 'compatibility:backfill',
            parameters: ['model' => Listing::class],
        )->assertSuccessful();
        $missing = '6f1c3c0e-8a52-4d7e-9a1b-2f3e4d5c6b7a';

        DB::table('listings')->insert(['title' => 'Ash Barn', 'company_id' => ListingTables::COMPANY, 'job_id' => $missing, 'created_at' => '2026-01-06 10:00:00', 'updated_at' => '2026-01-06 10:00:00']);
        DB::table('jobs')->where(
            column: 'id',
            operator: '=',
            value: DB::table('listings')->where(
                column: 'id',
                operator: '=',
                value: 1,
            )->value('job_id'),
        )->delete();
        $beforeInstall = [DB::table('listings')->orderBy('id')->pluck('id')->all(), DB::table('listing_photos')->where(
            column: 'listing_id',
            operator: '=',
            value: 1,
        )->count()];
        DB::table('listings')->whereIn(
            column: 'id',
            values: [1, 4],
        )->delete();
        $this->artisan(
            command: 'compatibility:backfill',
            parameters: ['model' => Listing::class],
        )->assertSuccessful();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $uuid = DB::table('listings')->where(
            column: 'id',
            operator: '=',
            value: 3,
        )->value('uuid');
        DB::table('listing_shares')->insert(['listing_uuid' => $uuid]);
        DB::table('listing_photos')->insert(['listing_id' => 3, 'path' => 'elm/back.jpg']);
        DB::table('jobs')->where(
            column: 'id',
            operator: '=',
            value: DB::table('listings')->where(
                column: 'id',
                operator: '=',
                value: 3,
            )->value('job_id'),
        )->delete();

        $this->assertSame([[1, 2, 3, 4], 2], $beforeInstall);
        $this->assertSame([[2], [2], 0], [Listing::withTrashed()->orderBy('id')->pluck('id')->all(), DB::table('listings_retained')->pluck('id')->all(), DB::table('listing_photos')->where(
            column: 'listing_id',
            operator: '=',
            value: 3,
        )->count()]);
        $this->assertSame(1, DB::table('listing_shares')->whereNull('listing_uuid')->where(
            column: 'id',
            operator: '>',
            value: 2,
        )->count());
    }

    #[Test]
    public function it_resolves_a_map_built_directly_for_a_model(): void
    {
        $map = (new Compatibility(Listing::class))
            ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job->attribute(
                name: 'title',
                definition: fn (Attribute $attribute) => $attribute->from('name'),
            ))
            ->resolve();

        $this->assertSame([Listing::class, 'listings', 'job_id', ['title'], [], [], [], null], [$map->model, $map->table, $map->successorKey, array_keys($map->attributes), $map->hides, $map->guards, $map->withoutForwarding, $map->dependents]);
    }

    #[Test]
    public function it_leaves_every_successor_model_free_of_the_package(): void
    {
        $package = fn (string $name) => str_starts_with($name, 'Support\\Database\\Eloquent\\Compatibility\\');

        foreach ([Job::class, Place::class, JobSite::class, File::class, Link::class, PlaceAsset::class, AssetFile::class] as $successor) {
            $this->assertSame([], array_values(array_filter([...class_implements($successor), ...class_uses_recursive($successor)], $package)), $successor);
        }
    }

    #[Test]
    public function it_refuses_a_model_that_declares_no_map(): void
    {
        $this->expectExceptionObject(InvalidCompatibilityMap::because('jobs', 'Job does not implement '.Contracts\Compatible::class.', so it declares no map'));

        Compatibility::of(Job::class);
    }

    #[Test]
    public function it_refuses_each_schema_operation_for_a_model_that_declares_no_map_before_reading_the_database(): void
    {
        $refusals = [];
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        foreach (self::operations() as $operation) {
            try {
                $operation(Job::class);
            } catch (InvalidCompatibilityMap $refusal) {
                $refusals[] = $refusal->getMessage();
            }
        }

        $this->assertSame(array_fill(
            start_index: 0,
            count: 5,
            value: "Compatibility map 'jobs' is declared wrongly: Job does not implement ".Contracts\Compatible::class.', so it declares no map.',
        ), $refusals);
        $this->assertSame(0, $queries);
    }

    #[Test]
    public function it_refuses_each_schema_operation_for_a_successor_that_reads_another_database_before_reading_the_database(): void
    {
        config(['database.connections.compatibility_reporting' => [...(array) config('database.connections.pgsql'), 'database' => 'compatibility_reporting']]);
        $reporting = new class extends Model
        {
            protected $connection = 'compatibility_reporting';

            protected $table = 'jobs';
        };
        self::$reporting = $reporting::class;
        $legacy = new class extends Model implements Contracts\Compatible
        {
            use Provides\ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing->servedFrom(root: CompatibilityTest::$reporting, key: 'job_id', successor: fn (Successor $job) => $job->attribute(
                    name: 'title',
                    definition: fn (Attribute $attribute) => $attribute->from('name'),
                ));
            }
        };
        $refusals = [];
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        foreach (self::operations() as $operation) {
            try {
                $operation($legacy::class);
            } catch (InvalidCompatibilityMap $refusal) {
                $refusals[] = $refusal->getMessage();
            }
        }

        $this->assertSame(array_fill(
            start_index: 0,
            count: 5,
            value: "Compatibility map 'listings' is declared wrongly: the successor ".class_basename($reporting).' reads the connection compatibility_reporting, which reaches another database than the connection pgsql of '.class_basename($legacy).'; the view and its filters read every successor through the connection of '.class_basename($legacy).', so a map reads one database.',
        ), $refusals);
        $this->assertSame(0, $queries);
    }

    #[Test]
    public function it_refuses_to_prepare_a_map_whose_related_successor_reads_another_database_schema_read_configuration_or_table_prefix_before_reading_the_database(): void
    {
        $reporting = new class extends Model
        {
            protected $connection = 'compatibility_reporting';

            protected $table = 'link_previews';
        };
        $legacy = new class extends Model implements Contracts\Compatible
        {
            use Provides\ServesCompatibility;

            protected $table = 'interactive_content';

            public static function compatibility(Compatibility $content): Compatibility
            {
                return $content->servedFrom(root: Link::class, key: 'link_id', successor: fn (Successor $link) => $link
                    ->attribute(
                        name: 'url',
                        definition: fn (Attribute $attribute) => $attribute->from('url'),
                    )
                    ->related(
                        relation: fn (Link $link) => $link->hasOne(
                            related: CompatibilityTest::$reporting,
                            foreignKey: 'link_id',
                        ),
                        definition: fn (Successor $preview) => $preview,
                    ));
            }
        };
        self::$reporting = $reporting::class;
        $refusals = [];
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        foreach ([['database' => 'compatibility_reporting'], ['search_path' => 'reporting'], ['read' => ['search_path' => 'reporting'], 'write' => []], ['prefix' => 'app_']] as $elsewhere) {
            DB::purge('compatibility_reporting');
            config(['database.connections.compatibility_reporting' => [...(array) config('database.connections.pgsql'), ...$elsewhere]]);

            try {
                Compatibility::prepare($legacy::class);
            } catch (InvalidCompatibilityMap $refusal) {
                $refusals[] = $refusal->getMessage();
            }
        }

        DB::purge('compatibility_reporting');
        $relation = "Compatibility map 'interactive_content' is declared wrongly: the relation from Link to ".class_basename($reporting).' reads the connection compatibility_reporting, ';
        $this->assertSame([
            $relation.'which reaches another database than the connection pgsql of '.class_basename($legacy).'; the view and its filters read every successor through the connection of '.class_basename($legacy).', so a map reads one database.',
            $relation.'which reaches another database than the connection pgsql of '.class_basename($legacy).'; the view and its filters read every successor through the connection of '.class_basename($legacy).', so a map reads one database.',
            $relation.'whose read configuration reaches another database or search_path than the connection pgsql of '.class_basename($legacy).'; the view joins the successors the connection of '.class_basename($legacy).' writes, so the successor model would read other rows than the view serves.',
            $relation.'which adds the table prefix app_, and the package reads and compiles every table by its unprefixed name; use a connection without a prefix.',
        ], $refusals);
        $this->assertSame(0, $queries);
    }

    #[Test]
    public function it_refuses_a_morph_target_that_reads_another_database_before_reading_the_database(): void
    {
        config(['database.connections.compatibility_reporting' => [...(array) config('database.connections.pgsql'), 'database' => 'compatibility_reporting']]);
        $reporting = new class extends Model
        {
            protected $connection = 'compatibility_reporting';

            protected $table = 'asset_links';
        };
        self::$reporting = $reporting::class;
        $legacy = new class extends Model implements Contracts\Compatible
        {
            use Provides\ServesCompatibility;

            protected $table = 'place_videos';

            public static function compatibility(Compatibility $video): Compatibility
            {
                return $video->servedFrom(root: PlaceAsset::class, key: 'place_asset_id', successor: fn (Successor $asset) => $asset->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset(),
                    choose: fn (Model $video): string => CompatibilityTest::$reporting,
                    targets: fn (Targets $targets) => $targets
                        ->as(target: AssetFile::class, successor: fn (Successor $file) => $file)
                        ->as(target: CompatibilityTest::$reporting, successor: fn (Successor $link) => $link)));
            }
        };
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        try {
            Compatibility::prepare($legacy::class);
            $refusal = 'no refusal';
        } catch (InvalidCompatibilityMap $exception) {
            $refusal = $exception->getMessage();
        }

        DB::purge('compatibility_reporting');
        $this->assertSame("Compatibility map 'place_videos' is declared wrongly: the relation from PlaceAsset to ".class_basename($reporting).' reads the connection compatibility_reporting, which reaches another database than the connection pgsql of '.class_basename($legacy).'; the view and its filters read every successor through the connection of '.class_basename($legacy).', so a map reads one database.', $refusal);
        $this->assertSame(0, $queries);
    }

    #[Test]
    public function it_refuses_each_schema_operation_on_a_connection_with_a_table_prefix_before_reading_the_database(): void
    {
        config(['database.connections.compatibility_prefixed' => [...(array) config('database.connections.pgsql'), 'prefix' => 'app_'], 'database.default' => 'compatibility_prefixed']);
        $refusals = [];
        $queries = 0;

        try {
            ListingTables::create();
            ListingTables::seed();
            DB::listen(function () use (&$queries): void {
                $queries++;
            });

            foreach (self::operations() as $operation) {
                try {
                    $operation(Listing::class);
                } catch (InvalidCompatibilityMap $refusal) {
                    $refusals[] = $refusal->getMessage();
                }
            }

            $counted = $queries;
        } finally {
            ListingTables::drop();
            DB::purge('compatibility_prefixed');
            config(['database.default' => 'pgsql']);
        }

        $this->assertSame(array_fill(
            start_index: 0,
            count: 5,
            value: "Compatibility map 'listings' is declared wrongly: Listing reads the connection compatibility_prefixed, which adds the table prefix app_, and the package reads and compiles every table by its unprefixed name; use a connection without a prefix.",
        ), $refusals);
        $this->assertSame(0, $counted);
    }

    #[Test]
    public function it_refuses_a_related_successor_that_reads_another_schema_or_a_connection_nobody_configured(): void
    {
        config(['database.connections.compatibility_reporting' => [...(array) config('database.connections.pgsql'), 'search_path' => 'reporting']]);
        $sites = [
            new class extends Model
            {
                protected $connection = 'compatibility_reporting';

                protected $table = 'job_sites';
            },
            new class extends Model
            {
                protected $connection = 'compatibility_unconfigured';

                protected $table = 'job_sites';
            },
        ];
        $legacy = new class extends Model implements Contracts\Compatible
        {
            use Provides\ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job->related(
                    relation: fn (Job $job) => $job->hasOne(
                        related: CompatibilityTest::$reporting,
                        foreignKey: 'job_id',
                    ),
                    definition: fn (Successor $site) => $site,
                ));
            }
        };
        $refusals = [];

        foreach ($sites as $site) {
            self::$reporting = $site::class;

            try {
                Compatibility::install(
                    model: $legacy::class,
                    statementTimeout: CarbonInterval::seconds(5),
                );
            } catch (InvalidCompatibilityMap $refusal) {
                $refusals[] = $refusal->getMessage();
            }
        }

        $this->assertSame([
            "Compatibility map 'listings' is declared wrongly: the relation from Job to ".class_basename($sites[0]).' reads the connection compatibility_reporting, which reaches another database than the connection pgsql of '.class_basename($legacy).'; the view and its filters read every successor through the connection of '.class_basename($legacy).', so a map reads one database.',
            "Compatibility map 'listings' is declared wrongly: the relation from Job to ".class_basename($sites[1]).' reads the connection compatibility_unconfigured, which config/database.php does not declare.',
        ], $refusals);
    }

    #[Test]
    public function it_refuses_a_class_that_is_no_model(): void
    {
        $this->expectExceptionObject(InvalidCompatibilityMap::because(SyncListingToMls::class, 'it names no Eloquent model'));

        new Compatibility(SyncListingToMls::class);
    }

    /**
     * @return iterable<string, array{Closure(Compatibility): mixed, string}>
     */
    public static function invalidDeclarations(): iterable
    {
        $successor = fn (Successor $job) => $job;

        yield 'a retained table twice' => [fn (Compatibility $listing) => $listing->retainedIn('listings_retained')->retainedIn('listings_kept'), 'retainedIn() is declared twice'];
        yield 'an empty retained table' => [fn (Compatibility $listing) => $listing->retainedIn(''), 'retainedIn() names an empty table'];
        yield 'a root twice' => [fn (Compatibility $listing) => $listing->servedFrom(root: Job::class, key: 'job_id', successor: $successor)->servedFrom(root: Job::class, key: 'job_id', successor: $successor), 'servedFrom() is declared twice'];
        yield 'a root that is no model' => [fn (Compatibility $listing) => $listing->servedFrom(root: SyncListingToMls::class, key: 'job_id', successor: $successor), 'servedFrom() names '.SyncListingToMls::class.', which is not an Eloquent model']; // @phpstan-ignore argument.type
        yield 'an empty successor key' => [fn (Compatibility $listing) => $listing->servedFrom(root: Job::class, key: '', successor: $successor), "servedFrom() names the successor key '', which names no column of listings"];
        yield 'a successor key in another table' => [fn (Compatibility $listing) => $listing->servedFrom(root: Job::class, key: 'jobs.id', successor: $successor), "servedFrom() names the successor key 'jobs.id', which names no column of listings"];
        yield 'dependents that manage nothing' => [fn (Compatibility $listing) => $listing->dependents(SyncListingToMls::class), 'dependents() names '.SyncListingToMls::class.', which does not implement '.Contracts\ManagesDependents::class]; // @phpstan-ignore argument.type
        yield 'a hide rule without a reason' => [fn (Compatibility $listing) => $listing->hide(fn (Builder $jobs) => $jobs, because: ''), 'hide() gives no reason; it takes the reason the legacy model never shows these records'];
        yield 'a guard without a remedy' => [fn (Compatibility $listing) => $listing->guard('frozen', fn (Model $after, Model $before) => false, because: 'Frozen.', instead: ' '), "guard 'frozen' needs a name, a reason and what to do instead"];
        yield 'a guard twice' => [fn (Compatibility $listing) => $listing->guard('frozen', fn (Model $after, Model $before) => false, 'Frozen.', 'Wait.')->guard('frozen', fn (Model $after, Model $before) => true, 'Frozen.', 'Wait.'), "guard 'frozen' is declared twice"];
        yield 'an unknown listener' => [fn (Compatibility $listing) => $listing->withoutForwardingTo('App\\Listeners\\Missing', because: 'Gone.'), 'withoutForwardingTo() names App\\Listeners\\Missing, which is not a class'];
        yield 'a listener without a reason' => [fn (Compatibility $listing) => $listing->withoutForwardingTo(SyncListingToMls::class, because: ''), 'withoutForwardingTo('.SyncListingToMls::class.') gives no reason'];
        yield 'a listener twice' => [fn (Compatibility $listing) => $listing->withoutForwardingTo(SyncListingToMls::class, 'Own edits.')->withoutForwardingTo(SyncListingToMls::class, 'Own edits.'), 'withoutForwardingTo('.SyncListingToMls::class.') is declared twice'];
    }

    /**
     * @param  Closure(Compatibility): mixed  $declare
     */
    #[DataProvider('invalidDeclarations')]
    #[Test]
    public function it_refuses_an_invalid_declaration_and_names_the_map(Closure $declare, string $problem): void
    {
        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings', $problem));

        $declare(new Compatibility(Listing::class));
    }

    #[Test]
    public function it_refuses_each_closure_that_captures_a_variable(): void
    {
        $merged = 'merged_into_job_id';
        $refusals = [];

        foreach ([
            fn (Compatibility $listing) => $listing->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job->attribute(
                name: $merged,
                definition: fn (Attribute $attribute) => $attribute,
            )),
            fn (Compatibility $listing) => $listing->hide(fn (Builder $jobs) => $jobs->whereNotNull($merged), because: 'Merged.'),
            fn (Compatibility $listing) => $listing->guard('merged', fn (Model $after, Model $before) => $after->getAttribute($merged) !== null, because: 'Merged.', instead: 'Unmerge.'),
        ] as $declare) {
            try {
                $declare(new Compatibility(Listing::class));
            } catch (InvalidCompatibilityMap $refusal) {
                $refusals[] = $refusal->getMessage();
            }
        }

        $this->assertSame([
            "Compatibility map 'listings' is declared wrongly: the servedFrom() closure captures \$merged, and a map reads nothing but its own arguments.",
            "Compatibility map 'listings' is declared wrongly: a hide() scope captures \$merged, and a map reads nothing but its own arguments.",
            "Compatibility map 'listings' is declared wrongly: guard 'merged' captures \$merged, and a map reads nothing but its own arguments.",
        ], $refusals);
    }

    /**
     * @return list<Closure(class-string<Model>): void>
     */
    private static function operations(): array
    {
        return [
            fn (string $model) => Compatibility::prepare($model),
            fn (string $model) => Compatibility::install(
                model: $model,
                statementTimeout: CarbonInterval::seconds(5),
            ),
            fn (string $model) => Compatibility::update($model),
            fn (string $model) => Compatibility::rollback($model),
            fn (string $model) => Compatibility::retire($model),
        ];
    }

    /**
     * @return list<array{id: int, uuid: string}>
     */
    private function identities(string $table): array
    {
        return array_map(
            callback: fn (object $row) => ['id' => (int) $row->id, 'uuid' => (string) $row->uuid],
            array: DB::select("select id, uuid from {$table} order by id"),
        );
    }

    /**
     * @return list<string>
     */
    private function constraints(string $table): array
    {
        return array_column(
            array: DB::select(
                query: "select conname || ' ' || contype::text as constraint_name from pg_constraint where conrelid = to_regclass(?) and contype <> 'n' order by conname",
                bindings: [$table],
            ),
            column_key: 'constraint_name',
        );
    }

    private function relkind(string $table): null|string
    {
        $kind = DB::scalar(
            query: 'select relkind::text from pg_class where oid = to_regclass(?)',
            bindings: [$table],
        );

        return is_string($kind) ? $kind : null;
    }

    private function referencedBy(string $table): null|string
    {
        $referenced = DB::scalar(
            query: "select confrelid::regclass::text from pg_constraint where conrelid = to_regclass(?) and contype = 'f'",
            bindings: [$table],
        );

        return is_string($referenced) ? $referenced : null;
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, list<array<string, mixed>>>
     */
    private function columns(array $tables): array
    {
        $columns = [];

        foreach ($tables as $table) {
            $columns[$table] = array_map(
                callback: fn (object $row) => (array) $row,
                array: DB::select(
                    query: 'select column_name, data_type, is_nullable, column_default from information_schema.columns where table_schema = current_schema() and table_name = ? order by ordinal_position',
                    bindings: [$table],
                ),
            );
        }

        return $columns;
    }
}
