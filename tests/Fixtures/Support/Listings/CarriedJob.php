<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Provides\CarriesLegacy;

/**
 * @property string $id
 * @property string $name
 */
class CarriedJob extends Model
{
    use CarriesLegacy;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'configured_jobs';

    protected $guarded = [];
}
