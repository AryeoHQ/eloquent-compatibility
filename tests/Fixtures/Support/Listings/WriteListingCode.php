<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @implements WritesAttribute<Job>
 */
final class WriteListingCode implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $successors->root()->setAttribute(
            key: 'payload',
            value: $value === null ? null : json_encode(
                value: ['code' => $value],
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }
}
