<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
            ->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset(),
                choose: fn (Video $video): string => $video->youtube_embed_url !== null ? AssetLink::class : AssetFile::class,
                targets: fn (Targets $targets) => $targets
                    ->as(target: AssetFile::class, successor: fn (Successor $file) => $file->attribute(
                        name: 'original_file_name',
                        definition: fn (Attribute $attribute) => $attribute->from('filename'),
                    ))
                    ->as(target: AssetLink::class, successor: fn (Successor $link) => $link->attribute(
                        name: 'youtube_embed_url',
                        definition: fn (Attribute $attribute) => $attribute->from('url'),
                    )))
            ->morph();

        $this->assertNotNull($morph);
        $this->assertSame([PlaceAsset::class, 'place_assets', 'asset_type', 'asset_id'], [$morph->parent, $morph->parentTable, $morph->typeColumn, $morph->idColumn]);
        $this->assertSame([
            AssetFile::class => ['place_assets', 'asset_id', 'asset_files', 'id', true, 'asset_type', 'place_asset_file', null, null, ['original_file_name']],
            AssetLink::class => ['place_assets', 'asset_id', 'asset_links', 'id', true, 'asset_type', 'place_asset_link', null, null, ['youtube_embed_url']],
        ], array_map(fn (Related $target) => [$target->parentTable, $target->parentKey, $target->table, $target->relatedKey, $target->belongsTo, $target->morphType, $target->morphAlias, $target->conditions, $target->deletedAt, array_keys($target->successor->attributes())], $morph->targets));
    }

    #[Test]
    public function it_reads_only_the_live_rows_of_a_target_that_soft_deletes(): void
    {
        $morph = (new Successor('place_images', PlaceAsset::class))
            ->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset(), choose: fn (Model $legacy): string => TrashableAssetFile::class,
                targets: fn (Targets $targets) => $targets->as(target: TrashableAssetFile::class, successor: fn (Successor $file) => $file))
            ->morph();

        $this->assertSame([TrashableAssetFile::class, 'deleted_at'], [($morph?->choose)(new PlaceAsset), $morph?->targets[TrashableAssetFile::class]->deletedAt]);
    }

    #[Test]
    public function it_compiles_a_morph_to_whose_owner_key_is_the_key_of_every_target(): void
    {
        $morph = (new Successor('place_videos', PlaceAsset::class))
            ->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->morphTo(
                name: 'asset',
                type: 'asset_type',
                id: 'asset_id',
                ownerKey: 'id',
            ),
                choose: fn (Video $video): string => $video->youtube_embed_url !== null ? AssetLink::class : AssetFile::class,
                targets: fn (Targets $targets) => $targets
                    ->as(target: AssetFile::class, successor: fn (Successor $file) => $file)
                    ->as(target: AssetLink::class, successor: fn (Successor $link) => $link))
            ->morph();

        $this->assertSame(['id', 'id'], array_values(array_map(fn (Related $target) => $target->relatedKey, $morph->targets ?? [])));
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

        yield 'a belongs to' => [fn () => (new Successor(
            map: 'place_videos',
            model: Place::class,
        ))->relatedMorph(relation: fn (Place $place) => $place->job(), choose: fn (Model $legacy): string => AssetFile::class,
            targets: fn (Targets $targets) => $targets->as(target: AssetFile::class, successor: fn (Successor $file) => $file)), 'the relatedMorph() relation from Place to Job is a BelongsTo, and relatedMorph() reads a MorphTo; declare it with related()'];
        yield 'a has many' => [fn () => (new Successor(
            map: 'place_videos',
            model: Job::class,
        ))->relatedMorph(relation: fn (Job $job) => $job->hasMany(Place::class), choose: fn (Model $legacy): string => AssetFile::class,
            targets: fn (Targets $targets) => $targets->as(target: AssetFile::class, successor: fn (Successor $file) => $file)), 'the relatedMorph() relation from Job to Place is a HasMany, and relatedMorph() reads a MorphTo only'];
        yield 'membership on a target' => [fn () => $asset()->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset(), choose: fn (Model $legacy): string => AssetFile::class,
            targets: fn (Targets $targets) => $targets->as(target: AssetFile::class, successor: fn (Successor $file) => $file->when(fn (Builder $files) => $files->whereNotNull('zml_asset_id')))), 'when() on AssetFile, which relatedMorph() reaches, decides membership, and only the root PlaceAsset carries when()'];
        yield 'at most one target row' => [fn () => $asset()->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset(), choose: fn (Model $legacy): string => AssetFile::class,
            targets: fn (Targets $targets) => $targets->as(target: AssetFile::class, successor: fn (Successor $file) => $file->atMostOne('One file.'))), 'atMostOne() on AssetFile, which relatedMorph() reaches, refuses a second row, and a MorphTo reads one asset_files row by its key; drop atMostOne()'];
        yield 'a relation of a target' => [fn () => $asset()->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset(), choose: fn (Model $legacy): string => AssetFile::class,
            targets: fn (Targets $targets) => $targets->as(target: AssetFile::class, successor: fn (Successor $file) => $file->related(
                relation: fn (AssetFile $file) => $file->hasOne(
                    related: AssetLink::class,
                    foreignKey: 'id',
                ),
                successor: fn (Successor $link) => $link,
            ))), 'AssetFile, which relatedMorph() reaches, declares related() or relatedMorph() of its own, and a morph target serves the columns of its own table; declare the relation on PlaceAsset'];
        yield 'a scoped target' => [fn () => $asset()->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset(), choose: fn (Model $legacy): string => ScopedAssetFile::class,
            targets: fn (Targets $targets) => $targets->as(target: ScopedAssetFile::class, successor: fn (Successor $file) => $file)), 'ScopedAssetFile, which relatedMorph() reaches, applies the global scope visible, which would read the application\'s state once and keep it; only SoftDeletes may apply'];
        yield 'a second morph' => [fn () => $asset()->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset(), choose: fn (Model $legacy): string => AssetFile::class,
            targets: fn (Targets $targets) => $targets->as(target: AssetFile::class, successor: fn (Successor $file) => $file))->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset(), choose: fn (Model $legacy): string => AssetLink::class,
                targets: fn (Targets $targets) => $targets->as(target: AssetLink::class, successor: fn (Successor $link) => $link)), 'relatedMorph() on PlaceAsset is declared twice; one legacy record reads one MorphTo of its successor'];
        yield 'an owner key a target does not key by' => [fn () => $asset()->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->morphTo(
            name: 'asset',
            type: 'asset_type',
            id: 'asset_id',
            ownerKey: 'zml_asset_id',
        ), choose: fn (Model $legacy): string => AssetFile::class,
            targets: fn (Targets $targets) => $targets->as(target: AssetFile::class, successor: fn (Successor $file) => $file)), 'the relatedMorph() relation from PlaceAsset through asset() matches each target on the owner key zml_asset_id, and AssetFile keys its rows by id; drop the owner key, because the view and the writers join each target on its key'];
        $constrained = 'the relatedMorph() relation from PlaceAsset through asset() constrains the targets it reads, and the view and the writers join each target on its morph alias and key alone; drop the constraint, because relatedMorph() serves every target row a PlaceAsset names';

        yield 'a condition on the targets' => [fn () => $asset()->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset()->where(
            column: 'filename',
            operator: 'like',
            value: 'public-%',
        ), choose: fn (Model $legacy): string => AssetFile::class,
            targets: fn (Targets $targets) => $targets->as(target: AssetFile::class, successor: fn (Successor $file) => $file)), $constrained];
        yield 'an order on the targets' => [fn () => $asset()->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset()->orderBy('filename'), choose: fn (Model $legacy): string => AssetFile::class,
            targets: fn (Targets $targets) => $targets->as(target: AssetFile::class, successor: fn (Successor $file) => $file)), $constrained];
        yield 'a scope call replayed on the targets' => [fn () => $asset()->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset()->withTrashed(), choose: fn (Model $legacy): string => TrashableAssetFile::class,
            targets: fn (Targets $targets) => $targets->as(target: TrashableAssetFile::class, successor: fn (Successor $file) => $file)), $constrained];
        yield 'a constraint for one target class' => [fn () => $asset()->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset()->constrain([AssetFile::class => fn (Builder $files) => $files->whereNotNull('zml_asset_id')]), choose: fn (Model $legacy): string => AssetFile::class,
            targets: fn (Targets $targets) => $targets->as(target: AssetFile::class, successor: fn (Successor $file) => $file)), $constrained];
        $defaulted = 'the relatedMorph() relation from PlaceAsset through asset() returns a default model when no target row exists, and the view and the writers serve NULL for each attribute of a missing target; drop withDefault()';

        yield 'a default model' => [fn () => $asset()->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset()->withDefault(), choose: fn (Model $legacy): string => AssetFile::class,
            targets: fn (Targets $targets) => $targets->as(target: AssetFile::class, successor: fn (Successor $file) => $file)), $defaulted];
        yield 'a default model with attributes' => [fn () => $asset()->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset()->withDefault(['filename' => 'Unknown']), choose: fn (Model $legacy): string => AssetFile::class,
            targets: fn (Targets $targets) => $targets->as(target: AssetFile::class, successor: fn (Successor $file) => $file)), $defaulted];
        yield 'a relation closure that captures' => [fn () => $asset()->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->morphTo($relation), choose: fn (Model $legacy): string => AssetFile::class,
            targets: fn (Targets $targets) => $targets->as(target: AssetFile::class, successor: fn (Successor $file) => $file)), 'a relatedMorph() relation closure on PlaceAsset captures $relation, and a map reads nothing but its own arguments'];
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
