<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\WorkedListing;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Support\Database\Eloquent\Compatibility\Provides\CarriesLegacy;

/**
 * @property string $id
 * @property string $name
 * @property string $company_id
 * @property string $lifecycle_status
 * @property null|bool $are_customer_downloads_enabled
 * @property bool $opted_in_to_zma
 * @property PaymentStatus $payment_status
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

    protected function casts(): array
    {
        return [
            'are_customer_downloads_enabled' => 'boolean',
            'opted_in_to_zma' => 'boolean',
            'payment_status' => PaymentStatus::class,
        ];
    }

    public function draft(): void
    {
        $this->moveTo('draft');
    }

    public function publish(): void
    {
        $this->moveTo('published');
    }

    /** @return HasOne<ListingCompatibilityState, $this> */
    public function listingCompatibilityState(): HasOne
    {
        return $this->hasOne(ListingCompatibilityState::class);
    }

    /** @return HasOne<Place, $this> */
    public function place(): HasOne
    {
        return $this->hasOne(Place::class);
    }

    /** @return HasOne<JobSite, $this> */
    public function jobSite(): HasOne
    {
        return $this->hasOne(JobSite::class);
    }

    private function moveTo(string $status): void
    {
        $this->lifecycle_status = $status;
        $this->save();
    }
}
