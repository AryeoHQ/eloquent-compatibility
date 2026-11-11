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
final class RefuseWithCutTitle implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        throw Refusal::because('The title '.substr(
            string: is_string($value) ? $value : '',
            offset: 0,
            length: 1,
        ).' is cut.');
    }
}
