<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\Files\FakeMediaLibrary;
use Tests\Fixtures\Support\Files\File;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\InteractiveContent;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Files\Video;
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
        Compatibility::install(Listing::class);

        $this->assertSame([3, 7], [$backfilled['read'], $backfilled['changed']]);
        $this->assertSame($seeded, $this->rows('select * from listings order by id'));
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

        $caughtUp = $this->backfiller(Listing::class)->run(since: $watermark['updatedAt']);

        $this->assertSame([1, 1], [$caughtUp['read'], $caughtUp['changed']]);
        $this->assertSame(['Oak Manor', 'Pine Flat', 'Elm Cottage'], array_column($this->rows('select name from jobs order by legacy_id'), 'name'));
        $this->assertSame($watermark['key'], $caughtUp['watermark']['key']);
        $this->assertSame($caughtUp['watermark'], $this->backfiller(Listing::class)->remembered());
    }

    #[Test]
    public function it_creates_a_dependent_once_a_mapped_value_is_set_and_updates_the_one_the_relation_reads(): void
    {
        $this->backfiller(Listing::class)->run();
        $place = DB::scalar('select places.id from places join jobs on jobs.id = places.job_id where jobs.legacy_id = 1');
        DB::update('update places set updated_at = null');
        DB::update("update listings set street = '2 Pine Rd', latitude = 45.5 where id = 2");
        DB::update("update listings set street = '1 Oak Ave' where id = 1");

        $again = $this->backfiller(Listing::class)->run();

        $this->assertSame(3, $again['changed']);
        $this->assertSame(
            [['legacy_id' => 1, 'street' => '1 Oak Ave', 'parent_id' => null], ['legacy_id' => 2, 'street' => '2 Pine Rd', 'parent_id' => null], ['legacy_id' => 3, 'street' => '3 Elm St', 'parent_id' => null]],
            $this->rows('select jobs.legacy_id, places.street, places.parent_id from places join jobs on jobs.id = places.job_id order by jobs.legacy_id'),
        );
        $this->assertSame($place, DB::scalar('select places.id from places join jobs on jobs.id = places.job_id where jobs.legacy_id = 1'));
        $this->assertSame([[1, true, true], [2, true, true], [3, true, false]], array_map(fn (array $row) => [$row['legacy_id'], $row['created'], $row['updated']], $this->rows('select jobs.legacy_id, places.created_at is not null as created, places.updated_at is not null as updated from places join jobs on jobs.id = places.job_id order by jobs.legacy_id')));
        $this->assertSame([1, 2, 3], array_map(intval(...), array_column($this->rows('select jobs.legacy_id from job_sites join jobs on jobs.id = job_sites.job_id order by jobs.legacy_id'), 'legacy_id')));
    }

    #[Test]
    public function it_fills_the_with_attributes_of_a_relation_into_each_dependent_it_creates_and_stamps_an_unmapped_timestamp(): void
    {
        $listing = $this->seattleListing(withAttributes: true);

        $this->backfiller($listing::class)->run();

        $this->assertSame([['legacy_id' => 1, 'street' => '1 Oak St', 'city' => 'Seattle'], ['legacy_id' => 3, 'street' => '3 Elm St', 'city' => 'Seattle']], $this->rows('select jobs.legacy_id, places.street, places.city from places join jobs on jobs.id = places.job_id order by jobs.legacy_id'));
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
        $watermark = $this->backfiller(Listing::class)->run()['watermark']['updatedAt'];
        proc_close($process);

        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $opened);
        $this->assertLessThanOrEqual($opened, $watermark);
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
                $this->concurrently("set lock_timeout = '1s'; update jobs set name = name where legacy_id = 3; update jobs set name = name where legacy_id = 2;"),
            ];
        });

        $this->assertSame([['AccessShareLock', 'RowExclusiveLock'], 'SQLSTATE[55P03]'], [$seen[0], substr((string) $seen[1], 0, 15)]);
    }

    #[Test]
    public function it_refuses_to_walk_a_legacy_table_that_install_already_replaced_with_the_view(): void
    {
        $this->backfiller(Listing::class)->run();
        Compatibility::install(Listing::class);

        $this->expectExceptionObject(InvalidCompatibilityMap::cannot('listings', 'backfill', 'listings is already installed as a view, and the backfill walks the legacy table while it is still a table'));

        $this->backfiller(Listing::class)->run();
    }

    #[Test]
    public function it_keeps_the_comment_of_the_legacy_table_beside_its_watermark_and_uninstall_restores_it(): void
    {
        DB::statement("comment on table listings is 'Listings shown on the site'");

        $this->backfiller(Listing::class)->run();
        $remembered = json_decode((string) DB::scalar("select obj_description('listings'::regclass, 'pg_class')"), true);
        Compatibility::install(Listing::class);
        Compatibility::uninstall(Listing::class);

        $this->assertSame(['backfilled', 'comment', 'key', 'updatedAt'], array_keys((array) $remembered));
        $this->assertSame(['compatibility backfill', 'Listings shown on the site', 3], [$remembered['backfilled'] ?? null, $remembered['comment'] ?? null, $remembered['key'] ?? null]);
        $this->assertSame('Listings shown on the site', DB::scalar("select obj_description('listings'::regclass, 'pg_class')"));
    }

    #[Test]
    public function it_writes_the_morph_alias_and_the_existing_legacy_id_of_each_model_on_a_shared_successor(): void
    {
        FileTables::create();
        FileTables::seed();
        FakeMediaLibrary::bound()->calls = [];

        foreach (FileTables::MODELS as $model) {
            Compatibility::prepare($model);
            $this->backfiller($model)->run();
        }

        foreach (FileTables::MODELS as $model) {
            Compatibility::install($model);
        }

        $this->assertSame(
            [['photo', 1, 'oak-front.jpg'], ['photo', 2, 'oak-back.jpg'], ['photo', 3, 'elm.png'], ['floorplan', 1, 'oak-plan.pdf'], ['floorplan', 2, 'elm-plan.png'], ['legacy_file', 1, 'contract.pdf'], ['legacy_file', 2, 'notes.txt']],
            array_map(fn (array $row) => [$row['legacy_type'], $row['legacy_id'], $row['filename']], $this->rows("select legacy_type, legacy_id, filename from files order by array_position(array['photo', 'floorplan', 'legacy_file'], legacy_type), legacy_id")),
        );
        $this->assertSame(['image/jpeg', 'image/jpeg', 'image/png'], Photo::query()->orderBy('id')->get()->map(fn (Photo $photo) => $photo->mime_type)->all());
        $this->assertSame(3, DB::scalar("select count(zml_asset_id) from files where legacy_type = 'photo'"));
        $this->assertSame(['512.000', '2048.000', '1.500'], array_column($this->rows('select size_kb from photos order by id'), 'size_kb'));
        $this->assertSame(['oak-plan.pdf', 'elm-plan.png'], array_column($this->rows('select file_name from floorplans order by id'), 'file_name'));
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
        $this->assertSame('image/webp', FakeMediaLibrary::bound()->stored((string) File::query()->where('legacy_type', 'photo')->where('legacy_id', 3)->value('zml_asset_id'))?->mimeType);
    }

    #[Test]
    public function it_routes_each_record_of_a_map_over_two_successors_by_its_selector_and_moves_one_whose_selector_changed(): void
    {
        FileTables::create();
        FileTables::seedMedia();
        Compatibility::prepare(Video::class);
        $watermark = $this->backfiller(Video::class)->run()['watermark'];
        $routed = [$this->rows("select legacy_id, filename from files where legacy_type = 'video' order by legacy_id"), $this->rows("select legacy_id, url from links where legacy_type = 'video' order by legacy_id")];
        DB::update("update videos set youtube_embed_url = null, original_file_name = 'oak.mp4', mime_type = 'video/mp4', updated_at = localtimestamp(0) where id = 2");

        $moved = $this->backfiller(Video::class)->run(since: $watermark['updatedAt']);

        $this->assertSame([[['legacy_id' => 1, 'filename' => 'tour.mp4'], ['legacy_id' => 3, 'filename' => 'drone.mov']], [['legacy_id' => 2, 'url' => 'https://youtu.be/oak']]], $routed);
        $this->assertSame([1, 2], [$moved['read'], $moved['changed']]);
        $this->assertSame([1, 2, 3], array_map(intval(...), array_column($this->rows("select legacy_id from files where legacy_type = 'video' order by legacy_id"), 'legacy_id')));
        $this->assertSame(0, DB::scalar("select count(*) from links where legacy_type = 'video'"));
    }

    #[Test]
    public function it_backfills_a_map_over_two_successors_that_install_serves_and_uninstall_restores(): void
    {
        FileTables::create();
        FileTables::seedMedia();
        $seeded = $this->rows('select * from videos order by id');
        Compatibility::prepare(Video::class);
        Compatibility::prepare(InteractiveContent::class);

        $this->backfiller(Video::class)->run();
        $this->backfiller(InteractiveContent::class)->run();
        Compatibility::install(Video::class);
        Compatibility::install(InteractiveContent::class);

        $this->assertSame(array_map(fn (array $row) => array_diff_key($row, ['mime_type' => true]), $seeded), $this->rows('select * from videos order by id'));
        $this->assertSame(['video/mp4', null, 'video/quicktime'], Video::query()->orderBy('id')->get()->map(fn (Video $video) => $video->mime_type)->all());
        $this->assertSame(['https://tour.example/oak', 'https://tour.example/elm'], array_column($this->rows('select url from interactive_content order by id'), 'url'));

        Compatibility::uninstall(Video::class);

        $this->assertSame(array_map($this->sorted(...), $seeded), array_map($this->sorted(...), $this->rows('select * from videos order by id')));
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
                    ->identifiedBy('id', fn (Identifier $identifier) => $identifier->integer()->from('legacy_id'))
                    ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier->uuid()->from('legacy_uuid'))
                    ->retainedIn('listings_retained', key: 'job_id')
                    ->servedFrom(Job::class, fn (Successor $job) => $job
                        ->attribute('title', fn (Attribute $attribute) => $attribute->from('name'))
                        ->attribute('company_id', fn (Attribute $attribute) => $attribute->from('company_id'))
                        ->related(
                            self::$withAttributes ? fn (Job $job) => $job->hasOne(Place::class)->withAttributes(['city' => 'Seattle']) : fn (Job $job) => $job->hasOne(Place::class)->where('city', 'Seattle'),
                            fn (Successor $place) => $place->attribute('street', fn (Attribute $attribute) => $attribute->from('street')),
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
            ...$this->rows('select xmin::text as version, * from jobs order by legacy_id'),
            ...$this->rows('select places.xmin::text as version, places.* from places join jobs on jobs.id = places.job_id order by jobs.legacy_id'),
            ...$this->rows('select job_sites.xmin::text as version, job_sites.* from job_sites join jobs on jobs.id = job_sites.job_id order by jobs.legacy_id'),
        ];
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
