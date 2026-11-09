<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Builder;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Definition\Targets;
use Support\Database\Eloquent\Compatibility\Events\Forwarder;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;
use Support\Database\Eloquent\Compatibility\Write\Backfiller;
use Support\Database\Eloquent\Compatibility\Write\ExternalAttributes;
use Support\Database\Eloquent\Compatibility\Write\MassWriter;
use Support\Database\Eloquent\Compatibility\Write\RecordWriter;
use Tests\Fixtures\Support\Files\FakeMediaLibrary;
use Tests\Fixtures\Support\Files\FileMetadata;
use Tests\Fixtures\Support\PlaceAssets\AssetFile;
use Tests\Fixtures\Support\PlaceAssets\AssetLink;
use Tests\Fixtures\Support\PlaceAssets\Image;
use Tests\Fixtures\Support\PlaceAssets\InteractiveContent;
use Tests\Fixtures\Support\PlaceAssets\PlaceAsset;
use Tests\Fixtures\Support\PlaceAssets\PlaceAssetTables;
use Tests\Fixtures\Support\PlaceAssets\TrashablePlaceAsset;
use Tests\Fixtures\Support\PlaceAssets\Video;
use Tests\TestCase;

#[CoversClass(LegacyBuilder::class)]
#[CoversClass(ViewCompiler::class)]
#[CoversClass(RecordWriter::class)]
#[CoversClass(MassWriter::class)]
#[CoversClass(Backfiller::class)]
#[CoversClass(ExternalAttributes::class)]
#[CoversClass(Forwarder::class)]
final class PlaceAssetTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        PlaceAssetTables::create();
        PlaceAssetTables::seed();
    }

    protected function tearDown(): void
    {
        AssetFile::flushEventListeners();
        AssetLink::flushEventListeners();
        PlaceAsset::flushEventListeners();
        TrashablePlaceAsset::flushEventListeners();
        PlaceAssetTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_backfills_a_legacy_video_with_an_embed_url_into_one_asset_link_and_one_place_asset(): void
    {
        PlaceAssetTables::prepare();

        (new Backfiller(Compatibility::of(Video::class)->resolve(), DB::connection()))->run();

        $this->assertSame([[1, 'place_asset_file'], [2, 'place_asset_link'], [3, 'place_asset_file']], $this->assets('place_video'));
        $this->assertSame([['https://youtu.be/oak', 2]], array_map(
            callback: fn (object $row) => [(string) $row->url, (int) $row->legacy_id],
            array: DB::select("select asset_links.url, place_videos.id as legacy_id from asset_links join place_assets on place_assets.asset_type = 'place_asset_link' and place_assets.asset_id = asset_links.id join place_videos on place_videos.place_asset_id = place_assets.id"),
        ));
        $this->assertSame(2, DB::table('asset_files')->count());
        $this->assertSame(['Oak walkthrough', 'Tour', null], DB::table('place_assets')->whereIn(
            column: 'id',
            values: DB::table('place_videos')->select('place_asset_id'),
        )->orderBy('title')->pluck('title')->all());
    }

    #[Test]
    public function it_moves_a_backfilled_video_to_the_target_its_legacy_row_now_chooses_and_keeps_its_place_asset(): void
    {
        PlaceAssetTables::prepare();
        $backfiller = new Backfiller(Compatibility::of(Video::class)->resolve(), DB::connection());
        $backfiller->run();
        $asset = DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_videos')->where(
                column: 'id',
                operator: '=',
                value: 1,
            )->value('place_asset_id'),
        )->value('id');
        $file = DB::table('place_assets')->where('id', $asset)->value('asset_id');

        DB::table('place_videos')->where('id', 1)->update(['youtube_embed_url' => 'https://youtu.be/tour', 'original_file_name' => null, 'size_kb' => null]);
        $backfiller->run();

        $this->assertSame([[1, 'place_asset_link'], [2, 'place_asset_link'], [3, 'place_asset_file']], $this->assets('place_video'));
        $this->assertSame([$asset, 'https://youtu.be/tour'], [DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_videos')->where(
                column: 'id',
                operator: '=',
                value: 1,
            )->value('place_asset_id'),
        )->value('id'), DB::table('asset_links')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_assets')->where(
                column: 'id',
                operator: '=',
                value: $asset,
            )->value('asset_id'),
        )->value('url')]);
        $this->assertFalse(DB::table('asset_files')->where('id', $file)->exists());
    }

    #[Test]
    public function it_serves_each_legacy_model_through_its_place_asset_and_the_file_or_link_its_morph_names(): void
    {
        PlaceAssetTables::install();

        $this->assertSame(['v', 'v', 'v'], array_map(fn (string $table) => DB::scalar('select relkind::text from pg_class where oid = to_regclass(?)', [$table]), ['place_images', 'place_videos', 'place_interactive_content']));
        $this->assertSame(
            [[1, 'Tour', 'tour.mp4', '2048.000', null, 5], [2, 'Oak walkthrough', null, null, 'https://youtu.be/oak', 2], [3, null, 'drone.mov', '512.000', null, 0]],
            Video::query()->orderBy('id')->get()->map(fn (Video $video) => [$video->id, $video->title, $video->original_file_name, $video->size_kb, $video->youtube_embed_url, $video->views])->all(),
        );
        $this->assertSame([['front.jpg', 'image/jpeg'], ['back.png', 'image/png']], Image::query()->orderBy('id')->get()->map(fn (Image $image) => [$image->file_name, $image->mime_type])->all());
        $this->assertSame([['Oak tour', 'https://tour.example/oak']], InteractiveContent::query()->get()->map(fn (InteractiveContent $tour) => [$tour->title, $tour->url])->all());
        $this->assertSame([2, 3], Video::query()->where('youtube_embed_url', 'https://youtu.be/oak')->orWhere('original_file_name', 'drone.mov')->orderBy('id')->pluck('id')->all());
    }

    #[Test]
    public function it_reads_only_the_target_a_place_asset_names_when_a_file_and_a_link_share_one_key(): void
    {
        PlaceAssetTables::install();
        $file = (string) DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_videos')->where(
                column: 'id',
                operator: '=',
                value: 1,
            )->value('place_asset_id'),
        )->value('asset_id');
        $link = (string) DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_videos')->where(
                column: 'id',
                operator: '=',
                value: 2,
            )->value('place_asset_id'),
        )->value('asset_id');

        DB::transaction(function () use ($file, $link): void {
            DB::table('asset_links')->where('id', $link)->update(['id' => $file]);
            DB::table('place_assets')->where('asset_type', 'place_asset_link')->where('asset_id', $link)->update(['asset_id' => $file]);
        });

        $front = (string) DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_images')->where(
                column: 'id',
                operator: '=',
                value: 1,
            )->value('place_asset_id'),
        )->value('asset_id');
        DB::table('asset_links')->insert(['id' => $front, 'url' => 'https://tour.example/front', 'created_at' => '2026-03-07 10:00:00', 'updated_at' => '2026-03-07 10:00:00']);
        DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_images')->where(
                column: 'id',
                operator: '=',
                value: 2,
            )->value('place_asset_id'),
        )->update(['asset_type' => 'place_asset_link', 'asset_id' => $front]);

        $this->assertTrue(DB::table('asset_files')->where('id', $file)->exists());
        $this->assertSame([['front.jpg', 'image/jpeg'], [null, null]], Image::query()->orderBy('id')->get()->map(fn (Image $image) => [$image->file_name, $image->mime_type])->all());
        $this->assertSame(
            [[1, 'tour.mp4', '2048.000', null], [2, null, null, 'https://youtu.be/oak']],
            Video::query()->whereIn('id', [1, 2])->orderBy('id')->get()->map(fn (Video $video) => [$video->id, $video->original_file_name, $video->size_kb, $video->youtube_embed_url])->all(),
        );
    }

    #[Test]
    public function it_switches_a_video_from_upload_to_embed_and_keeps_its_place_asset_legacy_id_and_retained_row(): void
    {
        PlaceAssetTables::install();
        $asset = DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_videos')->where(
                column: 'id',
                operator: '=',
                value: 1,
            )->value('place_asset_id'),
        )->first();
        $video = Video::query()->findOrFail(1);

        $video->update(['youtube_embed_url' => 'https://youtu.be/tour']);

        $moved = DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_videos')->where(
                column: 'id',
                operator: '=',
                value: 1,
            )->value('place_asset_id'),
        )->first();
        $this->assertNotNull($asset);
        $this->assertNotNull($moved);
        $this->assertSame([$asset->id, 'place_asset_link'], [$moved->id, $moved->asset_type]);
        $this->assertSame('https://youtu.be/tour', DB::table('asset_links')->where('id', $moved->asset_id)->value('url'));
        $this->assertFalse(DB::table('asset_files')->where('id', $asset->asset_id)->exists());
        $this->assertSame([[5]], array_map(fn (object $row) => [(int) $row->views], DB::select('select views from place_videos_retained where place_asset_id = ?', [$asset->id])));
        $this->assertSame([1, 'Tour', null, null, 'https://youtu.be/tour', 5], [$video->id, $video->title, $video->original_file_name, $video->size_kb, $video->youtube_embed_url, $video->views]);
        $this->assertSame([null, null, 'https://youtu.be/tour', 5], [Video::query()->findOrFail(1)->original_file_name, Video::query()->findOrFail(1)->size_kb, Video::query()->findOrFail(1)->youtube_embed_url, Video::query()->findOrFail(1)->views]);
    }

    #[Test]
    public function it_switches_a_video_from_embed_to_upload_through_the_values_the_write_gives(): void
    {
        PlaceAssetTables::install();

        Video::query()->findOrFail(2)->update(['youtube_embed_url' => null, 'original_file_name' => 'oak.mp4', 'size_kb' => '64.000']);

        $this->assertSame([[1, 'place_asset_file'], [2, 'place_asset_file'], [3, 'place_asset_file']], $this->assets('place_video'));
        $this->assertSame(['Oak walkthrough', 'oak.mp4', '64.000', null], [Video::query()->findOrFail(2)->title, Video::query()->findOrFail(2)->original_file_name, Video::query()->findOrFail(2)->size_kb, Video::query()->findOrFail(2)->youtube_embed_url]);
        $this->assertSame(['https://plans.example/elm', 'https://tour.example/oak'], DB::table('asset_links')->orderBy('url')->pluck('url')->all());
    }

    #[Test]
    public function it_creates_the_target_before_the_place_asset_that_names_it(): void
    {
        PlaceAssetTables::install();
        $order = [];
        AssetLink::creating(function () use (&$order): void {
            $order[] = 'asset link';
        });
        PlaceAsset::creating(function (PlaceAsset $asset) use (&$order): void {
            $order[] = 'place asset '.$asset->asset_type;
        });

        $video = Video::query()->create(['title' => 'Garden', 'youtube_embed_url' => 'https://youtu.be/garden']);

        $this->assertSame(['asset link', 'place asset place_asset_link'], $order);
        $this->assertSame(['Garden', 'https://youtu.be/garden', null, 0], [$video->refresh()->title, $video->youtube_embed_url, $video->original_file_name, $video->views]);
        $this->assertGreaterThan(3, $video->id);
    }

    #[Test]
    public function it_updates_the_target_a_write_keeps_and_the_place_asset_in_place(): void
    {
        PlaceAssetTables::install();
        $assets = DB::table('place_assets')->orderBy('id')->pluck('asset_id', 'id')->all();

        Video::query()->findOrFail(1)->update(['original_file_name' => 'tour-v2.mp4', 'title' => 'Tour v2']);
        Video::query()->findOrFail(2)->update(['youtube_embed_url' => 'https://youtu.be/oak-v2']);

        $this->assertSame($assets, DB::table('place_assets')->orderBy('id')->pluck('asset_id', 'id')->all());
        $this->assertSame([['Tour v2', 'tour-v2.mp4', null], ['Oak walkthrough', null, 'https://youtu.be/oak-v2']], Video::query()->whereIn('id', [1, 2])->orderBy('id')->get()->map(fn (Video $video) => [$video->title, $video->original_file_name, $video->youtube_embed_url])->all());
    }

    #[Test]
    public function it_writes_a_mass_update_through_the_target_each_record_holds(): void
    {
        PlaceAssetTables::install();

        $updated = Video::query()->whereIn('id', [1, 3])->update(['original_file_name' => 'renamed.mp4', 'views' => 9]);
        $titled = Video::query()->update(['title' => 'Every video']);

        $this->assertSame([2, 3], [$updated, $titled]);
        $this->assertSame(
            [[1, 'Every video', 'renamed.mp4', null, 9], [2, 'Every video', null, 'https://youtu.be/oak', 2], [3, 'Every video', 'renamed.mp4', null, 9]],
            Video::query()->orderBy('id')->get()->map(fn (Video $video) => [$video->id, $video->title, $video->original_file_name, $video->youtube_embed_url, $video->views])->all(),
        );
        $this->assertSame(['Oak tour'], InteractiveContent::query()->pluck('title')->all());
    }

    #[Test]
    public function it_refuses_a_mass_write_that_changes_what_choose_picks_and_changes_nothing(): void
    {
        PlaceAssetTables::install();
        $before = DB::table('place_assets')->orderBy('id')->get()->all();

        try {
            Video::query()->whereIn('id', [1, 2])->update(['youtube_embed_url' => 'https://youtu.be/all']);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertStringContainsString('a mass write that changes what the relatedMorph() choose: closure picks', $refusal);
        $this->assertStringContainsString('It would move record 1 from AssetFile to AssetLink', $refusal);
        $this->assertEquals($before, DB::table('place_assets')->orderBy('id')->get()->all());
        $this->assertSame([null, 'https://youtu.be/oak'], [Video::query()->findOrFail(1)->youtube_embed_url, Video::query()->findOrFail(2)->youtube_embed_url]);
    }

    #[Test]
    public function it_refuses_a_mass_write_that_reaches_a_record_whose_place_asset_names_an_undeclared_alias_and_changes_nothing(): void
    {
        PlaceAssetTables::install();
        DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_videos')->where(
                column: 'id',
                operator: '=',
                value: 3,
            )->value('place_asset_id'),
        )->update(['asset_type' => 'retired_asset']);
        $before = DB::table('place_assets')->orderBy('id')->get()->all();

        try {
            Video::query()->update(['title' => 'All']);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertStringContainsString("cannot run a mass write that reaches record 3, whose PlaceAsset stores asset_type 'retired_asset' through its compatibility map", $refusal);
        $this->assertStringContainsString('targets: declares no target for it, so no target statement would write the record', $refusal);
        $this->assertEquals($before, DB::table('place_assets')->orderBy('id')->get()->all());
        $this->assertSame(['Tour', 'Oak walkthrough', null], Video::query()->orderBy('id')->pluck('title')->all());
    }

    #[Test]
    public function it_refuses_a_mass_write_that_reaches_a_record_whose_place_asset_stores_no_morph_type_and_changes_nothing(): void
    {
        PlaceAssetTables::install();
        DB::statement('alter table place_assets alter column asset_type drop not null');
        DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_videos')->where(
                column: 'id',
                operator: '=',
                value: 2,
            )->value('place_asset_id'),
        )->update(['asset_type' => null]);

        try {
            Video::query()->whereIn('id', [1, 2])->increment('views');
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertStringContainsString('cannot run a mass write that reaches record 2, whose PlaceAsset stores no asset_type through its compatibility map', $refusal);
        $this->assertSame([5, 2, 0], Video::query()->orderBy('id')->pluck('views')->all());
    }

    #[Test]
    public function it_locks_every_selected_place_asset_before_it_reads_which_target_holds_each_record(): void
    {
        PlaceAssetTables::install();
        config(['database.connections.second' => config('database.connections.'.DB::getDefaultConnection())]);
        $second = DB::connection('second');
        $link = (string) $second->scalar("insert into asset_links (url, created_at, updated_at) values ('https://youtu.be/race', now(), now()) returning id");
        $blocked = null;
        DB::listen(function (QueryExecuted $query) use (&$blocked, $second, $link): void {
            if ($blocked !== null || $query->connectionName !== DB::getDefaultConnection() || ! str_starts_with($query->sql, 'CREATE TEMPORARY TABLE') || ! str_contains($query->sql, 'compatibility_pinned')) {
                return;
            }

            try {
                $second->transaction(function () use ($second, $link): void {
                    $second->statement("SET LOCAL lock_timeout = '1s'");
                    $second->update(
                        query: "update place_assets set asset_type = 'place_asset_link', asset_id = ? where id = (select place_asset_id from place_videos where id = 1)",
                        bindings: [$link],
                    );
                });
                $blocked = false;
            } catch (QueryException) {
                $blocked = true;
            }
        });

        try {
            $updated = Video::query()->whereIn('id', [1, 3])->update(['title' => 'Raced']);
        } finally {
            DB::purge('second');
        }

        $this->assertSame([true, 2], [$blocked, $updated]);
        $this->assertSame(['Raced', 'Oak walkthrough', 'Raced'], Video::query()->orderBy('id')->pluck('title')->all());
        $this->assertSame([[1, 'place_asset_file'], [2, 'place_asset_link'], [3, 'place_asset_file']], $this->assets('place_video'));
    }

    #[Test]
    public function it_backfills_a_move_and_keeps_the_old_target_while_another_place_asset_still_names_it(): void
    {
        PlaceAssetTables::prepare();
        $backfiller = new Backfiller(Compatibility::of(Video::class)->resolve(), DB::connection());
        $backfiller->run();
        $file = DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_videos')->where(
                column: 'id',
                operator: '=',
                value: 1,
            )->value('place_asset_id'),
        )->value('asset_id');
        $drone = DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_videos')->where(
                column: 'id',
                operator: '=',
                value: 3,
            )->value('place_asset_id'),
        )->value('asset_id');
        DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_videos')->where(
                column: 'id',
                operator: '=',
                value: 3,
            )->value('place_asset_id'),
        )->update(['asset_id' => $file]);
        DB::table('asset_files')->where('id', $drone)->delete();

        DB::table('place_videos')->where('id', 1)->update(['youtube_embed_url' => 'https://youtu.be/tour', 'original_file_name' => null, 'size_kb' => null]);
        $backfiller->run();
        Compatibility::install(
            model: Video::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame([[1, 'place_asset_link'], [2, 'place_asset_link'], [3, 'place_asset_file']], $this->assets('place_video'));
        $this->assertSame([$file, 'drone.mov'], [DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_videos')->where(
                column: 'id',
                operator: '=',
                value: 3,
            )->value('place_asset_id'),
        )->value('asset_id'), DB::table('asset_files')->where(
            column: 'id',
            operator: '=',
            value: $file,
        )->value('filename')]);
        $this->assertSame([[1, null, 'https://youtu.be/tour'], [3, 'drone.mov', null]], Video::query()->whereIn('id', [1, 3])->orderBy('id')->get()->map(fn (Video $video) => [$video->id, $video->original_file_name, $video->youtube_embed_url])->all());
    }

    #[Test]
    public function it_resolves_an_external_attribute_of_a_morph_target_in_one_library_call_and_none_without_the_access(): void
    {
        PlaceAssetTables::install();
        $library = FakeMediaLibrary::bound();
        $calls = count($library->calls);

        Image::query()->take(20)->get(); // @phpstan-ignore staticMethod.dynamicCall
        $unread = array_slice($library->calls, $calls);
        $mimeType = Image::query()->take(20)->get()->first()?->mime_type; // @phpstan-ignore staticMethod.dynamicCall, larastan.noUnnecessaryCollectionCall
        $read = array_slice($library->calls, $calls);
        $images = Image::query()->orderBy('id')->take(20)->get(); // @phpstan-ignore staticMethod.dynamicCall
        $calls = count($library->calls);
        $held = [$images->first()?->mime_type, $images->last()?->mime_type];

        $this->assertSame([], $unread);
        $this->assertContains($mimeType, ['image/jpeg', 'image/png']);
        $this->assertSame(['fetch'], array_column($read, 0));
        $this->assertSame([['image/jpeg', 'image/png'], [['fetch', 2]]], [$held, array_map(fn (array $call) => [$call[0], count($call[1])], array_slice($library->calls, $calls))]);
    }

    #[Test]
    public function it_writes_an_external_attribute_of_a_morph_target_through_its_external_write(): void
    {
        PlaceAssetTables::install();
        $image = Image::query()->findOrFail(1);

        $image->update(['mime_type' => 'image/webp']);

        $asset = (string) DB::table('asset_files')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_assets')->where(
                column: 'id',
                operator: '=',
                value: DB::table('place_images')->where(
                    column: 'id',
                    operator: '=',
                    value: 1,
                )->value('place_asset_id'),
            )->value('asset_id'),
        )->value('zml_asset_id');
        $this->assertSame(['image/webp', 'image/webp', 'image/webp'], [$image->mime_type, Image::query()->findOrFail(1)->mime_type, FakeMediaLibrary::bound()->stored($asset)?->mimeType]);
    }

    #[Test]
    public function it_forwards_a_save_of_a_target_as_the_legacy_updated_event_of_the_record_its_place_asset_serves(): void
    {
        PlaceAssetTables::install();
        $fired = [];
        Event::listen('eloquent.updated: '.Video::class, function (Video $video) use (&$fired): void {
            $fired[] = [$video->id, $video->youtube_embed_url, $video->title];
        });

        AssetLink::query()->where('url', 'https://youtu.be/oak')->firstOrFail()->update(['url' => 'https://youtu.be/oak-v3']);

        $this->assertSame([[2, 'https://youtu.be/oak-v3', 'Oak walkthrough']], $fired);
    }

    #[Test]
    public function it_deletes_the_place_asset_of_a_deleted_legacy_record_and_leaves_its_target_as_related_leaves_a_row(): void
    {
        PlaceAssetTables::install();
        $file = DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_videos')->where(
                column: 'id',
                operator: '=',
                value: 3,
            )->value('place_asset_id'),
        )->value('asset_id');

        Video::query()->findOrFail(3)->delete();

        $this->assertSame([[1, 'place_asset_file'], [2, 'place_asset_link']], $this->assets('place_video'));
        $this->assertTrue(DB::table('asset_files')->where('id', $file)->exists());
        $this->assertNull(Video::query()->find(3));
    }

    #[Test]
    public function it_rolls_back_a_map_through_a_morph_into_the_rows_each_target_holds_and_installs_it_again(): void
    {
        PlaceAssetTables::install();
        $before = array_map(fn (object $row) => (array) $row, DB::select('select * from place_videos order by id'));

        Compatibility::rollback(Video::class);
        $restored = array_map(fn (object $row) => (array) $row, DB::select('select * from place_videos order by id'));
        Compatibility::prepare(Video::class);
        Compatibility::install(
            model: Video::class,
            statementTimeout: CarbonInterval::seconds(30),
        );

        $this->assertSame('r', DB::scalar("select relkind::text from pg_class where oid = to_regclass('place_videos_retained')"));
        $this->assertSame(array_map($this->sorted(...), $before), array_map($this->sorted(...), $restored));
        $this->assertSame(array_map($this->sorted(...), $before), array_map(fn (object $row) => $this->sorted((array) $row), DB::select('select * from place_videos order by id')));
    }

    #[Test]
    public function it_refuses_to_install_while_a_legacy_row_points_at_no_target_its_choice_picks(): void
    {
        PlaceAssetTables::prepare();
        PlaceAssetTables::backfill();
        DB::table('place_videos')->where('id', 2)->update(['youtube_embed_url' => null, 'updated_at' => '2026-01-01 00:00:00']);

        try {
            Compatibility::install(
                model: Video::class,
                statementTimeout: CarbonInterval::seconds(30),
            );
            $refusal = 'no refusal';
        } catch (InvalidCompatibilityMap $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertStringContainsString("Compatibility map 'place_videos' cannot install: 1 place_videos rows point at no target their relatedMorph() choose: closure picks; the first are id 2.", $refusal);
        $this->assertSame('r', DB::scalar("select relkind::text from pg_class where oid = to_regclass('place_videos')"));
    }

    #[Test]
    public function it_shows_the_listeners_of_a_soft_delete_the_external_value_of_a_morph_target_read_before_the_place_asset_deletes(): void
    {
        DB::statement('alter table place_assets add column deleted_at timestamp(0) with time zone');
        DB::statement('alter table place_images add column deleted_at timestamp(0) without time zone');
        $image = $this->trashableImage();
        Compatibility::prepare($image::class);
        (new Backfiller(Compatibility::of($image::class)->resolve(), DB::connection()))->run();
        Compatibility::install(
            model: $image::class,
            statementTimeout: CarbonInterval::seconds(30),
        );
        $asset = (string) DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_images')->where(
                column: 'id',
                operator: '=',
                value: 1,
            )->value('place_asset_id'),
        )->value('id');
        $stored = (string) DB::table('asset_files')->where('id', DB::table('place_assets')->where('id', $asset)->value('asset_id'))->value('zml_asset_id');
        TrashablePlaceAsset::deleting(function () use ($stored): void {
            FakeMediaLibrary::bound()->put($stored, new FileMetadata(null));
        });
        $seen = [];
        $image::deleted(function (Model $deleted) use (&$seen): void {
            $seen[] = $deleted->getAttribute('mime_type');
        });
        $record = $image->newQuery()->findOrFail(1);

        $record->delete();

        $this->assertSame([['image/jpeg'], 'image/jpeg', null], [$seen, $record->getAttribute('mime_type'), FakeMediaLibrary::bound()->stored($stored)?->mimeType]);
        $this->assertNotNull(DB::table('place_assets')->where('id', $asset)->value('deleted_at'));
    }

    #[Test]
    public function it_backfills_an_unchanged_is_not_null_attribute_of_a_morph_target_without_replacing_the_value_it_stores(): void
    {
        DB::statement('alter table place_videos add column is_featured boolean');
        DB::statement('alter table asset_files add column featured_at timestamp(0) without time zone');
        DB::update('update place_videos set is_featured = true where id in (1, 3)');
        $video = $this->featuredVideo();
        Compatibility::prepare($video::class);
        $backfiller = new Backfiller(Compatibility::of($video::class)->resolve(), DB::connection());
        $backfiller->run();
        $file = static fn (int $id): string => (string) DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_videos')->where(
                column: 'id',
                operator: '=',
                value: $id,
            )->value('place_asset_id'),
        )->value('asset_id');
        DB::update("update asset_files set featured_at = '2026-01-01 09:00:00' where id = ?", [$file(1)]);
        $unchanged = $backfiller->run();
        DB::update('update place_videos set is_featured = false where id = 3');

        $changed = $backfiller->run();

        $this->assertSame([0, 1], [$unchanged['changed'], $changed['changed']]);
        $this->assertSame(['2026-01-01 09:00:00', null], [DB::scalar('select cast(featured_at as text) from asset_files where id = ?', [$file(1)]), DB::table('asset_files')->where('id', $file(3))->value('featured_at')]);
    }

    #[Test]
    public function it_moves_a_record_without_copying_a_read_only_projection_of_the_target_the_write_omits_so_the_new_target_computes_it(): void
    {
        PlaceAssetTables::install();
        $video = $this->namedLinkVideo();
        DB::statement((new ViewCompiler(new ColumnCatalog(DB::connection()), DB::connection()))->replace(Compatibility::of($video::class)->resolve()));
        LegacyBuilder::forgetViews();
        $outcomes = [];

        foreach ([
            fn () => $video->newQuery()->findOrFail(1)->update(['youtube_embed_url' => 'https://youtu.be/tour']),
            fn () => $video->newQuery()->findOrFail(3)->update(['youtube_embed_url' => 'https://youtu.be/drone', 'original_file_name' => 'drone']),
            fn () => $video->newQuery()->findOrFail(2)->update(['original_file_name' => 'renamed']),
        ] as $write) {
            try {
                $write();
                $outcomes[] = 'stored';
            } catch (CompatibilityWriteRefused $refusal) {
                $outcomes[] = strstr($refusal->getMessage(), '. Why:', true);
            }
        }

        $this->assertSame(['stored', $video::class.' record 3 cannot change $original_file_name', $video::class.' record 2 cannot change $original_file_name'], $outcomes);
        $this->assertSame([[1, 'tour', 'https://youtu.be/tour'], [2, 'oak', 'https://youtu.be/oak'], [3, 'drone.mov', null]], $video->newQuery()->orderBy('id')->get()->map(fn (Model $served) => [$served->getKey(), $served->getAttribute('original_file_name'), $served->getAttribute('youtube_embed_url')])->all());
        $this->assertSame([[1, 'place_asset_link'], [2, 'place_asset_link'], [3, 'place_asset_file']], $this->assets('place_video'));
        $this->assertSame('https://youtu.be/tour', DB::table('asset_links')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_assets')->where(
                column: 'id',
                operator: '=',
                value: DB::table('place_videos')->where(
                    column: 'id',
                    operator: '=',
                    value: 1,
                )->value('place_asset_id'),
            )->value('asset_id'),
        )->value('url'));
    }

    private function trashableImage(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;
            use SoftDeletes;

            protected $table = 'place_images';

            protected $guarded = [];

            public function getMorphClass()
            {
                return 'place_image';
            }

            public static function compatibility(Compatibility $image): Compatibility
            {
                return $image
                    ->retainedIn('place_images_retained')
                    ->servedFrom(root: TrashablePlaceAsset::class, key: 'place_asset_id', successor: fn (Successor $asset) => $asset
                        ->attribute(
                            name: 'created_at',
                            definition: fn (Attribute $attribute) => $attribute->from('created_at'),
                        )
                        ->attribute(
                            name: 'updated_at',
                            definition: fn (Attribute $attribute) => $attribute->from('updated_at'),
                        )
                        ->attribute(
                            name: 'deleted_at',
                            definition: fn (Attribute $attribute) => $attribute->from('deleted_at'),
                        )
                        ->attribute(
                            name: 'title',
                            definition: fn (Attribute $attribute) => $attribute->from('title'),
                        )
                        ->relatedMorph(relation: fn (TrashablePlaceAsset $asset) => $asset->asset(),
                            choose: fn (Model $legacy): string => AssetFile::class,
                            targets: fn (Targets $targets) => $targets
                                ->as(target: AssetFile::class, successor: fn (Successor $file) => $file
                                    ->attribute(
                                        name: 'file_name',
                                        definition: fn (Attribute $attribute) => $attribute->from('filename'),
                                    )
                                    ->attribute(
                                        name: 'mime_type',
                                        definition: fn (Attribute $attribute) => $attribute->external(
                                            read: fn (Collection $files): array => AssetFile::metadataFor($files)->map(fn (FileMetadata $metadata) => $metadata->mimeType)->all(),
                                            write: fn (AssetFile $file, null|string $mimeType) => $file->metadata()->mimeType = $mimeType),
                                    ))));
            }
        };
    }

    private function featuredVideo(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'place_videos';

            protected $guarded = [];

            public function getMorphClass()
            {
                return 'place_video';
            }

            public static function compatibility(Compatibility $video): Compatibility
            {
                return $video
                    ->retainedIn('place_videos_retained')
                    ->servedFrom(root: PlaceAsset::class, key: 'place_asset_id', successor: fn (Successor $asset) => $asset
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
                            definition: fn (Attribute $attribute) => $attribute->from('title'),
                        )
                        ->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset(),
                            choose: fn (Model $video): string => $video->getAttribute('youtube_embed_url') !== null ? AssetLink::class : AssetFile::class,
                            targets: fn (Targets $targets) => $targets
                                ->as(target: AssetFile::class, successor: fn (Successor $file) => $file
                                    ->attribute(
                                        name: 'original_file_name',
                                        definition: fn (Attribute $attribute) => $attribute->from('filename'),
                                    )
                                    ->attribute(
                                        name: 'size_kb',
                                        definition: fn (Attribute $attribute) => $attribute->from('size_bytes')->dividedBy(1000),
                                    )
                                    ->attribute(
                                        name: 'youtube_embed_url',
                                        definition: fn (Attribute $attribute) => $attribute->fixed(null),
                                    )
                                    ->attribute(
                                        name: 'is_featured',
                                        definition: fn (Attribute $attribute) => $attribute->from('featured_at')->isNotNull(fn () => now()),
                                    ))
                                ->as(target: AssetLink::class, successor: fn (Successor $link) => $link
                                    ->attribute(
                                        name: 'original_file_name',
                                        definition: fn (Attribute $attribute) => $attribute->fixed(null),
                                    )
                                    ->attribute(
                                        name: 'size_kb',
                                        definition: fn (Attribute $attribute) => $attribute->fixed(null),
                                    )
                                    ->attribute(
                                        name: 'youtube_embed_url',
                                        definition: fn (Attribute $attribute) => $attribute->from('url'),
                                    )
                                    ->attribute(
                                        name: 'is_featured',
                                        definition: fn (Attribute $attribute) => $attribute->fixed(null),
                                    ))));
            }
        };
    }

    private function namedLinkVideo(): Model
    {
        return new class extends Model implements Compatible
        {
            use ServesCompatibility;

            protected $table = 'place_videos';

            protected $guarded = [];

            public function getMorphClass()
            {
                return 'place_video';
            }

            public static function compatibility(Compatibility $video): Compatibility
            {
                return $video
                    ->retainedIn('place_videos_retained')
                    ->servedFrom(root: PlaceAsset::class, key: 'place_asset_id', successor: fn (Successor $asset) => $asset
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
                            definition: fn (Attribute $attribute) => $attribute->from('title'),
                        )
                        ->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset(),
                            choose: fn (Model $video): string => $video->getAttribute('youtube_embed_url') !== null ? AssetLink::class : AssetFile::class,
                            targets: fn (Targets $targets) => $targets
                                ->as(target: AssetFile::class, successor: fn (Successor $file) => $file
                                    ->attribute(
                                        name: 'original_file_name',
                                        definition: fn (Attribute $attribute) => $attribute->from('filename'),
                                    )
                                    ->attribute(
                                        name: 'size_kb',
                                        definition: fn (Attribute $attribute) => $attribute->from('size_bytes')->dividedBy(1000),
                                    )
                                    ->attribute(
                                        name: 'youtube_embed_url',
                                        definition: fn (Attribute $attribute) => $attribute->fixed(null),
                                    ))
                                ->as(target: AssetLink::class, successor: fn (Successor $link) => $link
                                    ->attribute(
                                        name: 'original_file_name',
                                        definition: fn (Attribute $attribute) => $attribute->from('url')->readAs("split_part(\"asset_links\".\"url\", '/', 4)")->readOnly('A link takes its name from its URL.'),
                                    )
                                    ->attribute(
                                        name: 'size_kb',
                                        definition: fn (Attribute $attribute) => $attribute->fixed(null),
                                    )
                                    ->attribute(
                                        name: 'youtube_embed_url',
                                        definition: fn (Attribute $attribute) => $attribute->from('url'),
                                    ))));
            }
        };
    }

    /**
     * @return list<array{int, string}>
     */
    private function assets(string $legacyType): array
    {
        return array_map(
            callback: fn (object $row) => [(int) $row->legacy_id, (string) $row->asset_type],
            array: DB::select('select legacy.id as legacy_id, asset_type from place_assets join '.['place_video' => 'place_videos', 'place_brochure' => 'place_brochures', 'place_image' => 'place_images'][$legacyType].' as legacy on legacy.place_asset_id = place_assets.id order by legacy.id'),
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
}
