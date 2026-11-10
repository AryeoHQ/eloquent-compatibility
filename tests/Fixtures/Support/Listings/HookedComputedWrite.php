<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Write\Change;
use Support\Database\Eloquent\Compatibility\Write\Successors;

/**
 * @implements WritesAttribute<Job>
 */
final readonly class HookedComputedWrite implements WritesAttribute
{
    public function __construct(private RecordsHooks $hooks) {}

    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $this->hooks->record(self::class);
    }
}
