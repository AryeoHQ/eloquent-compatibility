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
final class NameJobFromTitle implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        if (! is_string($value) || trim($value) === '') {
            throw Refusal::because('A job needs a name, and the listing title is blank.');
        }

        $successors->root()->setAttribute(
            key: 'name',
            value: $value,
        );
    }
}
