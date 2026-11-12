<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\WorkedListing;

use Closure;
use Support\Database\Eloquent\Compatibility\Contracts\ScopesWrites;

final class ListingWriteScope implements ScopesWrites
{
    public function run(array $successors, Closure $write): mixed
    {
        return JobWriteTransaction::run(jobs: $successors[Job::class] ?? [], write: $write);
    }
}
