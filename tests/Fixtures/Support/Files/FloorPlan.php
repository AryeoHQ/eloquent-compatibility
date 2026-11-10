<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Files;

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
use Tests\Fixtures\Support\PlaceAssets\AssetFile;
use Tests\Fixtures\Support\PlaceAssets\AssetLink;
use Tests\Fixtures\Support\PlaceAssets\ClassifyPlaceAsset;
use Tests\Fixtures\Support\PlaceAssets\PlaceAsset;

/**
 * @property int $id
 * @property string $uuid
 * @property null|string $place_asset_id
 * @property null|string $file_name
 * @property null|string $mime_type
 * @property null|string $url
 * @property null|int $level
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 */
final class FloorPlan extends Model implements Compatible
{
    use ServesCompatibility;

    protected $table = 'floorplans';

    protected $guarded = [];

    protected static function booted(): void
    {
        Relation::morphMap(['floorplan' => self::class]);
    }

    public static function compatibility(Compatibility $floorPlan): Compatibility
    {
        return $floorPlan
            ->retainedIn('floorplans_retained')
            ->successorsInitializedBy(ClassifyPlaceAsset::class)
            ->servedFrom(root: PlaceAsset::class, key: 'place_asset_id', successor: fn (Successor $asset) => $asset
                ->when(fn (Builder $assets) => $assets->where(column: 'legacy_type', operator: '=', value: 'floorplan'))
                ->attribute(
                    name: 'created_at',
                    definition: fn (Attribute $attribute) => $attribute->from('created_at'),
                )
                ->attribute(
                    name: 'updated_at',
                    definition: fn (Attribute $attribute) => $attribute->from('updated_at'),
                )
                ->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset(),
                    choose: fn (FloorPlan $plan): string => $plan->url !== null ? AssetLink::class : AssetFile::class,
                    targets: fn (Targets $targets) => $targets
                        ->as(target: AssetFile::class, successor: fn (Successor $file) => $file
                            ->attribute(
                                name: 'file_name',
                                definition: fn (Attribute $attribute) => $attribute->from('filename'),
                            )
                            ->attribute(
                                name: 'mime_type',
                                definition: fn (Attribute $attribute) => $attribute->from('mime_type'),
                            )
                            ->attribute(
                                name: 'url',
                                definition: fn (Attribute $attribute) => $attribute->fixed(null)->readOnly(because: 'An AssetFile stores no url.', ignoreWrites: true),
                            ))
                        ->as(target: AssetLink::class, successor: fn (Successor $link) => $link
                            ->attribute(
                                name: 'file_name',
                                definition: fn (Attribute $attribute) => $attribute->fixed(null)->readOnly(because: 'An AssetLink stores no file_name.', ignoreWrites: true),
                            )
                            ->attribute(
                                name: 'mime_type',
                                definition: fn (Attribute $attribute) => $attribute->fixed(null)->readOnly(because: 'An AssetLink stores no mime_type.', ignoreWrites: true),
                            )
                            ->attribute(
                                name: 'url',
                                definition: fn (Attribute $attribute) => $attribute->from('url'),
                            ))));
    }
}
