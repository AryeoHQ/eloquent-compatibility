<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Tests\Fixtures\Support\Listings\Job;
use Tests\Fixtures\Support\Listings\Place;
use Tests\Fixtures\Support\PlaceAssets\AssetFile;
use Tests\Fixtures\Support\PlaceAssets\AssetLink;
use Tests\Fixtures\Support\PlaceAssets\PlaceAsset;
use Tests\Fixtures\Support\PlaceAssets\ScopedAssetFile;
use Tests\Fixtures\Support\PlaceAssets\TrashableAssetFile;
use Tests\Fixtures\Support\PlaceAssets\Video;
use Tests\TestCase;

#[CoversClass(RelatedMorph::class)]
#[CoversClass(Related::class)]
final class RelatedMorphTest extends TestCase
{
    #[Test]
    public function it_compiles_a_morph_to_into_its_type_and_id_columns_and_one_target_for_each_successor(): void
    {
        $morph = (new Successor('place_videos', PlaceAsset::class))
            ->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(),
                choose: fn (Video $video): string => $video->youtube_embed_url !== null ? AssetLink::class : AssetFile::class,
                successors: fn (Successors $successors) => $successors
                    ->as(AssetFile::class, fn (Successor $file) => $file->attribute('original_file_name', fn (Attribute $attribute) => $attribute->from('filename')))
                    ->as(AssetLink::class, fn (Successor $link) => $link->attribute('youtube_embed_url', fn (Attribute $attribute) => $attribute->from('url'))))
            ->morph();

        $this->assertNotNull($morph);
        $this->assertSame([PlaceAsset::class, 'place_assets', 'asset_type', 'asset_id', true], [$morph->parent, $morph->parentTable, $morph->typeColumn, $morph->idColumn, $morph->choose !== null]);
        $this->assertSame([
            AssetFile::class => ['place_assets', 'asset_id', 'asset_files', 'id', true, 'asset_type', 'place_asset_file', null, null, ['original_file_name']],
            AssetLink::class => ['place_assets', 'asset_id', 'asset_links', 'id', true, 'asset_type', 'place_asset_link', null, null, ['youtube_embed_url']],
        ], array_map(fn (Related $target) => [$target->parentTable, $target->parentKey, $target->table, $target->relatedKey, $target->belongsTo, $target->morphType, $target->morphAlias, $target->conditions, $target->deletedAt, array_keys($target->successor->attributes())], $morph->targets));
    }

    #[Test]
    public function it_reads_only_the_live_rows_of_a_target_that_soft_deletes(): void
    {
        $morph = (new Successor('place_images', PlaceAsset::class))
            ->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(), successors: fn (Successors $successors) => $successors->as(TrashableAssetFile::class, fn (Successor $file) => $file))
            ->morph();

        $this->assertSame([null, 'deleted_at'], [$morph?->choose, $morph?->targets[TrashableAssetFile::class]->deletedAt]);
    }

    #[Test]
    public function it_refuses_a_morph_to_through_related_and_names_related_morph(): void
    {
        $this->expectExceptionObject(InvalidCompatibilityMap::because('place_videos', 'the related() relation from PlaceAsset through asset() is a MorphTo, whose related model varies per record, and related() reads a HasOne or a BelongsTo; declare it with relatedMorph()'));

        Related::compile('place_videos', PlaceAsset::class, fn (PlaceAsset $asset) => $asset->asset(), fn (Successor $asset) => $asset);
    }

    /**
     * @return iterable<string, array{Closure(): mixed, string}>
     */
    public static function invalidMorphs(): iterable
    {
        $asset = fn () => new Successor('place_videos', PlaceAsset::class);
        $relation = 'asset';

        yield 'a belongs to' => [fn () => (new Successor('place_videos', Place::class))->relatedMorph(fn (Place $place) => $place->job(), successors: fn (Successors $successors) => $successors->as(AssetFile::class, fn (Successor $file) => $file)), 'the relatedMorph() relation from Place to Job is a BelongsTo, and relatedMorph() reads a MorphTo; declare it with related()'];
        yield 'a has many' => [fn () => (new Successor('place_videos', Job::class))->relatedMorph(fn (Job $job) => $job->hasMany(Place::class), successors: fn (Successors $successors) => $successors->as(AssetFile::class, fn (Successor $file) => $file)), 'the relatedMorph() relation from Job to Place is a HasMany, and relatedMorph() reads a MorphTo only'];
        yield 'membership on a target' => [fn () => $asset()->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(), successors: fn (Successors $successors) => $successors->as(AssetFile::class, fn (Successor $file) => $file->when(fn (Builder $files) => $files->whereNotNull('zml_asset_id')))), 'when() on AssetFile, which relatedMorph() reaches, decides membership through a CHECK on place_assets, and a CHECK cannot read asset_files; declare when() on PlaceAsset, whose discriminator column keys membership'];
        yield 'at most one target row' => [fn () => $asset()->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(), successors: fn (Successors $successors) => $successors->as(AssetFile::class, fn (Successor $file) => $file->atMostOne('One file.'))), 'atMostOne() on AssetFile, which relatedMorph() reaches, refuses a second row, and a MorphTo reads one asset_files row by its key; drop atMostOne()'];
        yield 'a relation of a target' => [fn () => $asset()->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(), successors: fn (Successors $successors) => $successors->as(AssetFile::class, fn (Successor $file) => $file->related(fn (AssetFile $file) => $file->hasOne(AssetLink::class, 'id'), fn (Successor $link) => $link))), 'AssetFile, which relatedMorph() reaches, declares related() or relatedMorph() of its own, and a morph target serves the columns of its own table; declare the relation on PlaceAsset'];
        yield 'a transition on a target' => [fn () => $asset()->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(), successors: fn (Successors $successors) => $successors->as(AssetFile::class, fn (Successor $file) => $file->attribute('status', fn (Attribute $attribute) => $attribute->from('mime_type')->transitions()))), "attribute 'status' on AssetFile, which relatedMorph() reaches, is declared transitions(), and a change of target creates its row anew; declare transitions() on an attribute of PlaceAsset"];
        yield 'a scoped target' => [fn () => $asset()->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(), successors: fn (Successors $successors) => $successors->as(ScopedAssetFile::class, fn (Successor $file) => $file)), 'ScopedAssetFile, which relatedMorph() reaches, applies the global scope visible, which would read the application\'s state once and keep it; only SoftDeletes may apply'];
        yield 'a second morph' => [fn () => $asset()->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(), successors: fn (Successors $successors) => $successors->as(AssetFile::class, fn (Successor $file) => $file))->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(), successors: fn (Successors $successors) => $successors->as(AssetLink::class, fn (Successor $link) => $link)), 'relatedMorph() on PlaceAsset is declared twice; one legacy record reads one MorphTo of its successor'];
        yield 'a relation closure that captures' => [fn () => $asset()->relatedMorph(fn (PlaceAsset $asset) => $asset->morphTo($relation), successors: fn (Successors $successors) => $successors->as(AssetFile::class, fn (Successor $file) => $file)), 'a relatedMorph() relation closure on PlaceAsset captures $relation, and a map reads nothing but its own arguments'];
    }

    /**
     * @param  Closure(): mixed  $declare
     */
    #[DataProvider('invalidMorphs')]
    #[Test]
    public function it_refuses_a_morph_whose_targets_the_view_cannot_join_by_their_key(Closure $declare, string $problem): void
    {
        $this->expectExceptionObject(InvalidCompatibilityMap::because('place_videos', $problem));

        $declare();
    }
}
