<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Files;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
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
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 */
final class LegacyFile extends Model implements Compatible
{
    use ServesCompatibility;

    protected $table = 'legacy_files';

    protected $guarded = [];

    protected $dispatchesEvents = [
        'saving' => LegacyFileSaving::class,
        'updated' => LegacyFileUpdated::class,
    ];

    protected static function booted(): void
    {
        Relation::morphMap(['legacy_file' => self::class]);
    }

    public static function compatibility(Compatibility $legacyFile): Compatibility
    {
        return $legacyFile
            ->retainedIn('legacy_files_retained')
            ->servedFrom(root: File::class, key: 'file_id', successor: fn (Successor $file) => $file
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
                    definition: fn (Attribute $attribute) => $attribute->from('mime_type'),
                ))
            ->withoutForwardingTo(ArchiveLegacyFile::class, because: 'The archive follows only edits made through the LegacyFile itself.');
    }
}
