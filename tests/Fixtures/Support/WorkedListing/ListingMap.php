<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\WorkedListing;

use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Successor;

final class ListingMap
{
    public static function define(Compatibility $listing): Compatibility
    {
        return $listing
            ->retainedIn('listings_retained')
            ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                ->attribute(name: 'created_at', definition: fn (Attribute $attribute) => $attribute->from('created_at')->inTimeZone('UTC'))
                ->attribute(name: 'updated_at', definition: fn (Attribute $attribute) => $attribute->from('updated_at')->inTimeZone('UTC'))
                ->attribute(name: 'deleted_at', definition: fn (Attribute $attribute) => $attribute->from('deleted_at')->inTimeZone('UTC'))
                ->attribute(name: 'company_id', definition: fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A Job never changes company.', ignoreWrites: false))
                ->attribute(name: 'is_draft', definition: fn (Attribute $attribute) => $attribute
                    ->readAs('"jobs"."lifecycle_status" = \'draft\'')
                    ->writtenBy(PublishOrDraftJob::class))
                ->attribute(name: 'downloads_enabled', definition: fn (Attribute $attribute) => $attribute
                    ->from(column: 'are_customer_downloads_enabled')
                    ->orFrom(successor: ListingCompatibilityState::class, column: 'downloads_enabled_fallback')
                    ->writtenBy(SetDownloadsPermission::class))
                ->related(fn (Job $job) => $job->listingCompatibilityState())
                ->related(relation: fn (Job $job) => $job->place(), successor: fn (Successor $place) => $place
                    ->attribute(name: 'place_id', definition: fn (Attribute $attribute) => $attribute->from('id')->readOnly(because: 'A Place is replaced, never re-keyed.', ignoreWrites: false))
                    ->related(relation: fn (Place $place) => $place->address()->withTrashed(), successor: fn (Successor $address) => $address
                        ->attribute(name: 'address_id', definition: fn (Attribute $attribute) => $attribute
                            ->from(column: 'id')
                            ->writtenBy(ChangeListingAddress::class))))
                ->related(relation: fn (Job $job) => $job->jobSite(), successor: fn (Successor $site) => $site
                    ->attribute(name: 'job_site_id', definition: fn (Attribute $attribute) => $attribute->from('id')->readOnly(because: 'A JobSite is replaced, never re-keyed.', ignoreWrites: false))))
            ->successorsInitializedBy(InitializeListingJob::class)
            ->legacyInitializedBy(InitializeLegacyListing::class)
            ->writesWithin(ListingWriteScope::class)
            ->dependents(ListingDependents::class);
    }
}
