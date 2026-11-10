<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @implements WritesAttribute<Job>
 */
final class WriteTitleSeeingRecord implements WritesAttribute
{
    /** @var list<array<string, mixed>> */
    public static array $records = [];

    public function write(Successors $successors, mixed $value, Change $change): void
    {
        self::$records[] = $change->record->getAttributes();
        $successors->root()->setAttribute('name', is_string($value) ? $value : null);
    }
}
