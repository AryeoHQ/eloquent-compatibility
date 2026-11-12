<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\WorkedListing;

use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Refusal;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @implements WritesAttribute<Job>
 */
final class ChangeListingAddress implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        if ($value === null) {
            throw Refusal::because('A listing keeps its address.');
        }

        $address = Address::withTrashed()->findOrFail($value);
        $successors->relatedOrNew(Place::class)->fill(['address_id' => $address->uuid, 'name' => $address->label()]);
        $address->hasValidCoordinates()
            ? $successors->relatedOrNew(JobSite::class)->fill(['latitude' => $address->latitude, 'longitude' => $address->longitude])
            : $successors->deleteRelated(JobSite::class);
    }
}
