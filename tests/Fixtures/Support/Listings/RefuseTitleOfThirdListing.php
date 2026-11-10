<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Refusal;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @implements WritesAttribute<Job>
 */
final class RefuseTitleOfThirdListing implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        if (in_array($change->original->getKey(), [3, null], true)) {
            throw Refusal::because('A title set in v2 cannot change through v1.');
        }

        $successors->root()->setAttribute(
            key: 'name',
            value: is_string($value) ? $value : null,
        );
    }
}
