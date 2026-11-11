<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Illuminate\Database\Eloquent\Builder;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;

final class ListingOfAnyPlace
{
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
                        ->readOnly(because: 'A job never changes company.', ignoreWrites: false),
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
                        ->writtenBy(TransitionListingStatus::class),
                )
                ->related(
                    relation: fn (Job $job) => $job->hasOne(Place::class)->oldest('street'),
                    successor: fn (Successor $place) => $place
                        ->atMostOne(because: 'A listing has one address.')
                        ->attribute('street', fn (Attribute $attribute) => $attribute->from('street'))
                        ->attribute('city', fn (Attribute $attribute) => $attribute->from('city')),
                )
                ->related(
                    relation: fn (Job $job) => $job->site(),
                    successor: fn (Successor $site) => $site
                        ->attribute('latitude', fn (Attribute $attribute) => $attribute->from('latitude')),
                ))
            ->hide(fn (Builder $jobs) => $jobs->whereNotNull('merged_into_job_id'), because: 'A merged job lives on as the job it merged into.');
    }
}
