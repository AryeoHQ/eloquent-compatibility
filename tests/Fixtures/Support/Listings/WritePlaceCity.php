<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @implements WritesAttribute<Job>
 */
final class WritePlaceCity implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $city = is_string($value) ? $value : null;
        ($city === null ? $successors->related(Place::class) : $successors->relatedOrNew(Place::class))?->setAttribute(
            key: 'city',
            value: $city,
        );
    }
}
