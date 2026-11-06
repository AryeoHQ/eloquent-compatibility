<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\PlaceAssets;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
 * @property null|string $file_name
 * @property null|string $url
 * @property bool $hosted
 * @property int $pages
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 */
final class Brochure extends Model implements Compatible
{
    use ServesCompatibility;

    protected $table = 'place_brochures';

    protected $guarded = [];

    protected static function booted(): void
    {
        Relation::morphMap(['place_brochure' => self::class]);
    }

    public static function compatibility(Compatibility $brochure): Compatibility
    {
        return $brochure
            ->retainedIn('place_brochures_retained')
            ->servedFrom(root: PlaceAsset::class, key: 'place_asset_id', successor: fn (Successor $asset) => $asset
                ->when(fn (Builder $assets) => $assets->where(column: 'legacy_type', operator: '=', value: 'place_brochure'))
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
                    definition: fn (Attribute $attribute) => $attribute
                        ->external(
                            read: fn (Collection $assets): array => $assets->mapWithKeys(fn (PlaceAsset $asset) => [$asset->getKey() => $asset->title])->all(),
                            write: fn (PlaceAsset $asset, null|string $title) => $asset->title = $title),
                )
                ->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset(),
                    choose: fn (Brochure $brochure): string => $brochure->hosted ? TrashableAssetFile::class : AssetLink::class,
                    targets: fn (Targets $targets) => $targets
                        ->as(target: TrashableAssetFile::class, successor: fn (Successor $file) => $file
                            ->attribute(
                                name: 'file_name',
                                definition: fn (Attribute $attribute) => $attribute->from('filename'),
                            )
                            ->attribute(
                                name: 'url',
                                definition: fn (Attribute $attribute) => $attribute->fixed(null),
                            ))
                        ->as(target: AssetLink::class, successor: fn (Successor $link) => $link
                            ->attribute(
                                name: 'file_name',
                                definition: fn (Attribute $attribute) => $attribute->fixed(null),
                            )
                            ->attribute(
                                name: 'url',
                                definition: fn (Attribute $attribute) => $attribute->from('url'),
                            ))));
    }
}
