<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\PlaceAssets;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as Values;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Definition\Targets;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;
use Tests\Fixtures\Support\Files\FileMetadata;

/**
 * @property int $id
 * @property string $uuid
 * @property null|string $title
 * @property string $file_name
 * @property null|string $mime_type
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 */
final class Image extends Model implements Compatible
{
    use ServesCompatibility;

    protected $table = 'place_images';

    protected $guarded = [];

    protected static function booted(): void
    {
        Relation::morphMap(['place_image' => self::class]);
    }

    public static function compatibility(Compatibility $image): Compatibility
    {
        return $image
            ->retainedIn('place_images_retained')
            ->servedFrom(root: PlaceAsset::class, key: 'place_asset_id', successor: fn (Successor $asset) => $asset
                ->when(fn (Builder $assets) => $assets->where(column: 'legacy_type', operator: '=', value: 'place_image'))
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
                    choose: fn (Image $image): string => AssetFile::class,
                    targets: fn (Targets $targets) => $targets
                        ->as(target: AssetFile::class, successor: fn (Successor $file) => $file
                            ->attribute(
                                name: 'file_name',
                                definition: fn (Attribute $attribute) => $attribute->from('filename'),
                            )
                            ->attribute(
                                name: 'mime_type',
                                definition: fn (Attribute $attribute) => $attribute
                                    ->external(
                                        read: fn (Collection $files): Values => AssetFile::metadataFor($files)->map(fn (FileMetadata $metadata) => $metadata->mimeType),
                                        write: fn (AssetFile $file, null|string $mimeType) => $file->metadata()->mimeType = $mimeType),
                            ))));
    }
}
