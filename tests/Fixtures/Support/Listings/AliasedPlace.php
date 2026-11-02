<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $job_id
 * @property string $street
 * @property null|string $city
 */
final class AliasedPlace extends Model
{
    use HasUuids;

    public const string CONNECTION = 'compatibility_aliased';

    protected $connection = self::CONNECTION;

    protected $table = 'places';

    protected $guarded = [];
}
