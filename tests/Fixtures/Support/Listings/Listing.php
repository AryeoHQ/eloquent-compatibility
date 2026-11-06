<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;
use Support\Database\Eloquent\Compatibility\Provides\ServesCompatibility;

/**
 * @property int $id
 * @property string $uuid
 * @property null|string $job_id
 * @property string $title
 * @property string $company_id
 * @property null|string $price
 * @property ListingStatus $status
 * @property null|string $street
 * @property null|string $city
 * @property null|float $latitude
 * @property null|string $mls_number
 * @property bool $featured
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 * @property null|Carbon $deleted_at
 */
final class Listing extends Model implements Compatible
{
    use ServesCompatibility;
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
            ->retainedIn('listings_retained')
            ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                ->attribute(
                    name: 'created_at',
                    definition: fn (Attribute $attribute) => $attribute->from('created_at'),
                )
                ->attribute(
                    name: 'updated_at',
                    definition: fn (Attribute $attribute) => $attribute->from('updated_at'),
                )
                ->attribute(
                    name: 'deleted_at',
                    definition: fn (Attribute $attribute) => $attribute->from('deleted_at'),
                )
                ->attribute(
                    name: 'title',
                    definition: fn (Attribute $attribute) => $attribute->from('name'),
                )
                ->attribute(
                    name: 'company_id',
                    definition: fn (Attribute $attribute) => $attribute
                        ->from('company_id')
                        ->readOnly(because: 'A job never changes company.'),
                )
                ->attribute(
                    name: 'price',
                    definition: fn (Attribute $attribute) => $attribute
                        ->from('price_cents')
                        ->dividedBy(100),
                )
                ->attribute(
                    name: 'status',
                    definition: fn (Attribute $attribute) => $attribute
                        ->from('lifecycle_status')
                        ->mapping([
                            LifecycleStatus::Drafting->value => ListingStatus::Draft,
                            LifecycleStatus::Live->value => ListingStatus::Active,
                            LifecycleStatus::Closed->value => ListingStatus::Sold,
                        ])
                        ->transitions(),
                )
                ->related(
                    relation: fn (Job $job) => $job->rootPlace(),
                    definition: fn (Successor $place) => $place
                        ->atMostOne(because: 'A listing has one address.')
                        ->attribute('street', fn (Attribute $attribute) => $attribute->from('street'))
                        ->attribute('city', fn (Attribute $attribute) => $attribute->from('city')),
                )
                ->related(
                    relation: fn (Job $job) => $job->site(),
                    definition: fn (Successor $site) => $site
                        ->attribute('latitude', fn (Attribute $attribute) => $attribute->from('latitude')),
                ))
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
