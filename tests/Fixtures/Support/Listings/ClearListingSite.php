<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @implements WritesAttribute<Job>
 */
final class ClearListingSite implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $site = $successors->relatedOrNew(JobSite::class);

        if ($value === null) {
            $successors->deleteRelated(JobSite::class);

            return;
        }

        $site->setAttribute(
            key: 'latitude',
            value: $value,
        );
    }
}
