<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @implements WritesAttribute<Job>
 */
final class ReachBeyondForwardedJob implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        if ($value === 'place') {
            $successors->related(Place::class);

            return;
        }

        $successors->afterSave(fn () => $successors->root()->touch());
    }
}
