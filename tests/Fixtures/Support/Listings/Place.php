<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $job_id
 * @property null|string $parent_id
 * @property string $street
 * @property null|string $city
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
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
