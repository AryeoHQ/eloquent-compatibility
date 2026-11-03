<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Tests\Fixtures\Support\PlaceAssets\AssetFile;
use Tests\Fixtures\Support\PlaceAssets\AssetLink;
use Tests\Fixtures\Support\PlaceAssets\Brochure;
use Tests\Fixtures\Support\PlaceAssets\Image;
use Tests\Fixtures\Support\PlaceAssets\InteractiveContent;
use Tests\Fixtures\Support\PlaceAssets\PlaceAsset;
use Tests\Fixtures\Support\PlaceAssets\PlaceAssetTables;
use Tests\Fixtures\Support\PlaceAssets\TrashableAssetFile;
use Tests\Fixtures\Support\PlaceAssets\Video;
use Tests\TestCase;

#[CoversClass(RecordWriter::class)]
#[CoversClass(MorphMove::class)]
#[CoversClass(ExternalAttributes::class)]
#[CoversClass(Backfiller::class)]
final class MorphTargetTest extends TestCase
{
    /** @var list<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        PlaceAssetTables::create();
        PlaceAssetTables::seed();
        PlaceAssetTables::install();
    }

    protected function tearDown(): void
    {
        AssetFile::flushEventListeners();
        AssetLink::flushEventListeners();
        PlaceAsset::flushEventListeners();
        PlaceAssetTables::drop();

        parent::tearDown();
    }

    #[Test]
    public function it_updates_the_place_asset_of_an_image_whose_file_is_gone_and_creates_no_file(): void
    {
        $asset = $this->asset('place_image', 1);
        DB::table('asset_files')->where('id', $asset['asset_id'])->delete();

        $updated = Image::query()->findOrFail(1)->update(['title' => 'New']);

        $this->assertTrue($updated);
        $this->assertSame(['New', null], [Image::query()->findOrFail(1)->title, Image::query()->findOrFail(1)->file_name]);
        $this->assertSame([$asset['asset_type'], $asset['asset_id'], 3], [$this->asset('place_image', 1)['asset_type'], $this->asset('place_image', 1)['asset_id'], DB::table('asset_files')->count()]);
    }

    #[Test]
    public function it_increments_a_retained_column_of_a_video_whose_link_is_gone_and_creates_no_link(): void
    {
        $asset = $this->asset('place_video', 2);
        DB::table('asset_links')->where('id', $asset['asset_id'])->delete();

        $incremented = Video::query()->findOrFail(2)->increment('views');

        $this->assertSame(1, $incremented);
        $this->assertSame([3, null, 'Oak walkthrough'], [Video::query()->findOrFail(2)->views, Video::query()->findOrFail(2)->youtube_embed_url, Video::query()->findOrFail(2)->title]);
        $this->assertSame([$asset, 1], [$this->asset('place_video', 2), DB::table('asset_links')->count()]);
    }

    #[Test]
    public function it_updates_a_video_whose_link_is_gone_through_its_place_asset_and_moves_nothing(): void
    {
        $asset = $this->asset('place_video', 2);
        DB::table('asset_links')->where('id', $asset['asset_id'])->delete();

        $updated = Video::query()->findOrFail(2)->update(['title' => 'Oak v2', 'views' => 4]);

        $this->assertTrue($updated);
        $this->assertSame(['Oak v2', 4, null, null], [Video::query()->findOrFail(2)->title, Video::query()->findOrFail(2)->views, Video::query()->findOrFail(2)->youtube_embed_url, Video::query()->findOrFail(2)->original_file_name]);
        $this->assertSame([$asset['asset_type'], $asset['asset_id'], 4, 1], [$this->asset('place_video', 2)['asset_type'], $this->asset('place_video', 2)['asset_id'], DB::table('asset_files')->count(), DB::table('asset_links')->count()]);
    }

    #[Test]
    public function it_updates_a_video_whose_place_asset_names_an_undeclared_alias_through_that_place_asset_alone(): void
    {
        DB::table('place_assets')->where('legacy_type', 'place_video')->where('legacy_id', 3)->update(['asset_type' => 'retired_asset']);
        $files = DB::table('asset_files')->count();

        $updated = Video::query()->findOrFail(3)->update(['title' => 'Drone']);
        $incremented = Video::query()->findOrFail(3)->increment('views', 2);

        $this->assertSame([true, 1], [$updated, $incremented]);
        $this->assertSame(['Drone', 2, null], [Video::query()->findOrFail(3)->title, Video::query()->findOrFail(3)->views, Video::query()->findOrFail(3)->original_file_name]);
        $this->assertSame(['retired_asset', $files], [$this->asset('place_video', 3)['asset_type'], DB::table('asset_files')->count()]);
    }

    #[Test]
    public function it_refuses_to_move_a_video_from_an_undeclared_alias_without_the_values_its_new_target_requires_and_changes_nothing(): void
    {
        DB::table('place_assets')->where('legacy_type', 'place_video')->where('legacy_id', 3)->update(['asset_type' => 'retired_asset']);
        $before = $this->rows();

        try {
            Video::query()->findOrFail(3)->update(['size_kb' => '1.000']);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertStringContainsString("cannot run a write that moves record 3, whose PlaceAsset stores asset_type 'retired_asset', into AssetFile without \$original_file_name", $refusal);
        $this->assertStringContainsString('asset_files.filename needs a value', $refusal);
        $this->assertSame($before, $this->rows());
    }

    #[Test]
    public function it_reads_and_writes_a_video_whose_place_asset_stores_no_morph_type(): void
    {
        DB::statement('alter table place_assets alter column asset_type drop not null');
        DB::table('place_assets')->where('legacy_type', 'place_video')->where('legacy_id', 3)->update(['asset_type' => null]);
        $files = DB::table('asset_files')->count();

        $read = Video::query()->findOrFail(3);
        $updated = $read->update(['title' => 'Drone']);
        $incremented = Video::query()->findOrFail(3)->increment('views');

        $this->assertSame([true, 1], [$updated, $incremented]);
        $this->assertSame([3, 'Drone', null, null, null, 1], [$read->id, Video::query()->findOrFail(3)->title, Video::query()->findOrFail(3)->original_file_name, Video::query()->findOrFail(3)->size_kb, Video::query()->findOrFail(3)->youtube_embed_url, Video::query()->findOrFail(3)->views]);
        $this->assertSame([null, $files], [$this->asset('place_video', 3)['asset_type'], DB::table('asset_files')->count()]);
    }

    #[Test]
    public function it_serves_and_writes_the_records_of_an_empty_target_table(): void
    {
        DB::table('asset_links')->delete();

        $videos = Video::query()->orderBy('id')->get()->map(fn (Video $video) => [$video->id, $video->youtube_embed_url])->all();
        $tours = InteractiveContent::query()->get()->map(fn (InteractiveContent $tour) => [$tour->title, $tour->url])->all();
        $titled = Video::query()->update(['title' => 'Every video']);
        $updated = InteractiveContent::query()->findOrFail(1)->update(['title' => 'Oak tour v2']);

        $this->assertSame([[[1, null], [2, null], [3, null]], [['Oak tour', null]]], [$videos, $tours]);
        $this->assertSame([3, true], [$titled, $updated]);
        $this->assertSame([['Every video', 'Every video', 'Every video'], 'Oak tour v2', 0], [Video::query()->orderBy('id')->pluck('title')->all(), InteractiveContent::query()->findOrFail(1)->title, DB::table('asset_links')->count()]);
    }

    #[Test]
    public function it_reads_an_external_attribute_of_the_place_asset_for_a_record_whose_alias_no_target_declares(): void
    {
        $this->installBrochures();
        DB::table('place_assets')->where('legacy_type', 'place_brochure')->where('legacy_id', 1)->update(['asset_type' => 'retired_asset']);

        $titles = Brochure::query()->orderBy('id')->get()->map(fn (Brochure $brochure) => [$brochure->id, $brochure->title, $brochure->file_name])->all();
        $brochure = Brochure::query()->findOrFail(1);
        $updated = $brochure->update(['title' => 'Guide v2']);

        $this->assertSame([[1, 'Guide', null], [2, 'Map', null]], $titles);
        $this->assertSame([true, 'Guide v2', 'Guide v2', 'Guide v2'], [$updated, $brochure->title, Brochure::query()->findOrFail(1)->title, DB::table('place_assets')->where('legacy_type', 'place_brochure')->where('legacy_id', 1)->value('title')]);
    }

    #[Test]
    public function it_serves_a_record_whose_target_is_trashed_and_writes_it_without_restoring_or_deleting_that_target(): void
    {
        $this->installBrochures();
        $trashed = (string) $this->asset('place_brochure', 1)['asset_id'];
        TrashableAssetFile::query()->findOrFail($trashed)->delete();

        $read = Brochure::query()->findOrFail(1);
        $updated = $read->update(['pages' => 13]);
        $pointer = $this->asset('place_brochure', 1);
        $renamed = Brochure::query()->findOrFail(1)->update(['file_name' => 'guide-v2.pdf']);
        $renaming = $this->asset('place_brochure', 1);

        $this->assertSame([1, 'Guide', null, true], [$read->id, $read->title, $read->file_name, $read->hosted]);
        $this->assertSame([true, $trashed, 13], [$updated, $pointer['asset_id'], Brochure::query()->findOrFail(1)->pages]);
        $this->assertTrue($renamed);
        $this->assertNotSame($trashed, $renaming['asset_id']);
        $this->assertSame(['guide-v2.pdf', 'guide-v2.pdf', true], [Brochure::query()->findOrFail(1)->file_name, DB::table('trashable_asset_files')->where('id', $renaming['asset_id'])->value('filename'), DB::table('trashable_asset_files')->where('id', $trashed)->whereNotNull('deleted_at')->exists()]);
    }

    #[Test]
    public function it_moves_a_record_whose_target_is_trashed_and_leaves_that_target_trashed(): void
    {
        $this->installBrochures();
        $trashed = (string) $this->asset('place_brochure', 1)['asset_id'];
        TrashableAssetFile::query()->findOrFail($trashed)->delete();

        $updated = Brochure::query()->findOrFail(1)->update(['hosted' => false, 'url' => 'https://guide.example/oak']);

        $moved = $this->asset('place_brochure', 1);
        $this->assertTrue($updated);
        $this->assertSame(['place_asset_link', 'https://guide.example/oak'], [$moved['asset_type'], DB::table('asset_links')->where('id', $moved['asset_id'])->value('url')]);
        $this->assertSame([null, 'https://guide.example/oak', false, 'Guide'], [Brochure::query()->findOrFail(1)->file_name, Brochure::query()->findOrFail(1)->url, Brochure::query()->findOrFail(1)->hosted, Brochure::query()->findOrFail(1)->title]);
        $this->assertTrue(DB::table('trashable_asset_files')->where('id', $trashed)->whereNotNull('deleted_at')->exists());
    }

    #[Test]
    public function it_backfills_a_record_whose_target_is_trashed_into_a_live_target_and_leaves_the_trashed_one(): void
    {
        PlaceAssetTables::brochures();
        Compatibility::prepare(Brochure::class);
        $backfiller = new Backfiller(Compatibility::of(Brochure::class)->resolve(), DB::connection());
        $backfiller->run();
        $trashed = (string) $this->asset('place_brochure', 1)['asset_id'];
        TrashableAssetFile::query()->findOrFail($trashed)->delete();

        $run = $backfiller->run();
        Compatibility::install(Brochure::class);

        $live = (string) $this->asset('place_brochure', 1)['asset_id'];
        $this->assertSame([2, 2], [$run['read'], $run['changed']]);
        $this->assertNotSame($trashed, $live);
        $this->assertSame(['guide.pdf', true], [DB::table('trashable_asset_files')->where('id', $live)->whereNull('deleted_at')->value('filename'), DB::table('trashable_asset_files')->where('id', $trashed)->whereNotNull('deleted_at')->exists()]);
        $this->assertSame([[1, 'Guide', 'guide.pdf', null], [2, 'Map', null, 'https://maps.example/oak']], Brochure::query()->orderBy('id')->get()->map(fn (Brochure $brochure) => [$brochure->id, $brochure->title, $brochure->file_name, $brochure->url])->all());
    }

    #[Test]
    public function it_chooses_the_target_of_a_new_record_from_the_default_of_a_retained_column_it_omits(): void
    {
        $this->installBrochures();

        $flyer = Brochure::query()->create(['title' => 'Flyer', 'file_name' => 'flyer.pdf']);

        $this->assertSame(['Flyer', 'flyer.pdf', null, true, 0], [$flyer->refresh()->title, $flyer->file_name, $flyer->url, $flyer->hosted, $flyer->pages]);
        $this->assertSame(TrashableAssetFile::class, $this->asset('place_brochure', $flyer->id)['asset_type']);
    }

    #[Test]
    public function it_chooses_the_target_of_a_new_record_from_a_retained_expression_it_evaluates_first(): void
    {
        $this->installBrochures();

        $site = Brochure::query()->create(['title' => 'Site', 'url' => 'https://site.example/oak', 'hosted' => DB::raw('1 = 0')]);

        $this->assertSame(['Site', null, 'https://site.example/oak', false], [$site->refresh()->title, $site->file_name, $site->url, $site->hosted]);
        $this->assertSame('place_asset_link', $this->asset('place_brochure', $site->id)['asset_type']);
    }

    private function installBrochures(): void
    {
        PlaceAssetTables::brochures();
        Compatibility::prepare(Brochure::class);
        (new Backfiller(Compatibility::of(Brochure::class)->resolve(), DB::connection()))->run();
        Compatibility::install(Brochure::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function asset(string $legacyType, int $id): array
    {
        return (array) DB::table('place_assets')->where('legacy_type', $legacyType)->where('legacy_id', $id)->first(['id', 'asset_type', 'asset_id', 'title']);
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function rows(): array
    {
        $rows = [];

        foreach (['place_assets' => 'id', 'asset_files' => 'id', 'asset_links' => 'id', 'place_videos_retained' => 'place_asset_id'] as $table => $key) {
            $rows[$table] = array_map(fn (object $row) => (array) $row, DB::table($table)->orderBy($key)->get()->all());
        }

        return $rows;
    }
}
