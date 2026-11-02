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
            ->discriminatedBy('legacy_type')
            ->identifiedBy('id', fn (Identifier $identifier) => $identifier
                ->integer()
                ->from('legacy_id'))
            ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier
                ->uuid()
                ->from('legacy_uuid'))
            ->retainedIn('floorplans_retained')
            ->timestamps()
            ->servedFrom(File::class, fn (Successor $file) => $file
                ->when(fn (Builder $files) => $files->whereIn('mime_type', ['image/png', 'application/pdf']))
                ->attribute('file_name', fn (Attribute $attribute) => $attribute->from('filename'))
                ->attribute('mime_type', fn (Attribute $attribute) => $attribute->from('mime_type')));
    }
}
