<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Closure;
use Support\Database\Eloquent\Compatibility\Contracts\ScopesWrites;

final class InterleavingWriteScope implements ScopesWrites
{
    /** @var null|Closure(array<string, list<int|string>>): void */
    public static null|Closure $interleave = null;

    /** @var list<array<string, list<int|string>>> */
    public static array $received = [];

    public function run(array $successors, Closure $write): mixed
    {
        self::$received[] = $successors;

        if (self::$interleave !== null) {
            (self::$interleave)($successors);
        }

        return $write();
    }
}
