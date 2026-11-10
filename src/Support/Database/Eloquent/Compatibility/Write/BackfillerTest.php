<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Definition\Targets;
use Support\Database\Eloquent\Compatibility\Events\WriteIgnored;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\Files\FakeMediaLibrary;
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\FileMetadata;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\InteractiveContent;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Files\Video;
use Tests\Fixtures\Support\Files\WriteFileMimeType;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\LeaveTitleUnchanged;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\Listings\NameJobFromTitle;
use Tests\Fixtures\Support\Listings\Place;
use Tests\Fixtures\Support\Listings\RecordingWriteScope;
use Tests\Fixtures\Support\PlaceAssets\AssetFile;
use Tests\Fixtures\Support\PlaceAssets\AssetLink;
use Tests\Fixtures\Support\PlaceAssets\PlaceAsset;
use Tests\Fixtures\Support\PlaceAssets\PlaceAssetTables;
use Tests\Fixtures\Support\PlaceAssets\WriteAssetFileMimeType;
use Tests\TestCase;

#[CoversClass(Backfiller::class)]
#[CoversClass(Ledger::class)]
#[CoversClass(Watermark::class)]
final class BackfillerTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        ListingTables::create();
        ListingTables::seed();
        Compatibility::prepare(Listing::class);
        WriteFileMimeType::$origins = [];
        WriteAssetFileMimeType::$seen = [];
        RecordingWriteScope::$received = [];
    }

    protected function tearDown(): void
    {
        ListingTables::drop();
        FileTables::drop();
        PlaceAssetTables::drop();
        DB::statement('drop table if exists file_references');

        parent::tearDown();
    }

    #[Test]
    public function it_creates_the_successors_of_each_row_that_the_installed_view_reads_back_as_the_seeded_rows(): void
    {
        $seeded = $this->rows('select * from listings order by id');

        $pass = $this->backfiller(Listing::class)->run();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame([3, 3, 3], [$pass->walked, $pass->changed, $pass->count(Outcome::Created)]);
        $this->assertSame($this->without(
            column: 'job_id',
            rows: $seeded,
        ), $this->without(
            column: 'job_id',
            rows: $this->rows('select * from listings order by id'),
        ));
        $this->assertSame([['created', 1], ['created', 1], ['created', 1]], array_map(
            callback: fn (array $row) => [$row['status'], $row['attempts']],
            array: $this->rows('select status, attempts from listings_backfill order by legacy_id'),
        ));
        $this->assertSame(0, DB::scalar('select count(*) from listings_backfill where successor_id is distinct from (select job_id from listings_retained where listings_retained.id = listings_backfill.legacy_id)'));
    }

    #[Test]
    public function it_writes_every_other_row_and_records_the_reason_of_the_row_an_operation_refuses(): void
    {
        $listing = $this->namedListing();
        DB::update("update listings set title = '' where id = 2");

        $pass = $this->backfiller($listing::class)->run();

        $this->assertSame([3, 2, 1, true], [$pass->walked, $pass->count(Outcome::Created), $pass->count(Outcome::Refused), $pass->blocked()]);
        $this->assertSame(['refused' => ['A job needs a name, and the listing title is blank.' => 1]], $pass->reasons);
        $this->assertSame(
            [['legacy_id' => 1, 'status' => 'created', 'reason' => null], ['legacy_id' => 2, 'status' => 'refused', 'reason' => 'A job needs a name, and the listing title is blank.'], ['legacy_id' => 3, 'status' => 'created', 'reason' => null]],
            $this->rows('select legacy_id, status, reason from listings_backfill order by legacy_id'),
        );
        $this->assertSame([2, null], [DB::table('jobs')->count(), DB::scalar('select job_id from listings where id = 2')]);
    }

    #[Test]
    public function it_changes_no_successor_row_when_it_walks_an_unchanged_row_again(): void
    {
        $this->backfiller(Listing::class)->run();
        $before = $this->successorRows();
        DB::update("update listings_backfill set status = 'failed'");

        $again = $this->backfiller(Listing::class)->run();

        $this->assertSame([3, 3, 3], [$again->walked, $again->count(Outcome::Unchanged), $again->changed]);
        $this->assertSame($before, $this->successorRows());
        $this->assertSame([0, 0], [$this->backfiller(Listing::class)->run()->walked, $this->backfiller(Listing::class)->run()->changed]);
    }

    #[Test]
    public function it_walks_only_the_rows_updated_since_its_watermark_and_retries_each_refused_row(): void
    {
        $listing = $this->namedListing();
        DB::update("update listings set title = '' where id = 2");
        $this->backfiller($listing::class)->run();
        DB::update("update listings set title = 'Oak Manor', updated_at = localtimestamp(0) where id = 1");
        DB::update("update listings set title = 'Elm Lodge' where id = 3");

        $caughtUp = $this->backfiller($listing::class)->run();

        $this->assertSame([2, 1, 1], [$caughtUp->walked, $caughtUp->count(Outcome::Updated), $caughtUp->count(Outcome::Refused)]);
        $this->assertSame(['Oak Manor', 'Elm Cottage'], array_column(
            array: $this->rows('select jobs.name from jobs join listings on listings.job_id = jobs.id order by listings.id'),
            column_key: 'name',
        ));
        $this->assertSame([[1, 2], [2, 2], [3, 1]], array_map(
            callback: fn (array $row) => [$row['legacy_id'], $row['attempts']],
            array: $this->rows('select legacy_id, attempts from listings_backfill order by legacy_id'),
        ));
    }

    #[Test]
    public function it_catches_up_a_row_a_transaction_stamped_before_a_pass_started_and_committed_after_it(): void
    {
        $this->backfiller(Listing::class)->run();
        [$process, $pipes] = $this->transaction("update listings set title = 'Oak Manor', updated_at = localtimestamp - interval '1 second' where id = 1", seconds: 2);
        $this->assertSame('written', trim((string) fgets($pipes[1])));

        $during = $this->backfiller(Listing::class)->run();
        $started = DB::scalar("select pass_started_at at time zone 'UTC' from compatibility_backfills where legacy_type = 'listings'");
        proc_close($process);
        $after = $this->backfiller(Listing::class)->run();

        $this->assertSame([0, 1, 1], [$during->walked, $after->walked, $after->count(Outcome::Updated)]);
        $this->assertSame('Oak Manor', DB::scalar('select jobs.name from jobs join listings on listings.job_id = jobs.id where listings.id = 1'));
        $this->assertTrue(DB::scalar('select updated_at < cast(? as timestamp) from listings where id = 1', [$started]));
    }

    #[Test]
    public function it_retries_a_row_with_a_null_updated_at_and_a_refused_row_on_every_pass(): void
    {
        $listing = $this->namedListing();
        DB::update("update listings set title = '' where id = 3");
        DB::update('update listings set updated_at = null where id = 2');

        $passes = [$this->backfiller($listing::class)->run(), $this->backfiller($listing::class)->run(), $this->backfiller($listing::class)->run()];

        $this->assertSame([3, 2, 2], array_map(fn (Pass $pass) => $pass->walked, $passes));
        $this->assertSame([[1, 'created', 1], [2, 'unchanged', 3], [3, 'refused', 3]], array_map(
            callback: fn (array $row) => [$row['legacy_id'], $row['status'], $row['attempts']],
            array: $this->rows('select legacy_id, status, attempts from listings_backfill order by legacy_id'),
        ));
        $this->assertSame([1, 0], [$passes[1]->changed, $passes[2]->changed]);
    }

    #[Test]
    public function it_creates_the_root_a_legacy_key_names_with_that_key_and_takes_a_trashed_root_as_existing(): void
    {
        $named = '019a5e3c-8f00-7000-8000-000000000011';
        $trashed = '019a5e3c-8f00-7000-8000-000000000012';
        DB::insert("insert into jobs (id, name, company_id, deleted_at) values ('{$trashed}', 'Trashed', '".ListingTables::COMPANY."', now())");
        DB::update("update listings set job_id = '{$named}' where id = 1");
        DB::update("update listings set job_id = '{$trashed}' where id = 2");

        $pass = $this->backfiller(Listing::class)->run();

        $this->assertSame([2, 1], [$pass->count(Outcome::Created), $pass->count(Outcome::Updated)]);
        $this->assertSame([$named, 'Oak House'], [DB::scalar('select job_id from listings where id = 1'), DB::scalar('select name from jobs where id = ?', [$named])]);
        $this->assertSame([3, 'Pine Flat'], [DB::table('jobs')->count(), DB::scalar('select name from jobs where id = ?', [$trashed])]);
        $this->assertSame(['successor_id' => $trashed, 'status' => 'updated'], (array) DB::selectOne('select successor_id, status from listings_backfill where legacy_id = 2'));
    }

    #[Test]
    public function it_soft_deletes_the_root_of_a_hard_deleted_legacy_row_fires_its_events_and_never_selects_the_row_again(): void
    {
        $this->backfiller(Listing::class)->run();
        $job = DB::scalar('select job_id from listings where id = 3');
        $fired = [];
        Event::listen('eloquent.deleting: '.Job::class, function (Job $deleting) use (&$fired): void {
            $fired[] = ['deleting', $deleting->getKey()];
        });
        Event::listen('eloquent.deleted: '.Job::class, function (Job $deleted) use (&$fired): void {
            $fired[] = ['deleted', $deleted->getKey()];
        });
        DB::delete('delete from listings where id = 3');

        $pass = $this->backfiller(Listing::class)->run();
        $again = $this->backfiller(Listing::class)->run();

        $this->assertSame([1, 1, 0], [$pass->count(Outcome::Deleted), $pass->changed, $again->count(Outcome::Deleted)]);
        $this->assertSame([['deleting', $job], ['deleted', $job]], $fired);
        $this->assertSame([true, 'Elm Cottage'], [DB::scalar('select deleted_at is not null from jobs where id = ?', [$job]), DB::scalar('select name from jobs where id = ?', [$job])]);
        $this->assertSame(['successor_id' => $job, 'status' => 'deleted', 'attempts' => 2], (array) DB::selectOne('select successor_id, status, attempts from listings_backfill where legacy_id = 3'));
    }

    #[Test]
    public function it_marks_failed_and_keeps_a_successor_whose_delete_a_foreign_key_refuses(): void
    {
        FileTables::create();
        FileTables::seed();
        Compatibility::prepare(Photo::class);
        $this->backfiller(Photo::class)->run();
        $file = FileTables::fileOf(table: 'photos', id: 1);
        DB::statement('create table file_references (file_id uuid not null references files (id))');
        DB::insert('insert into file_references (file_id) values (?)', [$file]);
        DB::delete('delete from photos where id = 1');

        $pass = $this->backfiller(Photo::class)->run();

        $this->assertSame([1, 0], [$pass->count(Outcome::Failed), $pass->count(Outcome::Deleted)]);
        $this->assertSame(1, DB::table('files')->where('id', $file)->count());
        $this->assertSame(['status' => 'failed', 'successor_id' => $file, 'refused' => true], (array) DB::selectOne("select status, successor_id, reason like '%23503%' as refused from photos_backfill where legacy_id = 1"));
    }

    #[Test]
    public function it_runs_the_operation_of_a_computed_attribute_on_every_walked_row_with_origin_backfill_and_records_an_unchanged_row(): void
    {
        FileTables::create();
        FileTables::seed();
        Compatibility::prepare(Photo::class);
        $this->backfiller(Photo::class)->run();
        $before = $this->rows('select xmin::text as version, files.* from files order by id');
        WriteFileMimeType::$origins = [];
        DB::update("update photos_backfill set status = 'failed'");

        $pass = $this->backfiller(Photo::class)->run();

        $this->assertSame([3, 3], [$pass->walked, $pass->count(Outcome::Unchanged)]);
        $this->assertSame([Origin::Backfill, Origin::Backfill, Origin::Backfill], WriteFileMimeType::$origins);
        $this->assertSame($before, $this->rows('select xmin::text as version, files.* from files order by id'));
    }

    #[Test]
    public function it_skips_the_computed_operation_of_a_row_whose_chosen_target_does_not_declare_the_attribute(): void
    {
        PlaceAssetTables::create();
        PlaceAssetTables::seed();
        $plan = $this->computedFloorPlan();
        Compatibility::prepare($plan::class);

        $pass = $this->backfiller($plan::class)->run();

        $this->assertSame([2, 2], [$pass->walked, $pass->count(Outcome::Created)]);
        $this->assertSame([[1, Origin::Backfill]], WriteAssetFileMimeType::$seen);
        $this->assertSame([['file_name' => 'oak-plan.pdf', 'mime_type' => 'application/pdf', 'url' => null], ['file_name' => null, 'mime_type' => null, 'url' => 'https://plans.example/elm']], $this->rows('select asset_files.filename as file_name, asset_files.mime_type, asset_links.url from floorplans join place_assets on place_assets.id = floorplans.place_asset_id left join asset_files on asset_files.id = place_assets.asset_id left join asset_links on asset_links.id = place_assets.asset_id order by floorplans.id'));
    }

    #[Test]
    public function it_dispatches_no_write_ignored_for_a_discarded_attribute_of_a_catch_up_row(): void
    {
        $listing = $this->ignoringListing();
        $ignored = [];
        Event::listen(WriteIgnored::class, function (WriteIgnored $event) use (&$ignored): void {
            $ignored[] = [$event->change->attribute, $event->value, $event->change->origin];
        });
        $created = $this->backfiller($listing::class)->run();
        DB::update("update listings set mls_number = 'MLS-9', updated_at = localtimestamp(0) where id = 1");

        $caughtUp = $this->backfiller($listing::class)->run();

        $this->assertSame([[3, 3], [1, 1]], [[$created->walked, $created->count(Outcome::Created)], [$caughtUp->walked, $caughtUp->count(Outcome::Updated)]]);
        $this->assertSame([['mls_number', 'MLS-1', Origin::Backfill], ['mls_number', 'MLS-3', Origin::Backfill]], $ignored);
    }

    #[Test]
    public function it_refuses_a_pass_while_another_session_holds_the_advisory_lock_of_the_legacy_model(): void
    {
        config(['database.connections.second' => config('database.connections.'.DB::getDefaultConnection())]);
        DB::connection('second')->select("select pg_advisory_lock(hashtextextended('compatibility_backfills:listings', 0))");

        try {
            $this->backfiller(Listing::class)->run();
            $this->fail('The pass did not refuse.');
        } catch (InvalidCompatibilityMap $exception) {
            $this->assertSame("Compatibility map 'listings' cannot backfill: another backfill pass or install of listings holds its advisory lock, and each legacy model runs one pass at a time; wait for it to finish, then backfill again.", $exception->getMessage());
        } finally {
            DB::disconnect('second');
        }

        $this->assertSame([0, 0], [DB::table('jobs')->count(), DB::scalar("select pass from compatibility_backfills where legacy_type = 'listings'")]);
        $this->assertSame(3, $this->backfiller(Listing::class)->run()->walked);
    }

    #[Test]
    public function it_counts_a_pass_once_it_starts_and_finishes_it_with_the_rows_it_walked_and_its_start_as_the_watermark(): void
    {
        $during = null;
        Event::listen('eloquent.saving: '.Job::class, function () use (&$during): void {
            $during ??= (array) DB::selectOne("select pass, pass_finished_at is null as running, watermark_at is null as unmarked from compatibility_backfills where legacy_type = 'listings'");
        });

        $this->backfiller(Listing::class)->run();

        $this->assertSame(['pass' => 1, 'running' => true, 'unmarked' => true], $during);
        $this->assertSame(['pass' => 1, 'finished' => true, 'pass_rows' => 3, 'marked' => true], (array) DB::selectOne("select pass, pass_finished_at is not null as finished, pass_rows, watermark_at = pass_started_at as marked from compatibility_backfills where legacy_type = 'listings'"));
    }

    #[Test]
    public function it_calls_the_write_scope_inside_each_row_before_its_first_lock(): void
    {
        $listing = $this->scopedListing();
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->backfiller($listing::class)->run();

        $this->assertSame(array_fill(0, 3, [Job::class => [], Place::class => []]), RecordingWriteScope::$received);
        $marker = array_search("select 'the write scope' as marker", $statements, true);
        $lock = array_search('select * from "listings" where "id" = ? limit 1 for update', $statements, true);
        $this->assertIsInt($marker);
        $this->assertIsInt($lock);
        $this->assertLessThan($lock, $marker);
    }

    #[Test]
    public function it_refuses_a_catch_up_row_whose_operation_loses_the_value_through_the_round_trip(): void
    {
        $this->backfiller(Listing::class)->run();
        $listing = $this->losingListing();
        DB::update("update listings set title = 'Oak Manor', updated_at = localtimestamp(0) where id = 1");

        $pass = $this->backfiller($listing::class)->run();

        $this->assertSame(1, $pass->count(Outcome::Failed));
        $this->assertSame('Oak House', DB::scalar('select jobs.name from jobs join listings on listings.job_id = jobs.id where listings.id = 1'));
        $this->assertStringContainsString('$title', (string) DB::scalar('select reason from listings_backfill where legacy_id = 1'));
    }

    #[Test]
    public function it_creates_a_dependent_once_a_mapped_value_is_set_and_updates_the_one_the_relation_reads(): void
    {
        $this->backfiller(Listing::class)->run();
        $place = DB::scalar('select places.id from places join listings on listings.job_id = places.job_id where listings.id = 1');
        DB::update("update listings set street = '2 Pine Rd', latitude = 45.5, updated_at = localtimestamp(0) where id = 2");
        DB::update("update listings set street = '1 Oak Ave', updated_at = localtimestamp(0) where id = 1");

        $again = $this->backfiller(Listing::class)->run();

        $this->assertSame([2, 2], [$again->walked, $again->count(Outcome::Updated)]);
        $this->assertSame(
            [['legacy_id' => 1, 'street' => '1 Oak Ave', 'parent_id' => null], ['legacy_id' => 2, 'street' => '2 Pine Rd', 'parent_id' => null], ['legacy_id' => 3, 'street' => '3 Elm St', 'parent_id' => null]],
            $this->rows('select listings.id as legacy_id, places.street, places.parent_id from places join listings on listings.job_id = places.job_id order by listings.id'),
        );
        $this->assertSame($place, DB::scalar('select places.id from places join listings on listings.job_id = places.job_id where listings.id = 1'));
        $this->assertSame([1, 2, 3], array_map(
            callback: intval(...),
            array: array_column(
                array: $this->rows('select listings.id as legacy_id from job_sites join listings on listings.job_id = job_sites.job_id order by listings.id'),
                column_key: 'legacy_id',
            ),
        ));
    }

    #[Test]
    public function it_fills_the_with_attributes_of_a_relation_into_each_dependent_it_creates(): void
    {
        $listing = $this->seattleListing(withAttributes: true);

        $this->backfiller($listing::class)->run();

        $this->assertSame([['legacy_id' => 1, 'street' => '1 Oak St', 'city' => 'Seattle'], ['legacy_id' => 3, 'street' => '3 Elm St', 'city' => 'Seattle']], $this->rows('select listings.id as legacy_id, places.street, places.city from places join listings on listings.job_id = places.job_id order by listings.id'));
        $this->assertSame(0, DB::scalar('select count(*) from jobs where created_at is null or updated_at is null'));
    }

    #[Test]
    public function it_fails_a_row_whose_dependent_its_relation_would_not_read_back_and_writes_the_others(): void
    {
        $listing = $this->seattleListing(withAttributes: false);

        $pass = $this->backfiller($listing::class)->run();

        $this->assertSame([1, 2], [$pass->count(Outcome::Created), $pass->count(Outcome::Failed)]);
        $this->assertSame([1, 0], [DB::scalar('select count(*) from jobs'), DB::scalar('select count(*) from places')]);
        $this->assertSame(['failed', 'created', 'failed'], array_column($this->rows('select status from listings_backfill order by legacy_id'), 'status'));
    }

    #[Test]
    public function it_gives_each_successor_row_the_unique_id_its_model_generates_when_the_table_has_no_default(): void
    {
        foreach (['jobs', 'places', 'job_sites'] as $table) {
            DB::statement("alter table {$table} alter column id drop default");
        }

        $pass = $this->backfiller(Listing::class)->run();

        $this->assertSame(3, $pass->count(Outcome::Created));
        $this->assertSame(7, DB::scalar('select (select count(distinct id) from jobs) + (select count(distinct id) from places) + (select count(distinct id) from job_sites)'));
    }

    #[Test]
    public function it_fails_a_row_whose_legacy_value_its_conversions_cannot_store_and_writes_the_others(): void
    {
        DB::update("update listings set status = 'Archived' where id = 3");
        $listing = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->attribute(
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                        )
                        ->attribute(
                            name: 'status',
                            definition: fn (Attribute $attribute) => $attribute->from('lifecycle_status')->mapping(['drafting' => 'Draft', 'live' => 'Active', 'closed' => 'Sold']),
                        ));
            }
        };

        $pass = $this->backfiller($listing::class)->run();

        $this->assertSame([2, 1], [$pass->count(Outcome::Created), $pass->count(Outcome::Failed)]);
        $this->assertSame(2, DB::scalar('select count(*) from jobs'));
        $this->assertStringContainsString("mapping() maps no stored value to 'Archived'", (string) DB::scalar('select reason from listings_backfill where legacy_id = 3'));
    }

    #[Test]
    public function it_refuses_to_walk_a_legacy_table_that_install_already_replaced_with_the_view(): void
    {
        $this->backfiller(Listing::class)->run();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->expectExceptionObject(InvalidCompatibilityMap::cannot('listings', 'backfill', 'listings is already installed as a view, and the backfill walks the legacy table while it is still a table'));

        $this->backfiller(Listing::class)->run();
    }

    #[Test]
    public function it_gives_each_model_on_a_shared_root_class_its_own_root_rows_and_keeps_its_legacy_ids(): void
    {
        FileTables::create();
        FileTables::seed();
        FakeMediaLibrary::bound()->calls = [];

        foreach (FileTables::MODELS as $model) {
            Compatibility::prepare($model);
            $this->backfiller($model)->run();
        }

        foreach (FileTables::MODELS as $model) {
            Compatibility::install(
                model: $model,
                statementTimeout: CarbonInterval::seconds(30),
            );
        }

        $this->assertSame(
            [['photos', 1, 'oak-front.jpg'], ['photos', 2, 'oak-back.jpg'], ['photos', 3, 'elm.png'], ['legacy_files', 1, 'contract.pdf'], ['legacy_files', 2, 'notes.txt']],
            array_map(
                callback: fn (array $row) => [$row['legacy'], $row['id'], $row['filename']],
                array: $this->rows("select 'photos' as legacy, photos_retained.id, files.filename from files join photos_retained on photos_retained.file_id = files.id union all select 'legacy_files', legacy_files_retained.id, files.filename from files join legacy_files_retained on legacy_files_retained.file_id = files.id order by legacy desc, id"),
            ),
        );
        $this->assertSame(5, DB::scalar('select count(*) from files'));
        $this->assertSame(['image/jpeg', 'image/jpeg', 'image/png'], Photo::query()->orderBy('id')->get()->map(fn (Photo $photo) => $photo->mime_type)->all());
        $this->assertSame(3, DB::scalar('select count(zml_asset_id) from files where id in (select file_id from photos_retained)'));
        $this->assertSame(['512.000', '2048.000', '1.500'], array_column($this->rows('select size_kb from photos order by id'), 'size_kb'));
    }

    #[Test]
    public function it_writes_a_computed_value_through_the_successor_and_forwards_no_legacy_event(): void
    {
        FileTables::create();
        FileTables::seed();
        Compatibility::prepare(Photo::class);
        FakeMediaLibrary::bound()->calls = [];
        $forwarded = [];
        Event::listen('eloquent.*: '.Photo::class, function (string $event) use (&$forwarded): void {
            $forwarded[] = $event;
        });

        $created = $this->backfiller(Photo::class)->run();
        $again = $this->backfiller(Photo::class)->run();
        DB::update("update photos set mime_type = 'image/webp', updated_at = localtimestamp(0) where id = 3");
        $changed = $this->backfiller(Photo::class)->run();

        $this->assertSame([3, 0, 1], [$created->count(Outcome::Created), $again->walked, $changed->count(Outcome::Updated)]);
        $this->assertSame([], $forwarded);
        $this->assertSame(['store', 'store', 'store', 'fetch', 'put'], array_column(FakeMediaLibrary::bound()->calls, 0));
        $this->assertSame('image/webp', FakeMediaLibrary::bound()->stored((string) File::query()->whereKey(FileTables::fileOf(table: 'photos', id: 3))->value('zml_asset_id'))?->mimeType);
    }

    #[Test]
    public function it_backfills_a_map_with_a_computed_attribute_that_install_serves_and_rollback_restores(): void
    {
        FileTables::create();
        FileTables::seedMedia();
        $seeded = $this->rows('select * from videos order by id');
        Compatibility::prepare(Video::class);
        Compatibility::prepare(InteractiveContent::class);

        $this->backfiller(Video::class)->run();
        $this->backfiller(InteractiveContent::class)->run();
        Compatibility::install(
            model: Video::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        Compatibility::install(
            model: InteractiveContent::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame($this->without(
            column: 'mime_type',
            rows: $seeded,
        ), $this->without(
            column: 'file_id',
            rows: $this->rows('select * from videos order by id'),
        ));
        $this->assertSame(['video/mp4', null, 'video/quicktime'], Video::query()->orderBy('id')->get()->map(fn (Video $video) => $video->mime_type)->all());
        $this->assertSame(['https://tour.example/oak', 'https://tour.example/elm'], array_column($this->rows('select url from interactive_content order by id'), 'url'));

        Compatibility::rollback(Video::class);

        $this->assertSame(array_map(
            callback: $this->sorted(...),
            array: $seeded,
        ), array_map(
            callback: $this->sorted(...),
            array: $this->without(
                column: 'file_id',
                rows: $this->rows('select * from videos order by id'),
            ),
        ));
    }

    #[Test]
    public function it_writes_a_json_column_postgresql_cannot_compare_for_equality_and_changes_nothing_when_it_walks_it_again(): void
    {
        DB::statement('alter table listings add column details json');
        DB::statement('alter table jobs add column details json');
        DB::update('update listings set details = ? where id = 1', ['{"rooms": 3}']);
        $listing = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->attribute(
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                        )
                        ->attribute(
                            name: 'details',
                            definition: fn (Attribute $attribute) => $attribute->from('details'),
                        ));
            }
        };

        $first = $this->backfiller($listing::class)->run();
        DB::update("update listings_backfill set status = 'failed'");
        $again = $this->backfiller($listing::class)->run();

        $this->assertSame([3, 3], [$first->count(Outcome::Created), $again->count(Outcome::Unchanged)]);
        $this->assertSame(['{"rooms": 3}', null, null], array_column(
            array: $this->rows('select cast(jobs.details as text) as details from jobs join listings on listings.job_id = jobs.id order by listings.id'),
            column_key: 'details',
        ));
    }

    #[Test]
    public function it_keeps_the_stored_value_that_already_serves_an_unchanged_is_not_null_attribute_and_writes_only_the_values_whose_presence_changed(): void
    {
        DB::statement('alter table listings add column is_published boolean');
        DB::statement('alter table jobs add column published_at timestamp(0) without time zone');
        DB::update('update listings set is_published = id in (1, 2)');
        $listing = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->attribute(
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                        )
                        ->attribute(
                            name: 'is_published',
                            definition: fn (Attribute $attribute) => $attribute->from('published_at')->isNotNull(fn () => now()),
                        ));
            }
        };
        $this->backfiller($listing::class)->run();
        DB::update("update jobs set published_at = '2026-01-01 09:00:00' where id = (select job_id from listings where id = 1)");
        DB::update("update listings_backfill set status = 'failed'");
        $unchanged = $this->backfiller($listing::class)->run();
        DB::update('update listings set is_published = false where id = 2');
        DB::update('update listings set is_published = true where id = 3');
        DB::update("update listings_backfill set status = 'failed'");

        $changed = $this->backfiller($listing::class)->run();

        $this->assertSame([3, 2], [$unchanged->count(Outcome::Unchanged), $changed->count(Outcome::Updated)]);
        $this->assertSame(
            [['legacy_id' => 1, 'published_at' => '2026-01-01 09:00:00'], ['legacy_id' => 2, 'published_at' => null], ['legacy_id' => 3, 'published_at' => 'set']],
            $this->rows("select listings.id as legacy_id, case when listings.id = 3 and jobs.published_at is not null then 'set' else cast(jobs.published_at as text) end as published_at from jobs join listings on listings.job_id = jobs.id order by listings.id"),
        );
    }

    #[Test]
    public function it_writes_the_root_a_live_writer_names_while_its_pass_runs_and_creates_no_root_beside_it(): void
    {
        $raced = '019a5e3c-8f00-7000-8000-000000000002';
        $read = false;
        DB::listen(function (QueryExecuted $query) use (&$read, $raced): void {
            if ($read || $query->sql !== 'select * from "listings" order by "id" asc limit 1000') {
                return;
            }

            $read = true;
            $this->assertSame('unblocked', $this->concurrently("insert into jobs (id, name, company_id) values ('{$raced}', 'Raced', '".ListingTables::COMPANY."'); update listings set job_id = '{$raced}' where id = 2"));
        });

        $this->backfiller(Listing::class)->run();

        $this->assertSame([true, $raced, 3, 0], [$read, DB::scalar('select job_id from listings where id = 2'), DB::table('jobs')->count(), DB::scalar('select count(*) from jobs where id not in (select job_id from listings)')]);
        $this->assertSame(['successor_id' => $raced, 'status' => 'updated'], (array) DB::selectOne('select successor_id, status from listings_backfill where legacy_id = 2'));
        $this->assertSame('Pine Flat', DB::scalar('select name from jobs where id = ?', [$raced]));
    }

    #[Test]
    public function it_keeps_the_successors_of_a_legacy_row_whose_key_changed(): void
    {
        $this->backfiller(Listing::class)->run();
        $before = [DB::scalar('select job_id from listings where id = 1'), DB::scalar('select places.street from places join listings on listings.job_id = places.job_id where listings.id = 1')];
        DB::statement('alter table listing_photos drop constraint listing_photos_listing_id_foreign, add constraint listing_photos_listing_id_foreign foreign key (listing_id) references listings (id) on update cascade');
        DB::update('update listings set id = 99, updated_at = localtimestamp(0) where id = 1');

        $this->backfiller(Listing::class)->run();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame([2, 3, 99], array_map(
            callback: intval(...),
            array: array_column(
                array: $this->rows('select id from listings_retained order by id'),
                column_key: 'id',
            ),
        ));
        $this->assertSame($before, [DB::scalar('select job_id from listings_retained where id = 99'), DB::scalar('select places.street from places join listings_retained on listings_retained.job_id = places.job_id where listings_retained.id = 99')]);
        $this->assertSame([[2, 'Pine Flat'], [3, 'Elm Cottage'], [99, 'Oak House']], array_map(fn (array $row) => [(int) $row['id'], $row['title']], $this->rows('select id, title from listings order by id')));
    }

    #[Test]
    public function it_keeps_a_mapped_updated_at_while_it_saves_another_changed_value_of_a_successor(): void
    {
        FileTables::create();
        FileTables::seed();
        $photo = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'photos';

            public function getMorphClass()
            {
                return 'photo';
            }

            public static function compatibility(Compatibility $photo): Compatibility
            {
                return $photo
                    ->retainedIn('photos_retained')
                    ->servedFrom(root: File::class, key: 'file_id', successor: fn (Successor $file) => $file
                        ->attribute(
                            name: 'file_name',
                            definition: fn (Attribute $attribute) => $attribute->from('filename'),
                        )
                        ->attribute(
                            name: 'updated_at',
                            definition: fn (Attribute $attribute) => $attribute->from('updated_at'),
                        )
                        ->attribute(
                            name: 'mime_type',
                            definition: fn (Attribute $attribute) => $attribute
                                ->computedAs(read: fn (Collection $files): array => File::metadataFor($files)->map(fn (FileMetadata $metadata) => $metadata->mimeType)->all())->writtenBy(WriteFileMimeType::class),
                        ));
            }
        };
        Compatibility::prepare($photo::class);
        $this->backfiller($photo::class)->run();
        DB::update("update photos set file_name = 'oak-front-2.jpg' where id = 1");
        DB::update("update photos_backfill set status = 'failed' where legacy_id = 1");

        $changed = $this->backfiller($photo::class)->run();

        $this->assertSame(1, $changed->count(Outcome::Updated));
        $this->assertSame(
            ['filename' => 'oak-front-2.jpg', 'updated_at' => '2026-01-01 10:00:00', 'created' => true],
            (array) DB::selectOne("select filename, to_char(updated_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as updated_at, created_at is not null as created from files where id = (select file_id from photos where id = 1)"),
        );
    }

    #[Test]
    public function it_creates_a_dependent_whose_relation_fixes_a_timestamp_with_that_timestamp_and_stamps_only_the_other(): void
    {
        $listing = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->attribute(
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                        )
                        ->related(
                            relation: fn (Job $job) => $job->hasOne(Place::class)->withAttributes(['city' => 'Seattle', 'created_at' => '2026-01-01 00:00:00+00']),
                            successor: fn (Successor $place) => $place->attribute('street', fn (Attribute $attribute) => $attribute->from('street')),
                        ));
            }
        };

        $this->backfiller($listing::class)->run();

        $this->assertSame(
            [['legacy_id' => 1, 'street' => '1 Oak St', 'created_at' => '2026-01-01 00:00:00', 'updated' => true], ['legacy_id' => 3, 'street' => '3 Elm St', 'created_at' => '2026-01-01 00:00:00', 'updated' => true]],
            $this->rows("select listings.id as legacy_id, places.street, to_char(places.created_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as created_at, places.updated_at is not null as updated from places join listings on listings.job_id = places.job_id order by listings.id"),
        );
    }

    private function namedListing(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name')->writtenBy(NameJobFromTitle::class),
                        )
                        ->attribute(
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                        )
                        ->attribute(
                            name: 'updated_at',
                            definition: fn (Attribute $attribute) => $attribute->from('updated_at'),
                        ));
            }
        };
    }

    private function losingListing(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name')->writtenBy(LeaveTitleUnchanged::class),
                        )
                        ->attribute(
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                        ));
            }
        };
    }

    private function ignoringListing(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->attribute(
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                        )
                        ->attribute(
                            name: 'updated_at',
                            definition: fn (Attribute $attribute) => $attribute->from('updated_at'),
                        )
                        ->attribute(
                            name: 'mls_number',
                            definition: fn (Attribute $attribute) => $attribute->readAs("'MLS-' || jobs.name")->readOnly(because: 'v2 numbers its own listings.', ignoreWrites: true),
                        ));
            }
        };
    }

    private function scopedListing(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->writesWithin(RecordingWriteScope::class)
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->attribute(
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                        )
                        ->related(
                            relation: fn (Job $job) => $job->rootPlace(),
                            successor: fn (Successor $place) => $place->attribute('street', fn (Attribute $attribute) => $attribute->from('street')),
                        ));
            }
        };
    }

    private function computedFloorPlan(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'floorplans';

            public static function compatibility(Compatibility $plan): Compatibility
            {
                return $plan
                    ->retainedIn('floorplans_retained')
                    ->servedFrom(root: PlaceAsset::class, key: 'place_asset_id', successor: fn (Successor $asset) => $asset
                        ->attribute(
                            name: 'created_at',
                            definition: fn (Attribute $attribute) => $attribute->from('created_at'),
                        )
                        ->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset(),
                            choose: fn (Model $plan): string => $plan->getAttribute('url') !== null ? AssetLink::class : AssetFile::class,
                            targets: fn (Targets $targets) => $targets
                                ->as(target: AssetFile::class, successor: fn (Successor $file) => $file
                                    ->attribute(
                                        name: 'file_name',
                                        definition: fn (Attribute $attribute) => $attribute->from('filename'),
                                    )
                                    ->attribute(
                                        name: 'mime_type',
                                        definition: fn (Attribute $attribute) => $attribute
                                            ->computedAs(read: fn (Collection $files): array => $files->mapWithKeys(fn (Model $file) => [$file->getKey() => $file->getAttribute('mime_type')])->all())
                                            ->writtenBy(WriteAssetFileMimeType::class),
                                    )
                                    ->attribute(
                                        name: 'url',
                                        definition: fn (Attribute $attribute) => $attribute->fixed(null)->readOnly(because: 'An AssetFile stores no url.', ignoreWrites: true),
                                    ))
                                ->as(target: AssetLink::class, successor: fn (Successor $link) => $link
                                    ->attribute(
                                        name: 'file_name',
                                        definition: fn (Attribute $attribute) => $attribute->fixed(null)->readOnly(because: 'An AssetLink stores no file_name.', ignoreWrites: true),
                                    )
                                    ->attribute(
                                        name: 'mime_type',
                                        definition: fn (Attribute $attribute) => $attribute->fixed(null)->readOnly(because: 'An AssetLink stores no mime_type.', ignoreWrites: true),
                                    )
                                    ->attribute(
                                        name: 'url',
                                        definition: fn (Attribute $attribute) => $attribute->from('url'),
                                    ))));
            }
        };
    }

    private function seattleListing(bool $withAttributes): Model
    {
        $listing = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            public static bool $withAttributes = true;

            protected $table = 'listings';

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('name'),
                        )
                        ->attribute(
                            name: 'company_id',
                            definition: fn (Attribute $attribute) => $attribute->from('company_id'),
                        )
                        ->related(
                            relation: self::$withAttributes ? fn (Job $job) => $job->hasOne(Place::class)->withAttributes(['city' => 'Seattle']) : fn (Job $job) => $job->hasOne(Place::class)->where('city', 'Seattle'),
                            successor: fn (Successor $place) => $place->attribute('street', fn (Attribute $attribute) => $attribute->from('street')),
                        ));
            }
        };

        $listing::$withAttributes = $withAttributes;

        return $listing;
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function backfiller(string $model): Backfiller
    {
        return new Backfiller(Compatibility::of($model)->resolve(), DB::connection());
    }

    /**
     * @return array{resource, array<int, resource>}
     */
    private function transaction(string $sql, int $seconds): array
    {
        $config = DB::connection()->getConfig();
        $dsn = 'pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.DB::connection()->getDatabaseName();
        $process = proc_open([PHP_BINARY, '-r', '$pdo = new PDO('.var_export($dsn, true).', '.var_export($config['username'], true).', '.var_export($config['password'], true).', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);'
            .' $pdo->beginTransaction(); $pdo->exec('.var_export($sql, true).'); echo "written\n"; fflush(STDOUT); sleep('.$seconds.'); $pdo->commit();'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if ($process === false) {
            $this->fail('The concurrent transaction did not start.');
        }

        return [$process, $pipes];
    }

    private function concurrently(string $sql): string
    {
        $config = DB::connection()->getConfig();
        $dsn = 'pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.DB::connection()->getDatabaseName();
        $process = proc_open([PHP_BINARY, '-r', '$pdo = new PDO('.var_export($dsn, true).', '.var_export($config['username'], true).', '.var_export($config['password'], true).', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);'
            .' try { foreach (explode(";", '.var_export($sql, true).') as $statement) { if (trim($statement) !== "") { $pdo->exec($statement); } } echo "unblocked"; } catch (PDOException $exception) { echo $exception->getMessage(); }'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if ($process === false) {
            $this->fail('The concurrent writer did not start.');
        }

        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        proc_close($process);

        return $output;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function successorRows(): array
    {
        return [
            ...$this->rows('select jobs.xmin::text as version, jobs.* from jobs join listings on listings.job_id = jobs.id order by listings.id'),
            ...$this->rows('select places.xmin::text as version, places.* from places join listings on listings.job_id = places.job_id order by listings.id'),
            ...$this->rows('select job_sites.xmin::text as version, job_sites.* from job_sites join listings on listings.job_id = job_sites.job_id order by listings.id'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function without(string $column, array $rows): array
    {
        return array_map(
            callback: fn (array $row) => array_diff_key($row, [$column => true]),
            array: $rows,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function sorted(array $row): array
    {
        ksort($row);

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $query): array
    {
        return array_map(fn (object $row) => (array) $row, DB::select($query));
    }
}
