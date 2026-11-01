<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Provides\HasCompatibility;

/**
 * @property string $id
 * @property string $name
 * @property null|int $price_cents
 * @property bool $archived
 */
final class Job extends Model implements Compatible
{
    use HasCompatibility;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'jobs';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['archived' => 'boolean', 'published_at' => 'datetime'];
    }

    /** @return HasMany<Place, $this> */
    public function places(): HasMany
    {
        return $this->hasMany(Place::class);
    }

    /**
     * The one place a Listing reads its street from.
     *
     * @return HasOne<Place, $this>
     */
    public function rootPlace(): HasOne
    {
        return $this->hasOne(Place::class)->withAttributes(['parent_id' => null])->oldest('id');
    }

    /** @return HasOne<JobSite, $this> */
    public function site(): HasOne
    {
        return $this->hasOne(JobSite::class);
    }

    /** @return HasOne<TenantPlace, $this> */
    public function tenantPlace(): HasOne
    {
        return $this->hasOne(TenantPlace::class);
    }
}
