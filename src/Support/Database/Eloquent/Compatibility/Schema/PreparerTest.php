<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\FloorPlan;
use Tests\Fixtures\Support\Files\Video;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\Listings\Place;
use Tests\Fixtures\Support\PlaceAssets\PlaceAssetTables;
use Tests\TestCase;

#[CoversClass(Preparer::class)]
final class PreparerTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        ListingTables::create();
        ListingTables::seed();
    }

    protected function tearDown(): void
    {
        ListingTables::drop();
        FileTables::drop();
        PlaceAssetTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_prints_a_prepare_script_that_prepares_the_legacy_table_as_compatibility_prepare_does(): void
    {
        FileTables::create();
        FileTables::seedMedia();
        Compatibility::prepare(Video::class);
        $prepared = $this->preparedShape();
        FileTables::create();
        FileTables::seedMedia();

        Artisan::call(
            command: 'compatibility:sql',
            parameters: ['operation' => 'prepare', 'model' => Video::class],
        );
        $script = preg_replace('/^--.*$/m', '', Artisan::output()) ?? '';

        foreach (explode("\n;\n", $script) as $statement) {
            if (trim($statement) !== '') {
                DB::unprepared(trim($statement));
            }
        }

        $this->assertSame($prepared, $this->preparedShape());
        $this->assertSame(['file_id'], array_keys($prepared['videos']['columns']));
    }

    #[Test]
    public function it_adopts_an_existing_successor_key_with_a_unique_constraint_creates_the_backfill_tables_and_indexes_each_at_most_one(): void
    {
        $successors = $this->successorColumns();

        Compatibility::prepare(Listing::class);
        Compatibility::prepare(Listing::class);

        $this->assertSame(
            ['listings_job_id_unique' => 'UNIQUE (job_id)', 'listings_uuid_unique' => 'UNIQUE (uuid)'],
            array_column(
                array: DB::select("select conname, pg_get_constraintdef(oid) as definition from pg_constraint where conrelid = 'listings'::regclass and contype = 'u' order by conname"),
                column_key: 'definition',
                index_key: 'conname',
            ),
        );
        $this->assertEquals(new Column(
            type: 'uuid',
            default: null,
            notNull: false,
        ), (new ColumnCatalog(DB::connection()))->of('listings')['job_id']);
        $this->assertSame(
            'CREATE UNIQUE INDEX places_listings_at_most_one ON public.places USING btree (job_id) WHERE ((parent_id IS NULL) AND (deleted_at IS NULL))',
            DB::scalar("select indexdef from pg_indexes where indexname = 'places_listings_at_most_one'"),
        );
        $this->assertEquals([(object) ['legacy_type' => 'listings', 'watermark_at' => null, 'pass' => 0]], DB::select('select legacy_type, watermark_at, pass from compatibility_backfills'));
        $this->assertSame(['legacy_id integer', 'successor_id uuid', 'status text', 'reason text', 'attempts integer', 'attempted_at timestamp with time zone'], array_column(
            array: DB::select("select attname || ' ' || format_type(atttypid, atttypmod) as definition from pg_attribute where attrelid = 'listings_backfill'::regclass and attnum > 0 order by attnum"),
            column_key: 'definition',
        ));
        $this->assertSame($successors, $this->successorColumns());
    }

    #[Test]
    public function it_adds_a_missing_successor_key_of_the_root_key_type_without_rewriting_the_legacy_table(): void
    {
        PlaceAssetTables::create();
        PlaceAssetTables::seed();
        $file = DB::scalar("select pg_relation_filenode('floorplans')");

        Compatibility::prepare(FloorPlan::class);
        DB::table('floorplans')->insert(['file_name' => 'pine-plan.pdf']);

        $this->assertSame($file, DB::scalar("select pg_relation_filenode('floorplans')"));
        $this->assertEquals(new Column(
            type: 'uuid',
            default: null,
            notNull: false,
        ), (new ColumnCatalog(DB::connection()))->of('floorplans')['place_asset_id']);
        $this->assertSame(['UNIQUE (place_asset_id)', 0], [DB::scalar("select pg_get_constraintdef(oid) from pg_constraint where conname = 'floorplans_place_asset_id_unique'"), DB::table('place_assets')->count()]);
    }

    #[Test]
    public function it_refuses_an_adopted_successor_key_of_another_type_and_changes_nothing(): void
    {
        DB::statement('alter table listings alter column job_id type text');

        try {
            Compatibility::prepare(Listing::class);
            $this->fail('prepare() did not refuse.');
        } catch (InvalidCompatibilityMap $exception) {
            $this->assertSame("Compatibility map 'listings' is declared wrongly: listings.job_id is text, and the successor key holds the uuid key of jobs; change or drop listings.job_id, then prepare again.", $exception->getMessage());
        }

        $this->assertSame([false, false], [DB::scalar("select to_regclass('compatibility_backfills') is not null"), DB::scalar("select to_regclass('listings_job_id_unique') is not null")]);
    }

    #[Test]
    public function it_indexes_the_legacy_updated_at_when_only_a_partial_index_leads_with_it(): void
    {
        DB::statement('create index listings_live_updated_at on listings (updated_at) where deleted_at is null');

        Compatibility::prepare(Listing::class);

        $this->assertSame(['listings_live_updated_at', 'listings_updated_at_index'], array_column(DB::select("select indexname from pg_indexes where tablename = 'listings' and indexdef like '%updated_at%' order by indexname"), 'indexname'));
    }

    #[Test]
    public function it_indexes_the_legacy_updated_at_when_only_a_hash_index_that_cannot_read_a_range_leads_with_it(): void
    {
        DB::statement('create index listings_updated_at_hash on listings using hash (updated_at)');

        Compatibility::prepare(Listing::class);

        $this->assertSame(
            ['listings_updated_at_hash' => 'hash', 'listings_updated_at_index' => 'btree'],
            array_column(DB::select("select indexname, (select amname from pg_am join pg_class on pg_class.relam = pg_am.oid where pg_class.relname = indexname) as method from pg_indexes where tablename = 'listings' and indexdef like '%updated_at%' order by indexname"), 'method', 'indexname'),
        );
    }

    #[Test]
    public function it_refuses_to_prepare_when_an_index_that_cannot_serve_the_catch_up_already_takes_the_name_of_the_updated_at_index(): void
    {
        DB::statement('create index listings_updated_at_index on listings (updated_at) where id > 1');

        try {
            Compatibility::prepare(Listing::class);
            $refusal = 'no refusal';
        } catch (QueryException $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertStringContainsString("Compatibility map 'listings' cannot prepare: listings_updated_at_index already exists and is not a valid B-tree index on listings that leads with updated_at over every row, so the catch-up before install would scan listings. Rename or drop listings_updated_at_index, then prepare again.", $refusal);
        $this->assertSame(['listings_updated_at_index' => 'CREATE INDEX listings_updated_at_index ON public.listings USING btree (updated_at) WHERE (id > 1)'], array_column(DB::select("select indexname, indexdef from pg_indexes where tablename = 'listings' and indexdef like '%updated_at%'"), 'indexdef', 'indexname'));
    }

    #[Test]
    public function it_indexes_the_legacy_updated_at_once_and_leaves_an_index_that_already_leads_with_it(): void
    {
        Compatibility::prepare(Listing::class);
        Compatibility::prepare(Listing::class);
        $built = array_column(DB::select("select indexname, indexdef from pg_indexes where tablename = 'listings' and indexdef like '%updated_at%'"), 'indexdef', 'indexname');
        DB::statement('drop index listings_updated_at_index');
        DB::statement('create index listings_touched_after_id on listings (id, updated_at)');
        Compatibility::prepare(Listing::class);
        $trailing = array_column(DB::select("select indexname from pg_indexes where tablename = 'listings' and indexdef like '%updated_at%' order by indexname"), 'indexname');
        DB::statement('drop index listings_updated_at_index');
        DB::statement('create index listings_touched on listings (updated_at, id)');

        Compatibility::prepare(Listing::class);

        $this->assertSame(['listings_updated_at_index' => 'CREATE INDEX listings_updated_at_index ON public.listings USING btree (updated_at)'], $built);
        $this->assertSame(['listings_touched_after_id', 'listings_updated_at_index'], $trailing);
        $this->assertSame(['listings_touched', 'listings_touched_after_id'], array_column(DB::select("select indexname from pg_indexes where tablename = 'listings' and indexdef like '%updated_at%' order by indexname"), 'indexname'));
    }

    #[Test]
    public function it_rebuilds_the_updated_at_index_a_failed_concurrent_build_left_invalid(): void
    {
        Compatibility::prepare(Listing::class);
        DB::update("update pg_index set indisvalid = false where indexrelid = 'listings_updated_at_index'::regclass");

        Compatibility::prepare(Listing::class);

        $this->assertTrue(DB::scalar("select indisvalid from pg_index where indexrelid = 'listings_updated_at_index'::regclass"));
    }

    #[Test]
    public function it_refuses_an_at_most_one_index_over_rows_that_already_break_it_and_names_the_first_keys(): void
    {
        $job = DB::table('jobs')->insertGetId(['name' => 'Oak House', 'company_id' => ListingTables::COMPANY]);
        DB::table('places')->insert([['job_id' => $job, 'street' => '1 Oak St'], ['job_id' => $job, 'street' => '1 Oak St, rear']]);

        try {
            Compatibility::prepare(Listing::class);
            $this->fail('prepare() did not refuse.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString("Compatibility map 'listings' cannot prepare: atMostOne() on the relation from Job to Place allows one places row for each job_id, and 1 values of job_id have more; the first are {$job}", $exception->getMessage());
        }

        $this->assertNull(DB::scalar("select indexname from pg_indexes where indexname = 'places_listings_at_most_one'"));
    }

    #[Test]
    public function it_prepares_again_once_the_duplicates_it_refused_are_gone(): void
    {
        $job = DB::table('jobs')->insertGetId(['name' => 'Oak House', 'company_id' => ListingTables::COMPANY]);
        DB::table('places')->insert([['job_id' => $job, 'street' => '1 Oak St'], ['job_id' => $job, 'street' => '1 Oak St, rear']]);

        try {
            Compatibility::prepare(Listing::class);
            $this->fail('prepare() did not refuse.');
        } catch (QueryException) {
            $this->assertFalse(DB::scalar("select to_regclass('listings_backfill') is not null"));
        }

        DB::table('places')->where('street', '1 Oak St, rear')->delete();
        Compatibility::prepare(Listing::class);

        $this->assertSame(['listings_job_id_unique' => true, 'places_listings_at_most_one' => true], array_column(
            array: DB::select("select indexrelid::regclass::text as name, indisvalid from pg_index where indexrelid in ('places_listings_at_most_one'::regclass, 'listings_job_id_unique'::regclass) order by 1"),
            column_key: 'indisvalid',
            index_key: 'name',
        ));
    }

    #[Test]
    public function it_prepares_again_over_the_invalid_index_a_failed_concurrent_build_leaves(): void
    {
        Compatibility::prepare(Listing::class);
        DB::statement('alter table listings drop constraint listings_job_id_unique');
        DB::statement('create unique index listings_job_id_unique on listings (job_id)');
        DB::update("update pg_index set indisvalid = false where indexrelid in ('places_listings_at_most_one'::regclass, 'listings_job_id_unique'::regclass)");

        Compatibility::prepare(Listing::class);

        $this->assertSame(
            ['listings_job_id_unique' => true, 'places_listings_at_most_one' => true],
            array_column(
                array: DB::select("select indexrelid::regclass::text as name, indisvalid from pg_index where indexrelid in ('places_listings_at_most_one'::regclass, 'listings_job_id_unique'::regclass) order by 1"),
                column_key: 'indisvalid',
                index_key: 'name',
            ),
        );
        $this->assertSame(['listings_job_id_unique' => 'UNIQUE (job_id)', 'listings_uuid_unique' => 'UNIQUE (uuid)'], array_column(
            array: DB::select("select conname, pg_get_constraintdef(oid) as definition from pg_constraint where conrelid = 'listings'::regclass and contype = 'u' order by conname"),
            column_key: 'definition',
            index_key: 'conname',
        ));
    }

    /**
     * @return iterable<string, array{string, string, null|string}>
     */
    public static function foreignIndexes(): iterable
    {
        yield 'an index that is not unique' => ['create index places_listings_at_most_one on places (job_id) where parent_id is null and deleted_at is null', 'places_listings_at_most_one', null];
        yield 'a unique index over every row' => ['create unique index places_listings_at_most_one on places (job_id)', 'places_listings_at_most_one', null];
        yield 'a unique index over another column' => ['create unique index listings_job_id_unique on listings (title)', 'listings_job_id_unique', null];
        yield 'a table' => ['create table places_listings_at_most_one (id integer)', 'places_listings_at_most_one', 'drop table if exists places_listings_at_most_one'];
    }

    #[DataProvider('foreignIndexes')]
    #[Test]
    public function it_refuses_an_object_that_holds_the_name_of_an_index_it_builds(string $create, string $name, null|string $drop): void
    {
        DB::statement($create);

        try {
            Compatibility::prepare(Listing::class);
            $this->fail('prepare() did not refuse.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString("Compatibility map 'listings' cannot prepare: {$name} already exists and is not the unique index prepare() builds. Drop or rename it, then prepare again.", $exception->getMessage());
        } finally {
            if ($drop !== null) {
                DB::statement($drop);
            }
        }
    }

    /**
     * @return iterable<string, array{list<string>, string, string}>
     */
    public static function foreignInvalidIndexes(): iterable
    {
        yield 'on another table' => [['create table job_notes (job_id uuid)', 'create unique index listings_job_id_unique on job_notes (job_id)'], 'listings_job_id_unique', 'CREATE UNIQUE INDEX listings_job_id_unique ON public.job_notes USING btree (job_id)'];
        yield 'on the table, over another definition' => [['create unique index places_listings_at_most_one on places (job_id)'], 'places_listings_at_most_one', 'CREATE UNIQUE INDEX places_listings_at_most_one ON public.places USING btree (job_id)'];
    }

    /**
     * @param  list<string>  $create
     */
    #[DataProvider('foreignInvalidIndexes')]
    #[Test]
    public function it_refuses_and_keeps_an_invalid_index_that_holds_the_name_of_an_index_it_builds_but_not_its_table_or_definition(array $create, string $name, string $definition): void
    {
        foreach ($create as $statement) {
            DB::statement($statement);
        }

        DB::update('update pg_index set indisvalid = false where indexrelid = cast(? as regclass)', [$name]);

        try {
            Compatibility::prepare(Listing::class);
            $this->fail('prepare() did not refuse.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString("Compatibility map 'listings' cannot prepare: {$name} already exists and is not the unique index prepare() builds. Drop or rename it, then prepare again.", $exception->getMessage());
            $this->assertSame([$definition, false], [DB::scalar('select pg_get_indexdef(to_regclass(?))', [$name]), DB::scalar('select indisvalid from pg_index where indexrelid = to_regclass(?)', [$name])]);
        } finally {
            DB::statement('drop table if exists job_notes');
        }
    }

    #[Test]
    public function it_builds_an_at_most_one_index_over_rows_whose_key_is_null(): void
    {
        DB::statement('alter table places alter column job_id drop not null');
        DB::table('places')->insert([['job_id' => null, 'street' => 'Lot 1'], ['job_id' => null, 'street' => 'Lot 2']]);

        Compatibility::prepare(Listing::class);

        $this->assertTrue(DB::scalar("select indisvalid from pg_index where indexrelid = 'places_listings_at_most_one'::regclass"));
    }

    #[Test]
    public function it_refuses_to_create_an_index_concurrently_inside_a_transaction(): void
    {
        $this->expectExceptionObject(new LogicException('Compatibility::prepare() runs 7 statements after its own transaction commits, and the connection is already inside a transaction. Run it from a migration that sets public $withinTransaction = false.'));

        DB::transaction(fn () => Compatibility::prepare(Listing::class));
    }

    #[Test]
    public function it_refuses_at_most_one_on_a_relation_whose_parent_holds_the_key(): void
    {
        $map = (new Compatibility(Listing::class))
            ->servedFrom(root: Place::class, key: 'job_id', successor: fn (Successor $place) => $place
                ->related(
                    relation: fn (Place $place) => $place->job(),
                    definition: fn (Successor $job) => $job->atMostOne(because: 'A place has one job.'),
                ))
            ->resolve();

        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings', 'atMostOne() on the relation from Place to Job needs no index, because the places row names one jobs row by its key'));

        Statements::prepare($map, DB::connection());
    }

    #[Test]
    public function it_builds_an_at_most_one_index_whose_condition_binds_a_date(): void
    {
        DB::statement('alter table places add column moved_in_at timestamp(0) without time zone');
        $map = (new Compatibility(Listing::class))
            ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                ->related(
                    relation: fn (Job $job) => $job->hasOne(Place::class)->where('moved_in_at', '>=', new DateTimeImmutable('2026-01-01')),
                    definition: fn (Successor $place) => $place->atMostOne(because: 'One current address.'),
                ))
            ->resolve();

        (new Installer(DB::connection()))->compileAndRun(
            tables: [],
            compile: fn () => Statements::prepare(
                map: $map,
                connection: DB::connection(),
            ),
        );

        $this->assertSame(
            "CREATE UNIQUE INDEX places_listings_at_most_one ON public.places USING btree (job_id) WHERE ((moved_in_at >= '2026-01-01 00:00:00'::timestamp without time zone) AND (deleted_at IS NULL))",
            DB::scalar("select indexdef from pg_indexes where indexname = 'places_listings_at_most_one'"),
        );
    }

    #[Test]
    public function it_refuses_an_at_most_one_condition_whose_instant_follows_the_session_time_zone(): void
    {
        $map = (new Compatibility(Listing::class))
            ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                ->related(
                    relation: fn (Job $job) => $job->hasOne(Place::class)->where('created_at', '>=', new DateTimeImmutable('2026-01-01')),
                    definition: fn (Successor $place) => $place->atMostOne(because: 'One current address.'),
                ))
            ->resolve();

        try {
            (new Installer(DB::connection()))->compileAndRun(
                tables: [],
                compile: fn () => Statements::prepare(
                    map: $map,
                    connection: DB::connection(),
                ),
            );
            $refusal = 'no refusal';
        } catch (QueryException $exception) {
            $refusal = strtok((string) ($exception->errorInfo[2] ?? ''), "\n");
        }

        $this->assertSame(
            ["ERROR:  Compatibility map 'listings' cannot prepare: atMostOne() on the relation from Job to Place has a condition whose instant follows the session time zone, and the index and the view would each read it in the time zone of their own session. Compare a timestamp with time zone with a value that names its offset.", null],
            [$refusal, DB::scalar("select indexdef from pg_indexes where indexname = 'places_listings_at_most_one'")],
        );
    }

    #[Test]
    public function it_builds_an_at_most_one_index_whose_condition_names_the_offset_of_its_instant(): void
    {
        $map = (new Compatibility(Listing::class))
            ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                ->related(
                    relation: fn (Job $job) => $job->hasOne(Place::class)->where('created_at', '>=', '2026-01-01 00:00:00+09'),
                    definition: fn (Successor $place) => $place->atMostOne(because: 'One current address.'),
                ))
            ->resolve();

        (new Installer(DB::connection()))->compileAndRun(
            tables: [],
            compile: fn () => Statements::prepare(
                map: $map,
                connection: DB::connection(),
            ),
        );

        $this->assertSame(
            "CREATE UNIQUE INDEX places_listings_at_most_one ON public.places USING btree (job_id) WHERE ((created_at >= '2025-12-31 15:00:00+00'::timestamp with time zone) AND (deleted_at IS NULL))",
            DB::scalar("select indexdef from pg_indexes where indexname = 'places_listings_at_most_one'"),
        );
    }

    #[Test]
    public function it_refuses_an_index_name_longer_than_postgresql_keeps(): void
    {
        $legacy = new class extends Model
        {
            protected $table = 'listings_of_the_vendor_storefront_before_the_redesign';
        };
        $map = (new Compatibility($legacy::class))
            ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                ->related(
                    relation: fn (Job $job) => $job->rootPlace(),
                    definition: fn (Successor $place) => $place->atMostOne(because: 'One address.'),
                ))
            ->resolve();

        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings_of_the_vendor_storefront_before_the_redesign', 'the compiled name places_listings_of_the_vendor_storefront_before_the_redesign_at_most_one has 72 characters, and PostgreSQL keeps 63'));

        Statements::prepare($map, DB::connection());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function successorColumns(): array
    {
        return array_map(
            callback: fn (object $row) => (array) $row,
            array: DB::select("select table_name, column_name, data_type, column_default from information_schema.columns where table_schema = current_schema() and table_name in ('jobs', 'places', 'job_sites') order by table_name, ordinal_position"),
        );
    }

    /**
     * @return array<string, array{columns: array<string, list<null|string>>, constraints: array<string, string>, indexes: array<string, string>}>
     */
    private function preparedShape(): array
    {
        $shape = [];

        foreach (['videos', 'files', 'compatibility_backfills', 'videos_backfill'] as $table) {
            $shape[$table] = [
                'columns' => collect(DB::select(
                    query: "select column_name, column_default, is_nullable from information_schema.columns where table_schema = current_schema() and table_name = ? and (table_name <> 'videos' or column_name = 'file_id') order by column_name",
                    bindings: [$table],
                ))
                    ->mapWithKeys(fn (object $column) => [(string) $column->column_name => [$column->column_default === null ? null : (string) $column->column_default, (string) $column->is_nullable]])
                    ->all(),
                'constraints' => collect(DB::select('select conname, pg_get_constraintdef(oid) as definition from pg_constraint where conrelid = ?::regclass order by conname', [$table]))
                    ->mapWithKeys(fn (object $constraint) => [(string) $constraint->conname => (string) $constraint->definition])
                    ->all(),
                'indexes' => collect(DB::select('select indexname, indexdef from pg_indexes where schemaname = current_schema() and tablename = ? order by indexname', [$table]))
                    ->mapWithKeys(fn (object $index) => [(string) $index->indexname => (string) $index->indexdef])
                    ->all(),
            ];
        }

        return $shape;
    }
}
