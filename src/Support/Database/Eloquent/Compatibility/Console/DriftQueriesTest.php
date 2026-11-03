<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Closure;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Write\Backfiller;
use Tests\Fixtures\Support\Files\FileTables;
use Tests\Fixtures\Support\Files\InteractiveContent;
use Tests\Fixtures\Support\Files\Photo;
use Tests\Fixtures\Support\Files\Video;
use Tests\Fixtures\Support\Listings\Listing;
use Tests\Fixtures\Support\Listings\ListingTables;
use Tests\Fixtures\Support\PlaceAssets\Image;
use Tests\Fixtures\Support\PlaceAssets\PlaceAssetTables;
use Tests\TestCase;

#[CoversClass(DriftQueries::class)]
#[CoversClass(DriftFinding::class)]
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
    #[TestWith([Listing::class, "insert into listings (title, company_id) values ('Ash Barn', '0b5a4c7e-1f2d-4c3b-9a8e-7d6c5b4a3f21')", 'listings#4', 'no jobs row holds its id, so install() refuses it'])]
    #[TestWith([Photo::class, "delete from files where legacy_type = 'photo' and legacy_id = 2", 'photos#2', "no files row whose legacy_type is 'photo' holds its id, so install() refuses it"])]
    #[TestWith([Video::class, "insert into videos (original_file_name, title) values ('porch.mp4', 'Porch')", 'videos#4', "no files or links row whose legacy_type is 'video' holds its id, so install() refuses it"])]
    #[TestWith([Image::class, "delete from place_assets where legacy_type = 'place_image' and legacy_id = 1", 'place_images#1', "no place_assets row whose legacy_type is 'place_image' holds its id, so install() refuses it"])]
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
    #[TestWith([Video::class, "update videos set youtube_embed_url = 'https://youtu.be/tour' where id = 1", 'videos#1', 'videos holds other values for youtube_embed_url than the view would serve from files'])]
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

        $this->assertSame([
            [Listing::class, 'no primary', 'listings#4', 'no jobs row holds its id, so install() refuses it'],
            [Listing::class, 'values differ', 'listings#1', 'listings holds other values for title than the view would serve from jobs'],
        ], $this->find(Listing::class));
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

        $this->assertSame([[$model, 'stale view', $view, 'the installed view differs from the view the map compiles; run Compatibility::recompile()']], $this->find($model));
    }

    #[Test]
    public function it_names_a_retained_row_whose_primary_a_raw_delete_removed(): void
    {
        $this->installed(Listing::class);
        $job = DB::scalar('select id from jobs where legacy_id = 2');
        DB::transaction(function (): void {
            DB::statement('set local session_replication_role = replica');
            DB::delete('delete from jobs where legacy_id = 2');
        });

        $this->assertSame([
            [Listing::class, 'retained without primary', "listings_retained#{$job}", 'no jobs row holds it, so the view serves none of its legacy-only values'],
        ], $this->find(Listing::class));
    }

    /**
     * @param  class-string<Photo|Image>  $model
     */
    #[Test]
    #[TestWith([Photo::class, 'files', "update files set legacy_type = null where legacy_type = 'photo' and legacy_id = 2", 'photos_retained', "its files row no longer has legacy_type 'photo', so the view serves none of its legacy-only values"])]
    #[TestWith([Image::class, 'place_assets', "update place_assets set legacy_type = null where legacy_type = 'place_image' and legacy_id = 2", 'place_images_retained', "its place_assets row no longer has legacy_type 'place_image', so the view serves none of its legacy-only values"])]
    public function it_names_a_retained_row_whose_primary_a_raw_write_reclassified_and_counts_the_row_it_left_unclassified(string $model, string $successor, string $drift, string $retained, string $detail): void
    {
        $this->installed($model);
        $primary = DB::scalar("select id from {$successor} where legacy_id = 2 and legacy_type = ?", [(new $model)->getMorphClass()]);
        DB::unprepared($drift);

        $this->assertSame([
            [$model, 'retained without primary', "{$retained}#{$primary}", $detail],
            [$model, 'unclassified rows', $successor, '1 row has no legacy_type, so no legacy model serves it'],
        ], $this->find($model));
    }

    #[Test]
    public function it_names_a_retained_row_of_a_two_primary_map_whose_legacy_id_no_successor_holds(): void
    {
        $this->installed(Video::class);
        DB::delete("delete from files where legacy_type = 'video' and legacy_id = 1");

        $this->assertSame([
            [Video::class, 'retained without primary', 'videos_retained#1', "no files or links row whose legacy_type is 'video' holds it, so the view serves none of its legacy-only values"],
        ], $this->find(Video::class));
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function it_names_a_legacy_id_both_successors_of_a_two_primary_map_hold(bool $install): void
    {
        $install ? $this->installed(Video::class) : $this->backfilled(Video::class);
        DB::insert("insert into links (legacy_type, legacy_id, url, created_at, updated_at) values ('video', 1, 'https://youtu.be/tour', now(), now())");

        $this->assertSame([
            ...($install ? [] : [[Video::class, 'values differ', 'videos#1', 'videos holds other values for uuid, original_file_name, size_kb, youtube_embed_url, created_at, updated_at than the view would serve from links']]),
            [Video::class, 'held twice', 'videos#1', 'files and links both hold its id, so the view serves the record twice'],
        ], $this->find(Video::class));
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function it_names_a_primary_whose_morph_type_names_no_declared_target_or_whose_target_row_is_missing(bool $install): void
    {
        $install ? $this->installed(Image::class) : $this->backfilled(Image::class);
        DB::update("update place_assets set asset_type = 'place_brochure' where legacy_type = 'place_image' and legacy_id = 1");
        DB::delete("delete from asset_files where id = (select asset_id from place_assets where legacy_type = 'place_image' and legacy_id = 2)");

        $this->assertSame([
            ...($install ? [] : [
                [Image::class, 'values differ', 'place_images#1', 'place_images holds other values for file_name than the view would serve from place_assets'],
                [Image::class, 'values differ', 'place_images#2', 'place_images holds other values for file_name than the view would serve from place_assets'],
            ]),
            [Image::class, 'no morph target', 'place_images#1', "its asset_type 'place_brochure' names no target relatedMorph() declares"],
            [Image::class, 'no morph target', 'place_images#2', 'its asset_files row does not exist'],
        ], $this->find(Image::class));
    }

    /**
     * @param  class-string<Photo|Video|Image>  $model
     * @param  list<array{string, string}>  $counted
     */
    #[Test]
    #[TestWith([Photo::class, "insert into files (filename, mime_type) values ('stray.jpg', 'image/jpeg'), ('stray.png', 'image/png')", [['files', '2 rows have no legacy_type, so no legacy model serves them']]])]
    #[TestWith([Video::class, "insert into files (filename, mime_type) values ('stray.mp4', 'video/mp4'); insert into links (url) values ('https://youtu.be/stray'), ('https://youtu.be/lost')", [['files', '1 row has no legacy_type, so no legacy model serves it'], ['links', '2 rows have no legacy_type, so no legacy model serves them']]])]
    #[TestWith([Image::class, "insert into place_assets (asset_type, asset_id) values ('place_asset_file', gen_random_uuid())", [['place_assets', '1 row has no legacy_type, so no legacy model serves it']]])]
    public function it_counts_the_successor_rows_no_legacy_model_claims_per_table(string $model, string $drift, array $counted): void
    {
        $this->installed($model);
        DB::unprepared($drift);

        $this->assertSame(array_map(fn (array $count) => [$model, 'unclassified rows', ...$count], $counted), $this->find($model));
    }

    #[Test]
    #[TestWith([false, "update videos set youtube_embed_url = 'https://vimeo.com/oak' where id = 2"])]
    #[TestWith([true, "update links set url = 'https://vimeo.com/oak' where legacy_type = 'video' and legacy_id = 2"])]
    public function it_names_each_record_a_guard_refuses_as_it_stands(bool $install, string $drift): void
    {
        $install ? $this->installed(Video::class) : $this->backfilled(Video::class);
        DB::unprepared($drift);

        $this->assertSame([
            ...($install ? [] : [[Video::class, 'values differ', 'videos#2', 'videos holds other values for youtube_embed_url than the view would serve from links']]),
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
     * @param  class-string<Listing|Photo|Video|Image>  $model
     */
    private function backfilled(string $model): void
    {
        $this->drop = match ($model) {
            Listing::class => ListingTables::drop(...),
            Photo::class, Video::class => FileTables::drop(...),
            Image::class => PlaceAssetTables::drop(...),
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
            Image::class => (function (): void {
                PlaceAssetTables::create();
                PlaceAssetTables::seed();
                PlaceAssetTables::prepare();
                PlaceAssetTables::backfill();
            })(),
        };
    }

    /**
     * @param  class-string<Listing|Photo|Video|Image>  $model
     */
    private function installed(string $model): void
    {
        $this->backfilled($model);
        Compatibility::install($model);
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
     * @param  class-string<Listing|Photo|Video|Image>  $model
     * @return list<array{string, string, string, string}>
     */
    private function find(string $model, int $limit = 100): array
    {
        return array_map(
            fn (DriftFinding $finding) => [$finding->model, $finding->check, $finding->record, $finding->detail],
            (new DriftQueries(DB::connection()))->find(Compatibility::of($model)->resolve(), $limit),
        );
    }
}
