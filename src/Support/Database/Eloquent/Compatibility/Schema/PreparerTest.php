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
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\FloorPlan;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Files\Video;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\Listings\Place;
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

        parent::tearDown();
    }

    #[Test]
    public function it_prints_a_prepare_script_for_a_map_over_two_successors_that_prepares_each_successor_as_compatibility_prepare_does(): void
    {
        FileTables::create();
        FileTables::seedMedia();
        Compatibility::prepare(Video::class);
        $prepared = $this->preparedShape();
        FileTables::create();
        FileTables::seedMedia();

        Artisan::call('compatibility:sql', ['model' => Video::class, '--for' => 'prepare']);
        $script = preg_replace('/^--.*$/m', '', Artisan::output()) ?? '';

        foreach (explode("\n;\n", $script) as $statement) {
            if (trim($statement) !== '') {
                DB::unprepared(trim($statement));
            }
        }

        $this->assertSame($prepared, $this->preparedShape());
        $this->assertSame(['legacy_id', 'legacy_type', 'legacy_uuid'], array_keys($prepared['links']['columns']));
    }

    #[Test]
    public function it_adds_a_unique_successor_column_for_each_legacy_identifier_and_an_index_for_each_at_most_one(): void
    {
        Compatibility::prepare(Listing::class);

        $this->assertEquals(
            ['legacy_id' => new Column('integer', "nextval('listings_id_seq'::regclass)", true), 'legacy_uuid' => new Column('uuid', 'gen_random_uuid()', true)],
            array_intersect_key((new ColumnCatalog(DB::connection()))->of('jobs'), ['legacy_id' => true, 'legacy_uuid' => true]),
        );
        $this->assertSame(
            ['jobs_legacy_id_unique' => 'UNIQUE (legacy_id)', 'jobs_legacy_uuid_unique' => 'UNIQUE (legacy_uuid)'],
            array_column(DB::select("select conname, pg_get_constraintdef(oid) as definition from pg_constraint where conrelid = 'jobs'::regclass and contype = 'u' order by conname"), 'definition', 'conname'),
        );
        $this->assertSame(
            'CREATE UNIQUE INDEX places_listings_at_most_one ON public.places USING btree (job_id) WHERE ((parent_id IS NULL) AND (deleted_at IS NULL))',
            DB::scalar("select indexdef from pg_indexes where indexname = 'places_listings_at_most_one'"),
        );
    }

    #[Test]
    public function it_gives_a_job_created_before_install_a_legacy_id_from_the_legacy_sequence_and_a_fresh_legacy_uuid(): void
    {
        Compatibility::prepare(Listing::class);

        DB::table('jobs')->insert(['name' => 'Birch Barn', 'company_id' => ListingTables::COMPANY]);
        DB::table('listings')->insert(['title' => 'Cedar Cabin', 'company_id' => ListingTables::COMPANY]);
        $job = DB::selectOne("select id, legacy_id, legacy_uuid from jobs where name = 'Birch Barn'");

        $this->assertSame([4, 5], [$job->legacy_id, DB::scalar("select id from listings where title = 'Cedar Cabin'")]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $job->legacy_uuid);
        $this->assertNotSame($job->id, $job->legacy_uuid);
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
            $this->assertSame([], DB::select("select attname from pg_attribute where attrelid = 'jobs'::regclass and attname like 'legacy%'"));
        }

        DB::table('places')->where('street', '1 Oak St, rear')->delete();
        Compatibility::prepare(Listing::class);

        $this->assertSame(['places_listings_at_most_one' => true], array_column(DB::select("select indexrelid::regclass::text as name, indisvalid from pg_index where indexrelid = 'places_listings_at_most_one'::regclass"), 'indisvalid', 'name'));
        $this->assertEquals((object) ['legacy_id' => 4, 'has_uuid' => true], DB::selectOne('select legacy_id, legacy_uuid is not null as has_uuid from jobs where id = ?', [$job]));
    }

    #[Test]
    public function it_prepares_again_over_the_invalid_index_a_failed_concurrent_build_leaves(): void
    {
        Compatibility::prepare(Listing::class);
        DB::statement('alter table jobs drop constraint jobs_legacy_uuid_unique');
        DB::statement('create unique index jobs_legacy_uuid_unique on jobs (legacy_uuid)');
        DB::update("update pg_index set indisvalid = false where indexrelid in ('places_listings_at_most_one'::regclass, 'jobs_legacy_uuid_unique'::regclass)");

        Compatibility::prepare(Listing::class);

        $this->assertSame(
            ['jobs_legacy_uuid_unique' => true, 'places_listings_at_most_one' => true],
            array_column(DB::select("select indexrelid::regclass::text as name, indisvalid from pg_index where indexrelid in ('places_listings_at_most_one'::regclass, 'jobs_legacy_uuid_unique'::regclass) order by 1"), 'indisvalid', 'name'),
        );
        $this->assertSame(['jobs_legacy_id_unique' => 'UNIQUE (legacy_id)', 'jobs_legacy_uuid_unique' => 'UNIQUE (legacy_uuid)'], array_column(DB::select("select conname, pg_get_constraintdef(oid) as definition from pg_constraint where conrelid = 'jobs'::regclass and contype = 'u' order by conname"), 'definition', 'conname'));
    }

    /**
     * @return iterable<string, array{string, string, null|string}>
     */
    public static function foreignIndexes(): iterable
    {
        yield 'an index that is not unique' => ['create index places_listings_at_most_one on places (job_id) where parent_id is null and deleted_at is null', 'places_listings_at_most_one', null];
        yield 'a unique index over every row' => ['create unique index places_listings_at_most_one on places (job_id)', 'places_listings_at_most_one', null];
        yield 'a unique index over another column' => ['create unique index jobs_legacy_uuid_unique on jobs (name)', 'jobs_legacy_uuid_unique', null];
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

    #[Test]
    public function it_refuses_a_constraint_that_holds_the_name_of_the_check_it_adds(): void
    {
        DB::statement("alter table jobs add constraint jobs_legacy_id_filled check (name <> '')");

        try {
            Compatibility::prepare(Listing::class);
            $this->fail('prepare() did not refuse.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString("Compatibility map 'listings' cannot prepare: jobs_legacy_id_filled already exists on jobs and is not the check prepare() adds. Drop or rename it, then prepare again.", $exception->getMessage());
        }

        $this->assertSame("CHECK (((name)::text <> ''::text))", DB::scalar("select pg_get_constraintdef(oid) from pg_constraint where conrelid = 'jobs'::regclass and conname = 'jobs_legacy_id_filled'"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function leftoverChecks(): iterable
    {
        yield 'a check not yet validated' => ['alter table jobs add constraint jobs_legacy_id_filled check (legacy_id is not null) not valid'];
        yield 'a validated check' => ['alter table jobs add constraint jobs_legacy_id_filled check (legacy_id is not null)'];
    }

    #[DataProvider('leftoverChecks')]
    #[Test]
    public function it_prepares_again_over_the_check_an_interrupted_prepare_leaves(string $check): void
    {
        DB::statement('alter table jobs add column legacy_id integer');
        DB::statement($check);

        Compatibility::prepare(Listing::class);

        $this->assertSame([], DB::select("select conname from pg_constraint where conrelid = 'jobs'::regclass and conname like '%_filled'"));
    }

    #[Test]
    public function it_lets_the_successor_update_a_row_the_identifier_fill_has_not_reached(): void
    {
        DB::table('jobs')->insert(['name' => 'Birch Barn', 'company_id' => ListingTables::COMPANY]);
        $statements = Statements::prepare(Compatibility::of(Listing::class)->resolve(), DB::connection());

        DB::transaction(function () use ($statements): void {
            foreach ($statements->transaction as $statement) {
                DB::unprepared($statement);
            }
        });
        DB::table('jobs')->where('name', 'Birch Barn')->update(['name' => 'Birch Barn, restored']);

        foreach ($statements->afterwards as $statement) {
            DB::unprepared($statement);
        }

        $this->assertEquals((object) ['name' => 'Birch Barn, restored', 'legacy_id' => 4, 'has_uuid' => true], DB::selectOne('select name, legacy_id, legacy_uuid is not null as has_uuid from jobs'));
        $this->assertSame(['legacy_id', 'legacy_uuid'], array_column(DB::select("select attname from pg_attribute where attrelid = 'jobs'::regclass and attname like 'legacy%' and attnotnull order by attname"), 'attname'));
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
    public function it_adds_the_identifier_columns_without_rewriting_the_successor_table(): void
    {
        DB::table('jobs')->insert([['name' => 'Birch Barn', 'company_id' => ListingTables::COMPANY], ['name' => 'Cedar Cabin', 'company_id' => ListingTables::COMPANY]]);
        $file = DB::scalar("select pg_relation_filenode('jobs')");

        Compatibility::prepare(Listing::class);

        $this->assertSame($file, DB::scalar("select pg_relation_filenode('jobs')"));
        $this->assertEquals([(object) ['legacy_id' => 4, 'has_uuid' => true], (object) ['legacy_id' => 5, 'has_uuid' => true]], DB::select('select legacy_id, legacy_uuid is not null as has_uuid from jobs order by legacy_id'));
    }

    #[Test]
    public function it_refuses_to_create_an_index_concurrently_inside_a_transaction(): void
    {
        $this->expectExceptionObject(new LogicException('Compatibility::prepare() runs 13 statements after its own transaction commits, and the connection is already inside a transaction. Run it from a migration that sets public $withinTransaction = false.'));

        DB::transaction(fn () => Compatibility::prepare(Listing::class));
    }

    #[Test]
    public function it_adds_the_discriminator_one_shared_sequence_and_the_unique_indexes_for_a_discriminated_successor(): void
    {
        FileTables::create();

        Compatibility::prepare(Photo::class);
        Compatibility::prepare(FloorPlan::class);
        Compatibility::prepare(Photo::class);

        $this->assertEquals(
            ['legacy_type' => new Column('text', null, false), 'legacy_id' => new Column('integer', "nextval('compatibility_legacy_id_seq'::regclass)", true), 'legacy_uuid' => new Column('uuid', 'gen_random_uuid()', true)],
            array_intersect_key((new ColumnCatalog(DB::connection()))->of('files'), ['legacy_type' => true, 'legacy_id' => true, 'legacy_uuid' => true]),
        );
        $this->assertSame(
            ['files_legacy_type_legacy_id_unique' => 'UNIQUE (legacy_type, legacy_id)', 'files_legacy_type_legacy_uuid_unique' => 'UNIQUE (legacy_type, legacy_uuid)', 'files_legacy_uuid_unique' => 'UNIQUE (legacy_uuid)'],
            array_column(DB::select("select conname, pg_get_constraintdef(oid) as definition from pg_constraint where conrelid = 'files'::regclass and contype = 'u' order by conname"), 'definition', 'conname'),
        );
        $this->assertSame(
            ["nextval('compatibility_legacy_id_seq'::regclass)", "nextval('compatibility_legacy_id_seq'::regclass)"],
            [(new ColumnCatalog(DB::connection()))->of('photos')['id']->default, (new ColumnCatalog(DB::connection()))->of('floorplans')['id']->default],
        );
    }

    #[Test]
    public function it_draws_every_legacy_id_written_after_prepare_from_one_sequence_above_every_existing_legacy_id(): void
    {
        FileTables::create();
        FileTables::seed();
        DB::table('files')->insert(['filename' => 'orphan.bin', 'mime_type' => 'application/octet-stream']);

        Compatibility::prepare(Photo::class);
        Compatibility::prepare(FloorPlan::class);
        $photo = DB::table('photos')->insertGetId(['file_name' => 'birch.jpg', 'mime_type' => 'image/jpeg']);
        $floorPlan = DB::table('floorplans')->insertGetId(['file_name' => 'birch-plan.pdf', 'mime_type' => 'application/pdf']);
        DB::table('files')->insert(['filename' => 'birch-extra.jpg', 'mime_type' => 'image/jpeg', 'legacy_type' => 'photo']);

        $this->assertEquals((object) ['legacy_type' => null, 'legacy_id' => 4], DB::selectOne("select legacy_type, legacy_id from files where filename = 'orphan.bin'"));
        $this->assertSame([5, 6, 7], [$photo, $floorPlan, DB::scalar("select legacy_id from files where filename = 'birch-extra.jpg'")]);
    }

    #[Test]
    public function it_leaves_the_shared_sequence_unwritten_when_it_already_leads_every_legacy_id(): void
    {
        FileTables::create();
        FileTables::seed();

        foreach (FileTables::MODELS as $model) {
            Compatibility::prepare($model);
        }

        FileTables::backfill();
        DB::select("select setval('compatibility_legacy_id_seq', 100)");

        Compatibility::prepare(FloorPlan::class);
        Compatibility::install(Photo::class);

        $this->assertEquals((object) ['last_value' => 100, 'is_called' => true], DB::selectOne('select last_value, is_called from compatibility_legacy_id_seq'));
    }

    #[Test]
    public function it_advances_the_shared_sequence_only_once_no_open_transaction_still_draws_from_it(): void
    {
        FileTables::create();
        FileTables::seed();
        Compatibility::prepare(Photo::class);
        $config = DB::connection()->getConfig();
        $dsn = 'pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.DB::connection()->getDatabaseName();
        $allocator = proc_open([PHP_BINARY, '-r', '$pdo = new PDO('.var_export($dsn, true).', '.var_export($config['username'], true).', '.var_export($config['password'], true).');'
            .' $pdo->beginTransaction(); $pdo->query("select nextval(\'compatibility_legacy_id_seq\')"); echo $pdo->query("select pg_backend_pid()")->fetchColumn(), "\n"; fflush(STDOUT); sleep(2); $pdo->commit(); $pdo = null;'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if ($allocator === false) {
            $this->fail('The allocating transaction did not start.');
        }

        try {
            $pid = (int) fgets($pipes[1]);
            Compatibility::prepare(FloorPlan::class);
            $state = DB::scalar('select state from pg_stat_activity where pid = ?', [$pid]);
        } finally {
            $failure = (string) stream_get_contents($pipes[2]);
            proc_close($allocator);
        }

        $this->assertNotSame(0, $pid);
        $this->assertNotSame('idle in transaction', $state);
        $this->assertSame('', $failure);
    }

    #[Test]
    public function it_refuses_identifiers_of_two_types_that_share_one_successor_column(): void
    {
        FileTables::create();
        Compatibility::prepare(Photo::class);
        DB::statement('alter table floorplans alter column id type bigint');

        $this->expectExceptionObject(InvalidCompatibilityMap::because('floorplans', 'files.legacy_id is integer, and floorplans.id is bigint; identifiers that share a successor column take one type'));

        Compatibility::prepare(FloorPlan::class);
    }

    #[Test]
    public function it_refuses_a_discriminated_integer_identifier_whose_legacy_column_draws_from_no_sequence(): void
    {
        FileTables::create();
        DB::statement('alter table photos alter column id drop default');

        $this->expectExceptionObject(InvalidCompatibilityMap::because('photos', 'photos.id draws from no sequence, and integer() on a discriminated successor moves it onto the sequence compatibility_legacy_id_seq'));

        Compatibility::prepare(Photo::class);
    }

    #[Test]
    public function it_refuses_an_integer_identifier_whose_legacy_column_draws_from_no_sequence(): void
    {
        DB::statement('alter table listings alter column id drop default');

        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings', 'listings.id draws from no sequence, and integer() shares the legacy table\'s own sequence'));

        Compatibility::prepare(Listing::class);
    }

    #[Test]
    public function it_refuses_an_integer_identifier_whose_legacy_column_is_an_identity(): void
    {
        DB::statement('alter table listings alter column id drop default');
        DB::statement('alter table listings alter column id add generated by default as identity');

        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings', 'listings.id is an identity column, and integer() shares a sequence that must outlive the legacy column'));

        Compatibility::prepare(Listing::class);
    }

    #[Test]
    public function it_refuses_at_most_one_on_a_relation_whose_parent_holds_the_key(): void
    {
        $map = (new Compatibility(Listing::class))
            ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
            ->servedFrom(Place::class, fn (Successor $place) => $place
                ->related(fn (Place $place) => $place->job(), fn (Successor $job) => $job->atMostOne(because: 'A place has one job.')))
            ->resolve();

        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings', 'atMostOne() on the relation from Place to Job needs no index, because the places row names one jobs row by its key'));

        Statements::prepare($map, DB::connection());
    }

    #[Test]
    public function it_builds_an_at_most_one_index_whose_condition_binds_a_date(): void
    {
        DB::statement('alter table places add column moved_in_at timestamp(0) without time zone');
        $map = (new Compatibility(Listing::class))
            ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
            ->servedFrom(Job::class, fn (Successor $job) => $job
                ->related(fn (Job $job) => $job->hasOne(Place::class)->where('moved_in_at', '>=', new DateTimeImmutable('2026-01-01')), fn (Successor $place) => $place->atMostOne(because: 'One current address.')))
            ->resolve();

        (new Installer(DB::connection()))->run(Statements::prepare($map, DB::connection()));

        $this->assertSame(
            "CREATE UNIQUE INDEX places_listings_at_most_one ON public.places USING btree (job_id) WHERE ((moved_in_at >= '2026-01-01 00:00:00'::timestamp without time zone) AND (deleted_at IS NULL))",
            DB::scalar("select indexdef from pg_indexes where indexname = 'places_listings_at_most_one'"),
        );
    }

    #[Test]
    public function it_refuses_an_at_most_one_condition_whose_instant_follows_the_session_time_zone(): void
    {
        $map = (new Compatibility(Listing::class))
            ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
            ->servedFrom(Job::class, fn (Successor $job) => $job
                ->related(fn (Job $job) => $job->hasOne(Place::class)->where('created_at', '>=', new DateTimeImmutable('2026-01-01')), fn (Successor $place) => $place->atMostOne(because: 'One current address.')))
            ->resolve();

        try {
            (new Installer(DB::connection()))->run(Statements::prepare($map, DB::connection()));
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
            ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
            ->servedFrom(Job::class, fn (Successor $job) => $job
                ->related(fn (Job $job) => $job->hasOne(Place::class)->where('created_at', '>=', '2026-01-01 00:00:00+09'), fn (Successor $place) => $place->atMostOne(because: 'One current address.')))
            ->resolve();

        (new Installer(DB::connection()))->run(Statements::prepare($map, DB::connection()));

        $this->assertSame(
            "CREATE UNIQUE INDEX places_listings_at_most_one ON public.places USING btree (job_id) WHERE ((created_at >= '2025-12-31 15:00:00+00'::timestamp with time zone) AND (deleted_at IS NULL))",
            DB::scalar("select indexdef from pg_indexes where indexname = 'places_listings_at_most_one'"),
        );
    }

    #[Test]
    public function it_refuses_to_share_the_legacy_sequence_with_a_successor_writer_that_cannot_draw_from_it(): void
    {
        $role = 'compatibility_job_writer_'.getmypid();
        DB::statement("create role {$role}");

        try {
            DB::statement("grant insert on jobs to {$role}");

            try {
                Compatibility::prepare(Listing::class);
                $refusal = 'no refusal';
            } catch (QueryException $exception) {
                $refusal = strtok((string) ($exception->errorInfo[2] ?? ''), "\n");
            }

            $columns = DB::scalar("select count(*) from pg_attribute where attrelid = 'jobs'::regclass and attname = 'legacy_id'");
            DB::statement("grant usage on sequence listings_id_seq to {$role}");
            Compatibility::prepare(Listing::class);
            DB::statement("set role {$role}");
            DB::table('jobs')->insert(['name' => 'Birch Barn', 'company_id' => ListingTables::COMPANY]);
            DB::statement('reset role');
        } finally {
            DB::statement('reset role');
            DB::statement("drop owned by {$role}");
            DB::statement("drop role {$role}");
        }

        $this->assertSame(
            ["ERROR:  Compatibility map 'listings' cannot prepare: jobs.legacy_id draws from listings_id_seq, and {$role} can insert into jobs without USAGE on it. Grant USAGE ON SEQUENCE listings_id_seq to each of them, then prepare again.", 0, 1],
            [$refusal, $columns, DB::table('jobs')->where('name', 'Birch Barn')->whereNotNull('legacy_id')->count()],
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
            ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
            ->servedFrom(Job::class, fn (Successor $job) => $job
                ->related(fn (Job $job) => $job->rootPlace(), fn (Successor $place) => $place->atMostOne(because: 'One address.')))
            ->resolve();

        $this->expectExceptionObject(InvalidCompatibilityMap::because('listings_of_the_vendor_storefront_before_the_redesign', 'the compiled name places_listings_of_the_vendor_storefront_before_the_redesign_at_most_one has 72 characters, and PostgreSQL keeps 63'));

        Statements::prepare($map, DB::connection());
    }

    /**
     * @return array<string, array{columns: array<string, list<null|string>>, constraints: array<string, string>, indexes: array<string, string>}>
     */
    private function preparedShape(): array
    {
        $shape = [];

        foreach (['files', 'links'] as $table) {
            $shape[$table] = [
                'columns' => collect(DB::select("select column_name, column_default, is_nullable from information_schema.columns where table_schema = current_schema() and table_name = ? and column_name like 'legacy\\_%' order by column_name", [$table]))
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
