<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Closure;
use Illuminate\Support\Facades\DB;
use Support\Database\Eloquent\Compatibility\Contracts\ScopesWrites;

final class RecordingWriteScope implements ScopesWrites
{
    /** @var list<array<string, list<int|string>>> */
    public static array $received = [];

    public function run(array $successors, Closure $write): mixed
    {
        self::$received[] = $successors;
        DB::select("select 'the write scope' as marker");

        return $write();
    }
}
