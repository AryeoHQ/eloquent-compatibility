<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @implements WritesAttribute<Job>
 */
final class CompactListingSettings implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $successors->root()->setAttribute(
            key: 'settings',
            value: is_string($value) ? json_encode(
                value: json_decode(json: $value),
                flags: JSON_THROW_ON_ERROR,
            ) : $value,
        );
    }
}
