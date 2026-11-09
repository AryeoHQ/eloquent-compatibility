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
 * @property string $file_name
 * @property string $mime_type
 * @property null|string $size_kb
 * @property null|string $caption
 * @property int $views
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 */
final class Photo extends Model implements Compatible
{
    use ServesCompatibility;

    protected $table = 'photos';

    protected $guarded = [];

    protected static function booted(): void
    {
        Relation::morphMap(['photo' => self::class]);
    }

    public static function compatibility(Compatibility $photo): Compatibility
    {
        return $photo
            ->retainedIn('photos_retained')
            ->servedFrom(root: File::class, key: 'file_id', successor: fn (Successor $file) => $file
                ->when(fn (Builder $files) => $files->where('mime_type', 'like', 'image/%'))
                ->attribute(
                    name: 'created_at',
                    definition: fn (Attribute $attribute) => $attribute->from('created_at'),
                )
                ->attribute(
                    name: 'updated_at',
                    definition: fn (Attribute $attribute) => $attribute->from('updated_at'),
                )
                ->attribute(
                    name: 'file_name',
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
                ));
    }
}
