<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\WorkedListing;

use Support\Database\Eloquent\Compatibility\Contracts\InitializesSuccessors;
use Support\Database\Eloquent\Compatibility\Write\Creation;
use Support\Database\Eloquent\Compatibility\Write\Origin;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @implements InitializesSuccessors<Job>
 */
final class InitializeListingJob implements InitializesSuccessors
{
    public function initialize(Successors $successors, Creation $creation): void
    {
        $listing = $creation->record;
        $job = $successors->root();
        $job->name = $listing->title ?: 'Legacy listing '.$listing->uuid;
        $job->opted_in_to_zma = false;
        $job->payment_status = $creation->origin === Origin::Backfill
            ? PaymentStatus::fromOrders($listing->orders)
            : PaymentStatus::Unpaid;
    }
}
