<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\PlaceAssets;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Definition\Targets;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;

/**
 * @property int $id
 * @property string $uuid
 * @property null|string $title
 * @property null|string $original_file_name
 * @property null|string $size_kb
 * @property null|string $youtube_embed_url
 * @property int $views
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 */
final class Video extends Model implements Compatible
{
    use ServesCompatibility;

    protected $table = 'place_videos';

    protected $guarded = [];

    protected static function booted(): void
    {
        Relation::morphMap(['place_video' => self::class]);
    }

    public static function compatibility(Compatibility $video): Compatibility
    {
        return $video
            ->retainedIn('place_videos_retained')
            ->successorsInitializedBy(ClassifyPlaceAsset::class)
            ->servedFrom(root: PlaceAsset::class, key: 'place_asset_id', successor: fn (Successor $asset) => $asset
                ->when(fn (Builder $assets) => $assets->where(column: 'legacy_type', operator: '=', value: 'place_video'))
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
                    choose: fn (Video $video): string => $video->youtube_embed_url !== null ? AssetLink::class : AssetFile::class,
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
}
