<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\PlaceAssets;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as Values;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Definition\Successors;
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
            ->discriminatedBy('legacy_type')
            ->identifiedBy('id', fn (Identifier $identifier) => $identifier
                ->integer()
                ->from('legacy_id'))
            ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier
                ->uuid()
                ->from('legacy_uuid'))
            ->retainedIn('place_images_retained')
            ->timestamps()
            ->servedFrom(PlaceAsset::class, fn (Successor $asset) => $asset
                ->attribute('title', fn (Attribute $attribute) => $attribute->from('title'))
                ->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(),
                    successors: fn (Successors $successors) => $successors
                        ->as(AssetFile::class, fn (Successor $file) => $file
                            ->attribute('file_name', fn (Attribute $attribute) => $attribute->from('filename'))
                            ->attribute('mime_type', fn (Attribute $attribute) => $attribute
                                ->external(
                                    read: fn (Collection $files): Values => AssetFile::metadataFor($files)->map(fn (FileMetadata $metadata) => $metadata->mimeType),
                                    write: fn (AssetFile $file, null|string $mimeType) => $file->metadata()->mimeType = $mimeType)))));
    }
}
