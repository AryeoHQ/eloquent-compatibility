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
final class DefaultedPlace extends Model
{
    use HasUuids;

    protected $table = 'places';

    protected $guarded = [];

    protected $attributes = [
        'street' => 'Unknown St',
        'city' => 'Unknown',
    ];
}
