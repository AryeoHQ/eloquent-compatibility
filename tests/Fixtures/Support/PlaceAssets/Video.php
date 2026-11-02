<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\PlaceAssets;

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
            ->discriminatedBy('legacy_type')
            ->identifiedBy('id', fn (Identifier $identifier) => $identifier
                ->integer()
                ->from('legacy_id'))
            ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier
                ->uuid()
                ->from('legacy_uuid'))
            ->retainedIn('place_videos_retained')
            ->timestamps()
            ->servedFrom(PlaceAsset::class, fn (Successor $asset) => $asset
                ->attribute('title', fn (Attribute $attribute) => $attribute->from('title'))
                ->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(),
                    choose: fn (Video $video): string => $video->youtube_embed_url !== null ? AssetLink::class : AssetFile::class,
                    successors: fn (Successors $successors) => $successors
                        ->as(AssetFile::class, fn (Successor $file) => $file
                            ->attribute('original_file_name', fn (Attribute $attribute) => $attribute->from('filename'))
                            ->attribute('size_kb', fn (Attribute $attribute) => $attribute
                                ->from('size_bytes')
                                ->dividedBy(1000))
                            ->attribute('youtube_embed_url', fn (Attribute $attribute) => $attribute->fixed(null)))
                        ->as(AssetLink::class, fn (Successor $link) => $link
                            ->attribute('original_file_name', fn (Attribute $attribute) => $attribute->fixed(null))
                            ->attribute('size_kb', fn (Attribute $attribute) => $attribute->fixed(null))
                            ->attribute('youtube_embed_url', fn (Attribute $attribute) => $attribute->from('url')))));
    }
}
