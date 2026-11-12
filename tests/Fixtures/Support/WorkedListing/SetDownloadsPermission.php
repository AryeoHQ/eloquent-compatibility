<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\WorkedListing;

use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @implements WritesAttribute<Job>
 */
final class SetDownloadsPermission implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $job = $successors->root();

        if ($job->are_customer_downloads_enabled === null) {
            $successors->relatedOrNew(ListingCompatibilityState::class)->fill([
                'downloads_enabled_fallback' => (bool) ($value ?? true),
            ]);

            return;
        }

        $job->are_customer_downloads_enabled = (bool) $value;
    }
}
