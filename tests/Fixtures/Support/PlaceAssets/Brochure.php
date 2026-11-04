<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\PlaceAssets;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Definition\Successors;
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
            ->discriminatedBy('legacy_type')
            ->identifiedBy('id', fn (Identifier $identifier) => $identifier
                ->integer()
                ->from('legacy_id'))
            ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier
                ->uuid()
                ->from('legacy_uuid'))
            ->retainedIn('place_brochures_retained')
            ->timestamps()
            ->servedFrom(PlaceAsset::class, fn (Successor $asset) => $asset
                ->attribute('title', fn (Attribute $attribute) => $attribute
                    ->external(
                        read: fn (Collection $assets): array => $assets->mapWithKeys(fn (PlaceAsset $asset) => [$asset->getKey() => $asset->title])->all(),
                        write: fn (PlaceAsset $asset, null|string $title) => $asset->title = $title))
                ->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(),
                    choose: fn (Brochure $brochure): string => $brochure->hosted ? TrashableAssetFile::class : AssetLink::class,
                    successors: fn (Successors $successors) => $successors
                        ->as(TrashableAssetFile::class, fn (Successor $file) => $file
                            ->attribute('file_name', fn (Attribute $attribute) => $attribute->from('filename'))
                            ->attribute('url', fn (Attribute $attribute) => $attribute->fixed(null)))
                        ->as(AssetLink::class, fn (Successor $link) => $link
                            ->attribute('file_name', fn (Attribute $attribute) => $attribute->fixed(null))
                            ->attribute('url', fn (Attribute $attribute) => $attribute->from('url')))));
    }
}
