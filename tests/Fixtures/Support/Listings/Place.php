<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Provides\HasCompatibility;

/**
 * @property string $id
 * @property string $job_id
 * @property null|string $parent_id
 * @property Job $job
 */
final class Place extends Model implements Compatible
{
    use HasCompatibility;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'places';

    protected $guarded = [];

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }
}
