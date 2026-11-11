<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Support\Database\Eloquent\Compatibility\Provides\CarriesLegacy;
use Support\Database\Eloquent\StateMachines\StateMachine;

/**
 * @property string $id
 * @property string $name
 * @property string $company_id
 * @property null|int $price_cents
 * @property StateMachine<LifecycleStatus> $lifecycle_status
 * @property null|string $merged_into_job_id
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 * @property null|Carbon $deleted_at
 */
final class Job extends Model
{
    use CarriesLegacy;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'jobs';

    protected $guarded = [];

    protected $dispatchesEvents = [
        'updated' => JobUpdated::class,
    ];

    protected function casts(): array
    {
        return ['lifecycle_status' => LifecycleStatus::class];
    }

    /** @return HasMany<Place, $this> */
    public function places(): HasMany
    {
        return $this->hasMany(Place::class);
    }

    /** @return HasOne<Place, $this> */
    public function rootPlace(): HasOne
    {
        return $this->hasOne(Place::class)->withAttributes(['parent_id' => null])->oldest('id');
    }

    /** @return HasOne<JobSite, $this> */
    public function site(): HasOne
    {
        return $this->hasOne(JobSite::class);
    }
}
