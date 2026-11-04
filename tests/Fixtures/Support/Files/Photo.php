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
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
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
            ->discriminatedBy('legacy_type')
            ->identifiedBy('id', fn (Identifier $identifier) => $identifier
                ->integer()
                ->from('legacy_id'))
            ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier
                ->uuid()
                ->from('legacy_uuid'))
            ->retainedIn('photos_retained')
            ->timestamps()
            ->servedFrom(File::class, fn (Successor $file) => $file
                ->when(fn (Builder $files) => $files->where('mime_type', 'like', 'image/%'))
                ->attribute('file_name', fn (Attribute $attribute) => $attribute->from('filename'))
                ->attribute('mime_type', fn (Attribute $attribute) => $attribute->from('mime_type'))
                ->attribute('size_kb', fn (Attribute $attribute) => $attribute
                    ->from('size_bytes')
                    ->dividedBy(1000)));
    }
}
