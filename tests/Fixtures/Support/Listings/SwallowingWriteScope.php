<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Closure;
use Support\Database\Eloquent\Compatibility\Contracts\ScopesWrites;
use Throwable;

final class SwallowingWriteScope implements ScopesWrites
{
    public function run(array $successors, Closure $write): mixed
    {
        try {
            return $write();
        } catch (Throwable) {
            return 0;
        }
    }
}
