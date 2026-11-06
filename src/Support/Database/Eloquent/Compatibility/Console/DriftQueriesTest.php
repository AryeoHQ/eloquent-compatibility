<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Support\Database\Eloquent\Compatibility\Write\Backfiller;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\InteractiveContent;
use Tests\Fixtures\Support\Files\LegacyFile;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Files\Video;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\PlaceAssets\Brochure;
use Tests\Fixtures\Support\PlaceAssets\Image;
use Tests\Fixtures\Support\PlaceAssets\PlaceAssetTables;
use Tests\Fixtures\Support\PlaceAssets\Video as PlaceVideo;
use Tests\TestCase;

#[CoversClass(DriftQueries::class)]
#[CoversClass(DriftFinding::class)]
#[CoversClass(DriftCheck::class)]
final class DriftQueriesTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    /** @var null|Closure(): void */
    private null|Closure $drop = null;

    protected function tearDown(): void
    {
        if ($this->drop !== null) {
            ($this->drop)();
        }

        parent::tearDown();
    }

    #[Test]
    #[TestWith([Listing::class])]
    #[TestWith([Photo::class])]
    #[TestWith([Video::class])]
    #[TestWith([Image::class])]
    public function it_finds_no_drift_in_a_backfilled_map_before_install(string $model): void
    {
        $this->backfilled($model);

        $this->assertSame([], $this->find($model));
    }

    #[Test]
    #[TestWith([Listing::class])]
    #[TestWith([Photo::class])]
    #[TestWith([Video::class])]
    #[TestWith([Image::class])]
    public function it_finds_no_drift_in_an_installed_map(string $model): void
    {
        $this->installed($model);

        $this->assertSame([], $this->find($model));
    }

    /**
     * @param  class-string<Listing|Photo|Video|Image>  $model
     */
    #[Test]
    #[TestWith([Listing::class, 'delete from jobs where id = (select job_id from listings where id = 2)', 'listings#2', 'its job_id names no jobs row, so install() refuses it'])]
    #[TestWith([Photo::class, 'delete from files where id = (select file_id from photos where id = 2)', 'photos#2', 'its file_id names no files row, so install() refuses it'])]
    #[TestWith([Video::class, 'delete from files where id = (select file_id from videos where id = 3)', 'videos#3', 'its file_id names no files row, so install() refuses it'])]
    #[TestWith([Image::class, 'delete from place_assets where id = (select place_asset_id from place_images where id = 1)', 'place_images#1', 'its place_asset_id names no place_assets row, so install() refuses it'])]
    public function it_names_each_legacy_row_no_primary_holds_before_install(string $model, string $drift, string $record, string $detail): void
    {
        $this->backfilled($model);
        DB::unprepared($drift);

        $this->assertSame([[$model, 'no primary', $record, $detail]], $this->find($model));
    }

    /**
     * @param  class-string<Listing|Photo|Video|Image>  $model
     */
    #[Test]
    #[TestWith([Listing::class, "update listings set title = 'Oak Home', price = 1300 where id = 1", 'listings#1', 'listings holds other values for title, price than the view would serve from jobs'])]
    #[TestWith([Photo::class, "update photos set file_name = 'oak-side.jpg' where id = 1", 'photos#1', 'photos holds other values for file_name than the view would serve from files'])]
    #[TestWith([Video::class, "update videos set original_file_name = 'porch.mp4' where id = 1", 'videos#1', 'videos holds other values for original_file_name than the view would serve from files'])]
    #[TestWith([Image::class, "update place_images set file_name = 'side.jpg', title = 'Side' where id = 1", 'place_images#1', 'place_images holds other values for title, file_name than the view would serve from place_assets'])]
    public function it_names_each_legacy_row_whose_values_differ_from_what_the_view_would_serve_before_install(string $model, string $drift, string $record, string $detail): void
    {
        $this->backfilled($model);
        DB::unprepared($drift);

        $this->assertSame([[$model, 'values differ', $record, $detail]], $this->find($model));
    }

    #[Test]
    public function it_compares_only_the_legacy_rows_a_catch_up_from_the_backfill_watermark_would_not_rewrite(): void
    {
        ListingTables::create();
        ListingTables::seed();
        $this->drop = ListingTables::drop(...);
        Compatibility::prepare(Listing::class);
        (new Backfiller(Compatibility::of(Listing::class)->resolve(), DB::connection()))->run();
        DB::unprepared("update listings set title = 'Oak Home' where id = 1");
        DB::unprepared("update listings set title = 'Pine Loft', updated_at = localtimestamp(0) where id = 2");
        DB::unprepared("insert into listings (title, company_id, updated_at) values ('Ash Barn', '0b5a4c7e-1f2d-4c3b-9a8e-7d6c5b4a3f21', '2026-01-01 00:00:00')");
        DB::unprepared('delete from jobs where id in (select job_id from listings where id in (2, 3))');

        $this->assertSame([
            [Listing::class, 'no primary', 'listings#3', 'its job_id names no jobs row, so install() refuses it'],
            [Listing::class, 'values differ', 'listings#1', 'listings holds other values for title than the view would serve from jobs'],
        ], $this->find(Listing::class));
    }

    #[Test]
    public function it_compares_every_legacy_row_when_the_legacy_table_has_no_updated_at_to_limit_a_catch_up(): void
    {
        ListingTables::create();
        ListingTables::seed();
        $this->drop = ListingTables::drop(...);
        DB::statement('alter table listings drop column updated_at');
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
                        ));
            }
        };
        Compatibility::prepare($listing::class);
        (new Backfiller(Compatibility::of($listing::class)->resolve(), DB::connection()))->run();
        DB::unprepared("update listings set title = 'Oak Home' where id = 1");
        DB::unprepared("insert into listings (title, company_id) values ('Ash Barn', '0b5a4c7e-1f2d-4c3b-9a8e-7d6c5b4a3f21')");

        $this->assertSame([
            [$listing::class, 'no primary', 'listings#4', 'its job_id names no jobs row, so install() refuses it'],
            [$listing::class, 'values differ', 'listings#1', 'listings holds other values for title than the view would serve from jobs'],
        ], $this->find($listing::class));
    }

    #[Test]
    public function it_compares_a_json_column_as_jsonb_and_another_column_without_equality_as_text(): void
    {
        ListingTables::create();
        ListingTables::seed();
        $this->drop = ListingTables::drop(...);
        DB::statement('alter table listings add column details json, add column location point');
        DB::statement('alter table jobs add column details jsonb, add column location point');
        $listing = new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'listings';

            protected $guarded = [];

            protected $casts = ['details' => 'array'];

            public static function compatibility(Compatibility $listing): Compatibility
            {
                return $listing
                    ->retainedIn('listings_retained')
                    ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                        ->attribute(
                            name: 'created_at',
                            definition: fn (Attribute $attribute) => $attribute->from('created_at'),
                        )
                        ->attribute(
                            name: 'updated_at',
                            definition: fn (Attribute $attribute) => $attribute->from('updated_at'),
                        )
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
                        )
                        ->attribute(
                            name: 'location',
                            definition: fn (Attribute $attribute) => $attribute->from('location'),
                        ));
            }
        };
        Compatibility::prepare($listing::class);

        foreach ([1 => ['b' => 1, 'a' => 2], 2 => ['rooms' => 3], 3 => ['c' => [1, 2]]] as $id => $details) {
            $record = $listing::query()->findOrFail($id);
            $record->timestamps = false;
            $record->forceFill(['details' => $details, 'location' => '(1,2)'])->save();
        }

        (new Backfiller(Compatibility::of($listing::class)->resolve(), DB::connection()))->run();
        DB::unprepared("update listings set location = point(3, 4) where id = 2; update listings set details = '{\"c\":[2,1]}' where id = 3");

        $this->assertSame([
            [$listing::class, 'values differ', 'listings#2', 'listings holds other values for location than the view would serve from jobs'],
            [$listing::class, 'values differ', 'listings#3', 'listings holds other values for details than the view would serve from jobs'],
        ], $this->find($listing::class));
    }

    #[Test]
    public function it_names_a_legacy_row_whose_external_attribute_differs_from_its_external_read_before_install(): void
    {
        $this->backfilled(Image::class);
        DB::update("update place_images set mime_type = 'image/gif' where id = 1");

        $this->assertSame([
            [Image::class, 'values differ', 'place_images#1', 'place_images holds other values for mime_type than its external() read returns'],
        ], $this->find(Image::class));
    }

    #[Test]
    public function it_reads_external_attributes_only_until_the_limit_of_records_is_reached(): void
    {
        $this->backfilled(Image::class);
        DB::update("update place_images set file_name = 'side.jpg' where id = 1");
        DB::update("update place_images set mime_type = 'image/gif'");

        $this->assertSame([
            [Image::class, 'values differ', 'place_images#1', 'place_images holds other values for file_name than the view would serve from place_assets'],
        ], $this->find(Image::class, 1));
        $this->assertSame([
            [Image::class, 'values differ', 'place_images#1', 'place_images holds other values for file_name than the view would serve from place_assets'],
            [Image::class, 'values differ', 'place_images#1', 'place_images holds other values for mime_type than its external() read returns'],
        ], $this->find(Image::class, 2));
    }

    /**
     * @param  class-string<Video|PlaceVideo>  $model
     */
    #[Test]
    #[TestWith([PlaceVideo::class, "update place_videos set youtube_embed_url = 'https://youtu.be/tour' where id = 1", 'place_videos#1', 'place_videos holds other values for youtube_embed_url than the view would serve from place_assets', 'its relatedMorph() choose: closure picks asset_links, and its place_assets row points at no asset_links row, so install() refuses it'])]
    public function it_names_a_legacy_row_that_sits_in_another_successor_than_its_selector_chooses_before_install(string $model, string $drift, string $record, string $differs, string $misplaced): void
    {
        $this->backfilled($model);
        DB::unprepared($drift);

        $this->assertSame([
            [$model, 'values differ', $record, $differs],
            [$model, 'misplaced', $record, $misplaced],
        ], $this->find($model));
    }

    #[Test]
    public function it_names_a_primary_whose_morph_type_is_null(): void
    {
        $this->installed(Image::class);
        DB::statement('alter table place_assets alter column asset_type drop not null');
        DB::update('update place_assets set asset_type = null where id = (select place_asset_id from place_images where id = 1)');

        $this->assertSame([
            [Image::class, 'no morph target', 'place_images#1', 'its asset_type is NULL, which names no target relatedMorph() declares'],
        ], $this->find(Image::class));
    }

    #[Test]
    public function it_names_a_primary_whose_morph_target_row_is_soft_deleted(): void
    {
        $this->installed(Brochure::class);
        DB::update('update trashable_asset_files set deleted_at = now() where id = (select asset_id from place_assets where id = (select place_asset_id from place_brochures where id = 1))');

        $this->assertSame([
            [Brochure::class, 'no morph target', 'place_brochures#1', 'its trashable_asset_files row does not exist or is deleted'],
        ], $this->find(Brochure::class));
    }

    /**
     * @param  class-string<Listing|Photo|Video|Image>  $model
     */
    #[Test]
    #[TestWith([Listing::class, 'listings'])]
    #[TestWith([Photo::class, 'photos'])]
    #[TestWith([Video::class, 'videos'])]
    #[TestWith([Image::class, 'place_images'])]
    public function it_names_an_installed_view_that_differs_from_the_view_its_map_compiles(string $model, string $view): void
    {
        $this->installed($model);
        $this->editView($view);

        $this->assertSame([[$model, 'stale view', $view, 'the installed view differs from the view the map compiles; run Compatibility::update()']], $this->find($model));
    }

    #[Test]
    public function it_names_a_retained_row_whose_primary_a_raw_delete_removed(): void
    {
        $this->installed(Listing::class);
        DB::transaction(function (): void {
            DB::statement('set local session_replication_role = replica');
            DB::delete('delete from jobs where id = (select job_id from listings_retained where id = 2)');
        });

        $this->assertSame([
            [Listing::class, 'retained without primary', 'listings_retained#2', 'its job_id names no jobs row, so the view serves none of it'],
        ], $this->find(Listing::class));
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function it_names_a_record_whose_root_no_longer_matches_the_when_of_its_map(bool $install): void
    {
        $install ? $this->installed(Image::class) : $this->backfilled(Image::class);
        DB::update("update place_assets set legacy_type = 'place_video' where id = (select place_asset_id from place_images where id = 2)");

        $this->assertSame([
            [Image::class, 'outside when()', 'place_images#2', 'its place_assets row no longer matches the when() of Image'],
        ], $this->find(Image::class));
    }

    #[Test]
    public function it_names_a_record_whose_root_also_serves_a_record_of_a_sibling_map(): void
    {
        $this->backfilled(Photo::class);
        DB::update('update legacy_files set file_id = (select file_id from photos where id = 1) where id = 1');

        $this->assertSame([
            [Photo::class, 'held twice', 'photos#1', 'its files row also serves a LegacyFile record, so one shared successor serves two legacy records'],
        ], $this->find(
            model: Photo::class,
            siblings: [LegacyFile::class],
        ));
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function it_names_a_primary_whose_morph_type_names_no_declared_target_or_whose_target_row_is_missing(bool $install): void
    {
        $install ? $this->installed(Image::class) : $this->backfilled(Image::class);
        DB::update("update place_assets set asset_type = 'place_brochure' where id = (select place_asset_id from place_images where id = 1)");
        DB::delete('delete from asset_files where id = (select asset_id from place_assets where id = (select place_asset_id from place_images where id = 2))');

        $this->assertSame([
            ...($install ? [] : [
                [Image::class, 'values differ', 'place_images#1', 'place_images holds other values for file_name than the view would serve from place_assets'],
                [Image::class, 'values differ', 'place_images#2', 'place_images holds other values for file_name than the view would serve from place_assets'],
                [Image::class, 'values differ', 'place_images#1', 'place_images holds other values for mime_type than its external() read returns'],
                [Image::class, 'values differ', 'place_images#2', 'place_images holds other values for mime_type than its external() read returns'],
            ]),
            [Image::class, 'no morph target', 'place_images#1', "its asset_type 'place_brochure' names no target relatedMorph() declares"],
            [Image::class, 'no morph target', 'place_images#2', 'its asset_files row does not exist'],
        ], $this->find(Image::class));
    }

    #[Test]
    #[TestWith([false, "update videos set youtube_embed_url = 'https://vimeo.com/oak' where id = 2"])]
    #[TestWith([true, "update files set embed_url = 'https://vimeo.com/oak' where id = (select file_id from videos where id = 2)"])]
    public function it_names_each_record_a_guard_refuses_as_it_stands(bool $install, string $drift): void
    {
        $install ? $this->installed(Video::class) : $this->backfilled(Video::class);
        DB::unprepared($drift);

        $this->assertSame([
            ...($install ? [] : [[Video::class, 'values differ', 'videos#2', 'videos holds other values for youtube_embed_url than the view would serve from files']]),
            [Video::class, 'guard refuses', 'videos#2', "guard 'embed host' refuses the record as it stands: The listing page plays embeds from YouTube only."],
        ], $this->find(Video::class));
    }

    #[Test]
    public function it_reports_at_most_the_limit_of_records_per_check(): void
    {
        $this->backfilled(Listing::class);
        DB::unprepared("insert into listings (title, company_id) values ('Ash Barn', '0b5a4c7e-1f2d-4c3b-9a8e-7d6c5b4a3f21'), ('Birch Loft', '0b5a4c7e-1f2d-4c3b-9a8e-7d6c5b4a3f21'), ('Cedar Hall', '0b5a4c7e-1f2d-4c3b-9a8e-7d6c5b4a3f21')");
        DB::unprepared("update listings set title = concat(title, ' Renamed') where id <= 3");

        $this->assertSame([
            ['no primary', 'listings#4'],
            ['no primary', 'listings#5'],
            ['values differ', 'listings#1'],
            ['values differ', 'listings#2'],
        ], array_map(fn (array $finding) => [$finding[1], $finding[2]], $this->find(Listing::class, 2)));
    }

    #[Test]
    public function it_only_reads_and_leaves_no_compiled_view_behind(): void
    {
        $this->installed(Listing::class);
        $this->editView('listings');
        $before = $this->snapshot();

        $this->find(Listing::class);

        $this->assertSame($before, $this->snapshot());
        $this->assertNull(DB::scalar("select to_regclass('pg_temp.compatibility_compiled')"));
    }

    #[Test]
    public function it_refuses_a_map_whose_legacy_table_does_not_exist(): void
    {
        $this->expectExceptionObject(InvalidCompatibilityMap::cannot('listings', 'check', 'listings is neither a table nor a view'));

        $this->find(Listing::class);
    }

    /**
     * @param  class-string<Listing|Photo|Video|Image|PlaceVideo|Brochure>  $model
     */
    private function backfilled(string $model): void
    {
        $this->drop = match ($model) {
            Listing::class => ListingTables::drop(...),
            Photo::class, Video::class => FileTables::drop(...),
            Image::class, PlaceVideo::class, Brochure::class => PlaceAssetTables::drop(...),
        };

        match ($model) {
            Listing::class => (function (): void {
                ListingTables::create();
                ListingTables::seed();
                Compatibility::prepare(Listing::class);
                ListingTables::backfill();
            })(),
            Photo::class => (function (): void {
                FileTables::create();
                FileTables::seed();

                foreach (FileTables::MODELS as $shared) {
                    Compatibility::prepare($shared);
                }

                FileTables::backfill();
            })(),
            Video::class => (function (): void {
                FileTables::create();
                FileTables::seedMedia();
                Compatibility::prepare(Video::class);
                Compatibility::prepare(InteractiveContent::class);
                FileTables::backfillMedia();
            })(),
            Image::class, PlaceVideo::class => (function (): void {
                PlaceAssetTables::create();
                PlaceAssetTables::seed();
                PlaceAssetTables::prepare();
                PlaceAssetTables::backfill();
            })(),
            Brochure::class => (function (): void {
                PlaceAssetTables::create();
                PlaceAssetTables::brochures();
                Compatibility::prepare(Brochure::class);
                (new Backfiller(Compatibility::of(Brochure::class)->resolve(), DB::connection()))->run();
                PlaceAssetTables::classify(Brochure::class);
            })(),
        };
    }

    /**
     * @param  class-string<Listing|Photo|Video|Image|PlaceVideo|Brochure>  $model
     */
    private function installed(string $model): void
    {
        $this->backfilled($model);
        Compatibility::install(
            model: $model,
            statementTimeout: CarbonInterval::seconds(30),
        );
    }

    /**
     * @return list<mixed>
     */
    private function snapshot(): array
    {
        return [
            DB::scalar("select pg_get_viewdef('listings'::regclass)"),
            ...array_map(fn (string $table) => array_map(fn (object $row) => (array) $row, DB::select("select * from {$table} order by id")), ['jobs', 'places', 'job_sites']),
            array_map(fn (object $row) => (array) $row, DB::select('select * from listings_retained order by job_id')),
        ];
    }

    private function editView(string $view): void
    {
        $definition = rtrim(trim((string) DB::scalar('select pg_get_viewdef(cast(? as regclass))', [$view])), ';');
        DB::unprepared("create or replace view {$view} as select * from ({$definition}) as edited");
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<class-string<Model>>  $siblings
     * @return list<array{string, string, string, string}>
     */
    private function find(string $model, int $limit = 100, array $siblings = []): array
    {
        return array_map(
            fn (DriftFinding $finding) => [$finding->model, $finding->check->value, $finding->record, $finding->detail],
            (new DriftQueries(DB::connection()))->find(map: Compatibility::of($model)->resolve(), limit: $limit, siblings: array_map(
                callback: fn (string $sibling) => Compatibility::of($sibling)->resolve(),
                array: $siblings,
            )),
        );
    }
}
