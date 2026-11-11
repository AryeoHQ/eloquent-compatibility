<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Files;

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
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;

/**
 * @property int $id
 * @property string $uuid
 * @property null|string $original_file_name
 * @property null|string $mime_type
 * @property null|string $size_kb
 * @property null|string $youtube_embed_url
 * @property null|string $title
 * @property int $views
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 */
final class Video extends Model implements Compatible
{
    use ServesCompatibility;

    protected $table = 'videos';

    protected $guarded = [];

    protected static function booted(): void
    {
        Relation::morphMap(['video' => self::class]);
    }

    public static function compatibility(Compatibility $video): Compatibility
    {
        return $video
            ->retainedIn('videos_retained')
            ->guard('embed host', fn (Video $after, Video $before) => $after->youtube_embed_url !== null && ! str_starts_with($after->youtube_embed_url, 'https://youtu.be/'), because: 'The listing page plays embeds from YouTube only.', instead: 'Write a https://youtu.be/ link.')
            ->servedFrom(root: File::class, key: 'file_id', successor: fn (Successor $file) => $file
                ->when(fn (Builder $files) => $files->where(fn (Builder $members) => $members->where(column: 'mime_type', operator: 'like', value: 'video/%')->orWhereNotNull('embed_url')))
                ->attribute(
                    name: 'created_at',
                    definition: fn (Attribute $attribute) => $attribute->from('created_at'),
                )
                ->attribute(
                    name: 'updated_at',
                    definition: fn (Attribute $attribute) => $attribute->from('updated_at'),
                )
                ->attribute(
                    name: 'original_file_name',
                    definition: fn (Attribute $attribute) => $attribute->from('filename'),
                )
                ->attribute(
                    name: 'mime_type',
                    definition: fn (Attribute $attribute) => $attribute
                        ->computedAs(read: fn (Collection $files): Values => File::metadataFor($files)->map(fn (FileMetadata $metadata) => $metadata->mimeType))
                        ->writtenBy(WriteFileMimeType::class),
                )
                ->attribute(
                    name: 'size_kb',
                    definition: fn (Attribute $attribute) => $attribute
                        ->from('size_bytes')
                        ->dividedBy(1000),
                )
                ->attribute(
                    name: 'youtube_embed_url',
                    definition: fn (Attribute $attribute) => $attribute->from('embed_url'),
                ));
    }
}
