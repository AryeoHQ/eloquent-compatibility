<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $job_id
 * @property null|string $parent_id
 * @property string $street
 * @property null|string $city
 */
final class Place extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'places';

    protected $guarded = [];

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }
}
