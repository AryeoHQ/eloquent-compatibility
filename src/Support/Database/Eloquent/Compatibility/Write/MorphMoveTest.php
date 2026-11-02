<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Tests\Fixtures\Support\PlaceAssets\AssetFile;
use Tests\Fixtures\Support\PlaceAssets\AssetLink;
use Tests\Fixtures\Support\PlaceAssets\PlaceAsset;
use Tests\Fixtures\Support\PlaceAssets\PlaceAssetTables;
use Tests\Fixtures\Support\PlaceAssets\Video;
use Tests\TestCase;

#[CoversClass(MorphMove::class)]
#[CoversClass(RecordWriter::class)]
final class MorphMoveTest extends TestCase
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
    public function it_creates_the_new_target_then_repoints_and_saves_the_place_asset_then_deletes_the_old_target(): void
    {
        $order = [];
        AssetLink::created(function (AssetLink $link) use (&$order): void {
            $order[] = ['created', 'link', $link->url];
        });
        PlaceAsset::saved(function (PlaceAsset $asset) use (&$order): void {
            $order[] = ['saved', 'place asset', $asset->asset_type];
        });
        AssetFile::deleted(function (AssetFile $file) use (&$order): void {
            $order[] = ['deleted', 'file', $file->filename];
        });

        Video::query()->findOrFail(1)->update(['youtube_embed_url' => 'https://youtu.be/tour', 'title' => 'Tour embed']);

        $this->assertSame([['created', 'link', 'https://youtu.be/tour'], ['saved', 'place asset', 'place_asset_link'], ['deleted', 'file', 'tour.mp4']], $order);
        $this->assertSame('Tour embed', DB::table('place_assets')->where('legacy_type', 'place_video')->where('legacy_id', 1)->value('title'));
    }

    #[Test]
    public function it_changes_nothing_when_a_listener_refuses_to_delete_the_old_target(): void
    {
        $before = $this->rows();
        AssetFile::deleting(fn () => false);

        try {
            Video::query()->findOrFail(1)->update(['youtube_embed_url' => 'https://youtu.be/tour']);
            $refusal = null;
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception;
        }

        $this->assertInstanceOf(CompatibilityWriteRefused::class, $refusal);
        $this->assertSame($before, $this->rows());
        $this->assertSame([null, 'tour.mp4'], [Video::query()->findOrFail(1)->youtube_embed_url, Video::query()->findOrFail(1)->original_file_name]);
    }

    #[Test]
    public function it_changes_nothing_when_a_listener_refuses_to_create_the_new_target(): void
    {
        $before = $this->rows();
        AssetLink::creating(fn () => false);

        try {
            Video::query()->findOrFail(1)->update(['youtube_embed_url' => 'https://youtu.be/tour']);
            $refusal = null;
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception;
        }

        $this->assertInstanceOf(CompatibilityWriteRefused::class, $refusal);
        $this->assertSame($before, $this->rows());
    }

    #[Test]
    public function it_moves_a_record_whose_place_asset_names_no_target_into_the_one_its_choice_picks(): void
    {
        $asset = DB::table('place_assets')->where('legacy_type', 'place_video')->where('legacy_id', 3)->value('id');
        DB::table('place_assets')->where('id', $asset)->update(['asset_type' => 'retired_asset']);

        Video::query()->findOrFail(3)->update(['original_file_name' => 'drone-v2.mov']);

        $this->assertSame(['place_asset_file', 'drone-v2.mov', null], [DB::table('place_assets')->where('id', $asset)->value('asset_type'), Video::query()->findOrFail(3)->original_file_name, Video::query()->findOrFail(3)->size_kb]);
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
