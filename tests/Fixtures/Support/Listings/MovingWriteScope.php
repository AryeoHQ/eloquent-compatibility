<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Closure;
use Illuminate\Support\Facades\DB;
use Support\Database\Eloquent\Compatibility\Contracts\ScopesWrites;

final class MovingWriteScope implements ScopesWrites
{
    public function run(array $successors, Closure $write): mixed
    {
        DB::table('job_sites')->whereIn(
            column: 'job_id',
            values: $successors[Job::class] ?? [],
        )->delete();

        return $write();
    }
}
