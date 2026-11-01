<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\DefinesCompatibility;
use Support\Database\Eloquent\Compatibility\Provides\HasCompatibility;

/**
 * The previous model: one listings table, now served from jobs, places and job_sites.
 *
 * @property int $id
 * @property null|string $price
 * @property bool $is_published
 * @property null|string $street
 * @property null|float $latitude
 * @property string $status
 * @property null|string $job_id
 */
#[UseEloquentBuilder(ListingBuilder::class)]
final class Listing extends Model implements DefinesCompatibility
{
    use HasCompatibility;
    use SoftDeletes;

    protected $table = 'listings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => CentsAsDollars::class,
            'is_published' => PublishedFlag::class,
            'latitude' => 'float',
        ];
    }

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public static function compatibility(Compatibility $map): Compatibility
    {
        return $map
            ->identifiedBy(ListingRetained::class)
            ->attributes([
                'uuid' => 'uuid',
                'mls_number' => 'mls_number',
                'status' => 'status',
                'title' => 'job.name',
                'price' => 'job.price_cents',
                'is_published' => 'job.published_at',
                'street' => 'job.rootPlace.street',
                'city' => 'job.rootPlace.city',
                'latitude' => 'job.site.latitude',
            ])
            ->timestampsOn('job')
            ->readOnly('uuid', because: 'Partners hold the listing uuid.')
            ->creating('job', fn (Job $job, Listing $listing) => $job->setAttribute('source', 'legacy-listing'))
            ->createWhen('job.site', fn (Listing $listing) => $listing->latitude !== null)
            ->designatedBy('job.rootPlace', 'place_id')
            ->oneForEach('job', fn (ListingRetained $listing, Job $job) => $listing->setAttribute('status', $job->archived ? 'Archived' : 'Draft'))
            ->hide(fn (Builder $retained) => $retained->whereNull('job_id')->where('status', 'Retired'), because: 'Retired listings predate jobs.')
            ->guard(
                'sold_price_frozen',
                fn (Listing $after, Listing $before) => $before->status === 'Sold' && $after->price !== $before->price,
                because: 'A sold listing keeps the price it sold for.',
                instead: 'Change the price before the sale.',
            )
            ->refuse(
                'second_root_place',
                on: Place::class,
                when: fn (Place $place) => $place->isRoot()
                    && $place->job->places()->whereNull('parent_id')->whereKeyNot($place->getKey())->exists(),
                because: 'A listing has one street, so a job may have only one place without a parent.',
                instead: 'Give the new place a parent_id, or soft-delete the current root place first.',
                candidates: fn (Builder $places) => $places->whereNull('parent_id'),
            )
            ->excludeFromMirroredEvents(SyncListingToMls::class, because: 'MLS sync follows only edits made through the Listing itself.');
    }
}
