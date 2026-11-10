<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Closure;
use Support\Database\Eloquent\Compatibility\Contracts\ScopesWrites;

final readonly class HookedWriteScope implements ScopesWrites
{
    public function __construct(private RecordsHooks $hooks) {}

    public function run(array $successors, Closure $write): mixed
    {
        $this->hooks->record(self::class);

        return $write();
    }
}
