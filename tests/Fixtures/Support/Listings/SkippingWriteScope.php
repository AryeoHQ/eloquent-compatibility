<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Closure;
use Support\Database\Eloquent\Compatibility\Contracts\ScopesWrites;

final class SkippingWriteScope implements ScopesWrites
{
    public function run(array $successors, Closure $write): mixed
    {
        return 0;
    }
}
