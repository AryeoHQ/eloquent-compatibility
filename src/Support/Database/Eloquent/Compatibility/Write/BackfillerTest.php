<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
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
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\Listings\Place;
use Tests\TestCase;

#[CoversClass(Backfiller::class)]
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
    }

    protected function tearDown(): void
    {
        ListingTables::drop();
        FileTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_writes_successor_rows_that_the_installed_view_reads_back_as_the_seeded_rows(): void
    {
        $seeded = $this->rows('select * from listings order by id');

        $backfilled = $this->backfiller(Listing::class)->run();
        Compatibility::install(
            model: Listing::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame([3, 7], [$backfilled['read'], $backfilled['changed']]);
        $this->assertSame($this->without(
            column: 'job_id',
            rows: $seeded,
        ), $this->without(
            column: 'job_id',
            rows: $this->rows('select * from listings order by id'),
        ));
        $this->assertSame(0, DB::scalar('select count(*) from listings where job_id is null or job_id not in (select id from jobs)'));
    }

    #[Test]
    public function it_changes_no_successor_row_when_it_runs_again(): void
    {
        $this->backfiller(Listing::class)->run();
        $before = $this->successorRows();

        $again = $this->backfiller(Listing::class)->run();

        $this->assertSame([3, 0], [$again['read'], $again['changed']]);
        $this->assertSame($before, $this->successorRows());
    }

    #[Test]
    public function it_catches_up_only_the_legacy_rows_whose_updated_at_passed_the_watermark(): void
    {
        $watermark = $this->backfiller(Listing::class)->run()['watermark'];
        DB::update("update listings set title = 'Oak Manor', updated_at = localtimestamp(0) where id = 1");
        DB::update("update listings set title = 'Elm Lodge' where id = 3");

        $caughtUp = $this->backfiller(Listing::class)->run(since: $watermark);

        $this->assertSame([1, 1], [$caughtUp['read'], $caughtUp['changed']]);
        $this->assertSame(['Oak Manor', 'Pine Flat', 'Elm Cottage'], array_column(
            array: $this->rows('select jobs.name from jobs join listings on listings.job_id = jobs.id order by listings.id'),
            column_key: 'name',
        ));
        $this->assertSame($caughtUp['watermark'], $this->backfiller(Listing::class)->remembered());
    }

    #[Test]
    public function it_creates_a_dependent_once_a_mapped_value_is_set_and_updates_the_one_the_relation_reads(): void
    {
        $this->backfiller(Listing::class)->run();
        $place = DB::scalar('select places.id from places join listings on listings.job_id = places.job_id where listings.id = 1');
        DB::update('update places set updated_at = null');
        DB::update("update listings set street = '2 Pine Rd', latitude = 45.5 where id = 2");
        DB::update("update listings set street = '1 Oak Ave' where id = 1");

        $again = $this->backfiller(Listing::class)->run();

        $this->assertSame(3, $again['changed']);
        $this->assertSame(
            [['legacy_id' => 1, 'street' => '1 Oak Ave', 'parent_id' => null], ['legacy_id' => 2, 'street' => '2 Pine Rd', 'parent_id' => null], ['legacy_id' => 3, 'street' => '3 Elm St', 'parent_id' => null]],
            $this->rows('select listings.id as legacy_id, places.street, places.parent_id from places join listings on listings.job_id = places.job_id order by listings.id'),
        );
        $this->assertSame($place, DB::scalar('select places.id from places join listings on listings.job_id = places.job_id where listings.id = 1'));
        $this->assertSame([[1, true, true], [2, true, true], [3, true, false]], array_map(
            callback: fn (array $row) => [$row['legacy_id'], $row['created'], $row['updated']],
            array: $this->rows('select listings.id as legacy_id, places.created_at is not null as created, places.updated_at is not null as updated from places join listings on listings.job_id = places.job_id order by listings.id'),
        ));
        $this->assertSame([1, 2, 3], array_map(
            callback: intval(...),
            array: array_column(
                array: $this->rows('select listings.id as legacy_id from job_sites join listings on listings.job_id = job_sites.job_id order by listings.id'),
                column_key: 'legacy_id',
            ),
        ));
    }

    #[Test]
    public function it_fills_the_with_attributes_of_a_relation_into_each_dependent_it_creates_and_stamps_an_unmapped_timestamp(): void
    {
        $listing = $this->seattleListing(withAttributes: true);

        $this->backfiller($listing::class)->run();

        $this->assertSame([['legacy_id' => 1, 'street' => '1 Oak St', 'city' => 'Seattle'], ['legacy_id' => 3, 'street' => '3 Elm St', 'city' => 'Seattle']], $this->rows('select listings.id as legacy_id, places.street, places.city from places join listings on listings.job_id = places.job_id order by listings.id'));
        $this->assertSame(0, DB::scalar('select count(*) from jobs where created_at is null or updated_at is null'));
    }

    #[Test]
    public function it_refuses_a_dependent_its_relation_would_not_read_back_and_writes_nothing_of_that_chunk(): void
    {
        $listing = $this->seattleListing(withAttributes: false);

        try {
            $this->backfiller($listing::class)->run();
            $this->fail('The backfill did not refuse.');
        } catch (InvalidCompatibilityMap $exception) {
            $this->assertSame("Compatibility map 'listings' cannot backfill: listings rows id 1, 3 hold values for places, and the related() relation from jobs reads no places row the backfill can create for them; its parent row is missing, or its conditions need withAttributes().", $exception->getMessage());
        }

        $this->assertSame([0, 0], [DB::scalar('select count(*) from jobs'), DB::scalar('select count(*) from places')]);
    }

    #[Test]
    public function it_gives_each_successor_row_the_unique_id_its_model_generates_when_the_table_has_no_default(): void
    {
        foreach (['jobs', 'places', 'job_sites'] as $table) {
            DB::statement("alter table {$table} alter column id drop default");
        }

        $backfilled = $this->backfiller(Listing::class)->run();

        $this->assertSame(7, $backfilled['changed']);
        $this->assertSame(7, DB::scalar('select (select count(distinct id) from jobs) + (select count(distinct id) from places) + (select count(distinct id) from job_sites)'));
    }

    #[Test]
    public function it_starts_its_watermark_no_later_than_the_oldest_transaction_still_open_when_it_runs(): void
    {
        $config = DB::connection()->getConfig();
        $dsn = 'pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.DB::connection()->getDatabaseName();
        $process = proc_open([PHP_BINARY, '-r', '$pdo = new PDO('.var_export($dsn, true).', '.var_export($config['username'], true).', '.var_export($config['password'], true).');'
            .' $pdo->beginTransaction(); echo $pdo->query("select to_char(date_trunc(\'second\', now()) at time zone \'UTC\', \'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"\')")->fetchColumn(), "\n"; fflush(STDOUT); sleep(3); $pdo->commit();'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if ($process === false) {
            $this->fail('The concurrent transaction did not start.');
        }

        $opened = trim((string) fgets($pipes[1]));
        usleep(1200000);
        $watermark = $this->backfiller(Listing::class)->run()['watermark'];
        proc_close($process);

        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $opened);
        $this->assertLessThanOrEqual($opened, $watermark);
    }

    #[Test]
    public function it_records_each_legacy_key_it_writes_in_the_transaction_of_its_chunk(): void
    {
        try {
            $this->backfiller(Listing::class)->run(chunk: 2, chunked: function (array $keys): void {
                if ($keys === [3]) {
                    throw new LogicException('The second chunk fails.');
                }
            });
            $this->fail('The backfill did not fail.');
        } catch (LogicException $exception) {
            $this->assertSame('The second chunk fails.', $exception->getMessage());
        }

        $this->assertSame([1, 2], array_map(
            callback: intval(...),
            array: array_column(
                array: $this->rows('select legacy_id as id from listings_backfill order by legacy_id'),
                column_key: 'id',
            ),
        ));
        $this->assertSame([1, 2], array_map(
            callback: intval(...),
            array: array_column(
                array: $this->rows('select listings.id as legacy_id from jobs join listings on listings.job_id = jobs.id order by listings.id'),
                column_key: 'legacy_id',
            ),
        ));
    }

    #[Test]
    public function it_refuses_a_legacy_value_its_conversions_cannot_store_and_writes_nothing_of_that_chunk(): void
    {
        DB::update("update listings set status = 'Archived' where id = 3");

        try {
            $this->backfiller(Listing::class)->run();
            $this->fail('The backfill did not refuse.');
        } catch (InvalidCompatibilityMap $exception) {
            $this->assertSame("Compatibility map 'listings' cannot backfill: listings row id 3 holds a status that attribute 'status' cannot store: mapping() maps no stored value to 'Archived'..", $exception->getMessage());
        }

        $this->assertSame(0, DB::scalar('select count(*) from jobs'));
    }

    #[Test]
    public function it_takes_no_lock_wider_than_the_rows_of_the_chunk_it_writes(): void
    {
        $this->backfiller(Listing::class)->run();
        $seen = [];

        $this->backfiller(Listing::class)->run(chunk: 1, chunked: function (array $keys) use (&$seen): void {
            if ($keys !== [2]) {
                return;
            }

            $seen = [
                array_column($this->rows("select distinct mode from pg_locks where pid = pg_backend_pid() and locktype = 'relation' order by mode"), 'mode'),
                $this->concurrently("set lock_timeout = '1s'; update jobs set name = name where id = (select job_id from listings where id = 3); update jobs set name = name where id = (select job_id from listings where id = 2);"),
            ];
        });

        $this->assertSame([['AccessShareLock', 'RowExclusiveLock'], 'SQLSTATE[55P03]'], [$seen[0], substr((string) $seen[1], 0, 15)]);
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
    public function it_writes_an_external_value_through_the_successor_and_forwards_no_legacy_event(): void
    {
        FileTables::create();
        FileTables::seed();
        Compatibility::prepare(Photo::class);
        FakeMediaLibrary::bound()->calls = [];
        $forwarded = [];
        Event::listen('eloquent.*: '.Photo::class, function (string $event) use (&$forwarded): void {
            $forwarded[] = $event;
        });

        $backfilled = $this->backfiller(Photo::class)->run();
        $again = $this->backfiller(Photo::class)->run();
        DB::update("update photos set mime_type = 'image/webp' where id = 3");
        $changed = $this->backfiller(Photo::class)->run();

        $this->assertSame([3, 0, 1], [$backfilled['changed'], $again['changed'], $changed['changed']]);
        $this->assertSame([], $forwarded);
        $this->assertSame(['store', 'store', 'store', 'fetch', 'fetch', 'fetch', 'put'], array_column(FakeMediaLibrary::bound()->calls, 0));
        $this->assertSame('image/webp', FakeMediaLibrary::bound()->stored((string) File::query()->whereKey(FileTables::fileOf(table: 'photos', id: 3))->value('zml_asset_id'))?->mimeType);
    }

    #[Test]
    public function it_backfills_a_map_with_an_external_attribute_that_install_serves_and_rollback_restores(): void
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
    public function it_writes_a_json_column_postgresql_cannot_compare_for_equality_and_changes_nothing_when_it_runs_again(): void
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
        $again = $this->backfiller($listing::class)->run();

        $this->assertSame([3, 0], [$first['changed'], $again['changed']]);
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
        DB::update('update listings set is_published = true where id in (1, 2)');
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
        $unchanged = $this->backfiller($listing::class)->run();
        DB::update('update listings set is_published = false where id = 2');
        DB::update('update listings set is_published = true where id = 3');

        $changed = $this->backfiller($listing::class)->run();

        $this->assertSame([0, 2], [$unchanged['changed'], $changed['changed']]);
        $this->assertSame(
            [['legacy_id' => 1, 'published_at' => '2026-01-01 09:00:00'], ['legacy_id' => 2, 'published_at' => null], ['legacy_id' => 3, 'published_at' => 'set']],
            $this->rows("select listings.id as legacy_id, case when listings.id = 3 and jobs.published_at is not null then 'set' else cast(jobs.published_at as text) end as published_at from jobs join listings on listings.job_id = jobs.id order by listings.id"),
        );
    }

    #[Test]
    public function it_keeps_the_stored_value_that_already_serves_an_unchanged_is_not_null_attribute_of_a_successor_it_saves_record_by_record(): void
    {
        FileTables::create();
        FileTables::seed();
        DB::statement('alter table photos add column is_featured boolean');
        DB::statement('alter table files add column featured_at timestamp(0) without time zone');
        DB::update('update photos set is_featured = true where id in (1, 2)');
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
                            name: 'created_at',
                            definition: fn (Attribute $attribute) => $attribute->from('created_at'),
                        )
                        ->attribute(
                            name: 'updated_at',
                            definition: fn (Attribute $attribute) => $attribute->from('updated_at'),
                        )
                        ->attribute(
                            name: 'file_name',
                            definition: fn (Attribute $attribute) => $attribute->from('filename'),
                        )
                        ->attribute(
                            name: 'mime_type',
                            definition: fn (Attribute $attribute) => $attribute
                                ->computedAs(read: fn (Collection $files): array => File::metadataFor($files)->map(fn (FileMetadata $metadata) => $metadata->mimeType)->all())->writtenBy(WriteFileMimeType::class),
                        )
                        ->attribute(
                            name: 'is_featured',
                            definition: fn (Attribute $attribute) => $attribute->from('featured_at')->isNotNull(fn () => now()),
                        ));
            }
        };
        Compatibility::prepare($photo::class);
        $this->backfiller($photo::class)->run();
        DB::update("update files set featured_at = '2026-01-01 09:00:00' where id = (select file_id from photos where id = 1)");
        $unchanged = $this->backfiller($photo::class)->run();
        DB::update('update photos set is_featured = false where id = 2');

        $changed = $this->backfiller($photo::class)->run();

        $this->assertSame([0, 1], [$unchanged['changed'], $changed['changed']]);
        $this->assertSame(
            [['legacy_id' => 1, 'featured_at' => '2026-01-01 09:00:00'], ['legacy_id' => 2, 'featured_at' => null], ['legacy_id' => 3, 'featured_at' => null]],
            $this->rows('select photos.id as legacy_id, cast(files.featured_at as text) as featured_at from files join photos on photos.file_id = files.id order by photos.id'),
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
        $watermark = $this->backfiller(Listing::class)->run()['watermark'];
        $before = [DB::scalar('select job_id from listings where id = 1'), DB::scalar('select places.street from places join listings on listings.job_id = places.job_id where listings.id = 1')];
        DB::statement('alter table listing_photos drop constraint listing_photos_listing_id_foreign, add constraint listing_photos_listing_id_foreign foreign key (listing_id) references listings (id) on update cascade');
        DB::update('update listings set id = 99, updated_at = localtimestamp(0) where id = 1');

        $this->backfiller(Listing::class)->run(since: $watermark);
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
    public function it_keeps_a_mapped_updated_at_while_it_saves_another_changed_value_of_a_successor_whose_created_at_it_stamps(): void
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

        $changed = $this->backfiller($photo::class)->run();

        $this->assertSame(1, $changed['changed']);
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

    #[Test]
    public function it_refuses_a_watermark_while_pg_stat_activity_hides_the_transactions_of_other_sessions(): void
    {
        $role = 'compatibility_hidden_'.DB::connection()->getDatabaseName();
        DB::statement('create role '.$role);
        DB::statement('grant select on compatibility_backfills to '.$role);
        config(['database.connections.hidden' => config('database.connections.'.DB::getDefaultConnection())]);
        $hidden = DB::connection('hidden');
        $failure = null;

        try {
            $hidden->statement('set role '.$role);
            (new Backfiller(Compatibility::of(Listing::class)->resolve(), $hidden))->run();
        } catch (InvalidCompatibilityMap $exception) {
            $failure = $exception->getMessage();
        } finally {
            DB::disconnect('hidden');
            DB::statement('revoke select on compatibility_backfills from '.$role);
            DB::statement('drop role '.$role);
        }

        $this->assertIsString($failure);
        $this->assertStringStartsWith("Compatibility map 'listings' cannot backfill: pg_stat_activity hides from {$role} whether ", $failure);
        $this->assertStringEndsWith("Grant pg_read_all_stats to {$role}, then backfill again.", $failure);
        $this->assertSame(0, DB::scalar('select count(*) from jobs'));
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
