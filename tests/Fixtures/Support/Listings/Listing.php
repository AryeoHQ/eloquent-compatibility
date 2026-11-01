<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\Successor;

/**
 * @property int $id
 * @property string $uuid
 * @property string $title
 * @property null|string $price
 * @property ListingStatus $status
 */
final class Listing extends Model implements Compatible
{
    use SoftDeletes;

    protected $table = 'listings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'status' => ListingStatus::class,
            'latitude' => 'float',
        ];
    }

    public static function compatibility(Compatibility $listing): Compatibility
    {
        return $listing
            ->identifiedBy('id', fn (Identifier $identifier) => $identifier
                ->integer()
                ->from('legacy_id'))
            ->identifiedBy('uuid', fn (Identifier $identifier) => $identifier
                ->uuid()
                ->from('legacy_uuid'))
            ->retainedIn('listings_retained', key: 'job_id')
            ->timestamps()
            ->servedFrom(Job::class, fn (Successor $job) => $job
                ->attribute('title', fn (Attribute $attribute) => $attribute->from('name'))
                ->attribute('company_id', fn (Attribute $attribute) => $attribute
                    ->from('company_id')
                    ->readOnly(because: 'A job never changes company.'))
                ->attribute('price', fn (Attribute $attribute) => $attribute
                    ->from('price_cents')
                    ->dividedBy(100))
                ->attribute('status', fn (Attribute $attribute) => $attribute
                    ->from('lifecycle_status')
                    ->mapping([
                        LifecycleStatus::Drafting->value => ListingStatus::Draft,
                        LifecycleStatus::Live->value => ListingStatus::Active,
                        LifecycleStatus::Closed->value => ListingStatus::Sold,
                    ])
                    ->transitions())
                ->related(fn (Job $job) => $job->rootPlace(), fn (Successor $place) => $place
                    ->atMostOne(because: 'A listing has one address.')
                    ->attribute('street', fn (Attribute $attribute) => $attribute->from('street'))
                    ->attribute('city', fn (Attribute $attribute) => $attribute->from('city')))
                ->related(fn (Job $job) => $job->site(), fn (Successor $site) => $site
                    ->attribute('latitude', fn (Attribute $attribute) => $attribute->from('latitude'))))
            ->hide(fn (Builder $jobs) => $jobs->whereNotNull('merged_into_job_id'), because: 'A merged job lives on as the job it merged into.')
            ->guard(
                'sold price frozen',
                fn (Listing $after, Listing $before) => $before->status === ListingStatus::Sold && $after->price !== $before->price,
                because: 'A sold listing keeps the price it sold for.',
                instead: 'Change the price before the sale.',
            )
            ->withoutForwardingTo(SyncListingToMls::class, because: 'MLS sync follows only edits made through the Listing itself.');
    }
}
