<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Definition\Targets;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\Files\FakeMediaLibrary;
use Tests\Fixtures\Support\PlaceAssets\AssetFile;
use Tests\Fixtures\Support\PlaceAssets\AssetLink;
use Tests\Fixtures\Support\PlaceAssets\Image;
use Tests\Fixtures\Support\PlaceAssets\PlaceAsset;
use Tests\Fixtures\Support\PlaceAssets\PlaceAssetTables;
use Tests\Fixtures\Support\PlaceAssets\StoreAssetCaption;
use Tests\Fixtures\Support\PlaceAssets\Video;
use Tests\TestCase;

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
        $this->assertSame('Tour embed', DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_videos')->where(
                column: 'id',
                operator: '=',
                value: 1,
            )->value('place_asset_id'),
        )->value('title'));
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
        $asset = DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_videos')->where(
                column: 'id',
                operator: '=',
                value: 3,
            )->value('place_asset_id'),
        )->value('id');
        DB::table('place_assets')->where('id', $asset)->update(['asset_type' => 'retired_asset']);

        Video::query()->findOrFail(3)->update(['original_file_name' => 'drone-v2.mov']);

        $this->assertSame(['place_asset_file', 'drone-v2.mov', null], [DB::table('place_assets')->where('id', $asset)->value('asset_type'), Video::query()->findOrFail(3)->original_file_name, Video::query()->findOrFail(3)->size_kb]);
    }

    #[Test]
    public function it_keeps_the_old_target_of_a_moved_record_while_another_place_asset_still_names_it(): void
    {
        $file = DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_videos')->where(
                column: 'id',
                operator: '=',
                value: 1,
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

        $updated = Video::query()->findOrFail(1)->update(['youtube_embed_url' => 'https://youtu.be/tour']);

        $this->assertTrue($updated);
        $this->assertSame(['https://youtu.be/tour', 'tour.mp4'], [Video::query()->findOrFail(1)->youtube_embed_url, Video::query()->findOrFail(3)->original_file_name]);
        $this->assertSame([true, $file], [DB::table('asset_files')->where(
            column: 'id',
            operator: '=',
            value: $file,
        )->exists(), DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_videos')->where(
                column: 'id',
                operator: '=',
                value: 3,
            )->value('place_asset_id'),
        )->value('asset_id')]);
    }

    #[Test]
    public function it_refuses_a_create_whose_place_asset_listener_changes_the_morph_type_and_changes_nothing(): void
    {
        $before = $this->rows();
        PlaceAsset::saving(function (PlaceAsset $asset): void {
            $asset->asset_type = 'unknown_asset';
        });

        try {
            Image::query()->create(['title' => 'Side', 'file_name' => 'side.jpg']);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertStringContainsString('cannot run a successor listener that repoints a new record through its compatibility map', $refusal);
        $this->assertStringContainsString("PlaceAsset stored asset_type 'unknown_asset' and asset_id", $refusal);
        $this->assertSame($before, $this->rows());
        $this->assertSame([1, 2], Image::query()->orderBy('id')->pluck('id')->all());
    }

    #[Test]
    public function it_refuses_an_update_whose_place_asset_listener_replaces_the_morph_id_and_changes_nothing(): void
    {
        $before = $this->rows();
        PlaceAsset::saving(function (PlaceAsset $asset): void {
            $asset->asset_id = '00000000-0000-4000-8000-000000000000';
        });

        try {
            Video::query()->findOrFail(1)->update(['title' => 'Tour v2']);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertStringContainsString('cannot run a successor listener that repoints record 1 through its compatibility map', $refusal);
        $this->assertStringContainsString("asset_id '00000000-0000-4000-8000-000000000000' where the write gives 'place_asset_file'", $refusal);
        $this->assertSame($before, $this->rows());
        $this->assertSame('Tour', Video::query()->findOrFail(1)->title);
    }

    #[Test]
    public function it_refuses_a_move_whose_place_asset_listener_replaces_the_new_morph_id_and_changes_nothing(): void
    {
        $before = $this->rows();
        PlaceAsset::saving(function (PlaceAsset $asset): void {
            if ($asset->asset_type === 'place_asset_link') {
                $asset->asset_id = '00000000-0000-4000-8000-000000000000';
            }
        });

        try {
            Video::query()->findOrFail(1)->update(['youtube_embed_url' => 'https://youtu.be/tour']);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertStringContainsString('cannot run a successor listener that repoints record 1 through its compatibility map', $refusal);
        $this->assertSame($before, $this->rows());
    }

    #[Test]
    public function it_refuses_a_move_whose_old_target_listener_repoints_the_place_asset_and_changes_nothing(): void
    {
        $before = $this->rows();
        AssetFile::deleted(function (): void {
            DB::table('place_assets')->where(
                column: 'id',
                operator: '=',
                value: DB::table('place_videos')->where(
                    column: 'id',
                    operator: '=',
                    value: 1,
                )->value('place_asset_id'),
            )->update(['asset_type' => 'unknown_asset']);
        });

        try {
            Video::query()->findOrFail(1)->update(['youtube_embed_url' => 'https://youtu.be/tour']);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertStringContainsString("cannot run a successor listener that repoints record 1 through its compatibility map. Why: PlaceAsset stored asset_type 'unknown_asset'", $refusal);
        $this->assertStringContainsString("after the delete of the old AssetFile, where the move gives 'place_asset_link'", $refusal);
        $this->assertSame($before, $this->rows());
    }

    #[Test]
    public function it_runs_the_legacy_guards_of_a_move_against_the_values_the_new_target_fixes_for_the_attributes_the_write_omits(): void
    {
        $video = $this->guardedVideo();
        $before = $this->rows();

        try {
            $video->newQuery()->findOrFail(1)->update(['youtube_embed_url' => 'https://youtu.be/tour']);
            $refusal = 'no refusal';
        } catch (CompatibilityWriteRefused $exception) {
            $refusal = $exception->getMessage();
        }

        $this->assertStringStartsWith($video::class." record 1 cannot take this write, because guard 'sized' refuses it", $refusal);
        $this->assertSame($before, $this->rows());

        $video->newQuery()->findOrFail(2)->update(['youtube_embed_url' => null, 'original_file_name' => 'oak.mp4', 'size_kb' => 300]);

        $this->assertSame(['place_asset_file', 'oak.mp4', null], [DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_videos')->where(
                column: 'id',
                operator: '=',
                value: 2,
            )->value('place_asset_id'),
        )->value('asset_type'), $video->newQuery()->findOrFail(2)->getAttribute('original_file_name'), $video->newQuery()->findOrFail(2)->getAttribute('youtube_embed_url')]);
    }

    #[Test]
    public function it_hands_the_external_write_of_an_updated_or_moved_record_the_place_asset_fields_the_same_write_changes(): void
    {
        $video = $this->guardedVideo();
        $caption = fn (int $id) => FakeMediaLibrary::bound()->stored('caption-title-'.DB::table('place_assets')->where(
            column: 'id',
            operator: '=',
            value: DB::table('place_videos')->where(
                column: 'id',
                operator: '=',
                value: $id,
            )->value('place_asset_id'),
        )->value('id'))?->mimeType;

        $video->newQuery()->findOrFail(1)->update(['title' => 'Tour v2', 'caption' => 'Tour caption']);
        $video->newQuery()->findOrFail(2)->update(['youtube_embed_url' => null, 'original_file_name' => 'oak.mp4', 'title' => 'Oak upload', 'caption' => 'Oak caption']);

        $this->assertSame(['Tour v2', 'Oak upload'], [$caption(1), $caption(2)]);
        $this->assertSame(['Tour caption', 'Oak caption'], [$video->newQuery()->findOrFail(1)->getAttribute('caption'), $video->newQuery()->findOrFail(2)->getAttribute('caption')]);
        $this->assertSame([['Tour v2', 'place_asset_file'], ['Oak upload', 'place_asset_file']], array_map(
            callback: fn (int $id) => [DB::table('place_assets')->where(
                column: 'id',
                operator: '=',
                value: DB::table('place_videos')->where(
                    column: 'id',
                    operator: '=',
                    value: $id,
                )->value('place_asset_id'),
            )->value('title'), DB::table('place_assets')->where(
                column: 'id',
                operator: '=',
                value: DB::table('place_videos')->where(
                    column: 'id',
                    operator: '=',
                    value: $id,
                )->value('place_asset_id'),
            )->value('asset_type')],
            array: [1, 2],
        ));
    }

    #[Test]
    public function it_refuses_a_query_or_a_relation_for_an_external_attribute_of_an_updated_or_moved_record_and_changes_nothing(): void
    {
        $video = $this->guardedVideo();
        $before = $this->rows();
        $refusals = [];

        foreach ([
            fn () => $video->newQuery()->findOrFail(1)->update(['caption' => PlaceAsset::query()->select('title')->limit(1)]),
            fn () => $video->newQuery()->findOrFail(1)->update(['youtube_embed_url' => 'https://youtu.be/tour', 'caption' => PlaceAsset::query()->select('title')->limit(1)->toBase()]),
            fn () => $video->newQuery()->findOrFail(2)->update(['youtube_embed_url' => null, 'original_file_name' => 'oak.mp4', 'caption' => (new PlaceAsset)->hasOne(PlaceAsset::class, 'id', 'id')]),
        ] as $write) {
            try {
                $write();
                $refusals[] = 'stored';
            } catch (CompatibilityWriteRefused $refusal) {
                $refusals[] = $refusal->getMessage();
            }
        }

        $refusal = CompatibilityWriteRefused::rawExpression($video::class, 'caption', 'It is declared computedAs(), so its value is computed in PHP, and no SQL computes it.')->getMessage();

        $this->assertSame([$refusal, $refusal, $refusal], $refusals);
        $this->assertSame($before, $this->rows());
    }

    private function guardedVideo(): Model
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
                    ->guard('sized', fn (Model $after, Model $before) => $before->getAttribute('size_kb') !== null && $after->getAttribute('size_kb') === null, because: 'A sized upload keeps its size.', instead: 'Keep the upload.')
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
                        ->attribute(
                            name: 'caption',
                            definition: fn (Attribute $attribute) => $attribute->computedAs(read: fn (Collection $assets): array => $assets->mapWithKeys(fn (Model $asset) => [$asset->getKey() => FakeMediaLibrary::bound()->stored('caption-'.$asset->getKey())?->mimeType])->all())->writtenBy(StoreAssetCaption::class),
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
                                        definition: fn (Attribute $attribute) => $attribute
                                            ->from('size_bytes')
                                            ->dividedBy(1000),
                                    )
                                    ->attribute(
                                        name: 'youtube_embed_url',
                                        definition: fn (Attribute $attribute) => $attribute->fixed(null),
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
                                    ))));
            }
        };
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function rows(): array
    {
        $rows = [];

        foreach (['place_assets' => 'id', 'asset_files' => 'id', 'asset_links' => 'id', 'place_videos_retained' => 'place_asset_id', 'place_images_retained' => 'place_asset_id'] as $table => $key) {
            $rows[$table] = array_map(fn (object $row) => (array) $row, DB::table($table)->orderBy($key)->get()->all());
        }

        return $rows;
    }
}
