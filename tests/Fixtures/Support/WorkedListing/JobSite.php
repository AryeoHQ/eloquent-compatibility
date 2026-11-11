<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\WorkedListing;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $job_id
 * @property null|float $latitude
 * @property null|float $longitude
 */
final class JobSite extends Model
{
    use HasUuids;

    protected $table = 'job_sites';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }
}
