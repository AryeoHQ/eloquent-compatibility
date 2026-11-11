<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\WorkedListing;

use Closure;
use Illuminate\Support\Facades\DB;

final class JobWriteTransaction
{
    /** @var list<list<int|string>> */
    public static array $received = [];

    /**
     * @template TResult
     *
     * @param  list<int|string>  $jobs
     * @param  Closure(): TResult  $write
     * @return TResult
     */
    public static function run(array $jobs, Closure $write): mixed
    {
        self::$received[] = $jobs;

        return DB::transaction(callback: function () use ($jobs, $write): mixed {
            Job::withTrashed()->whereKey($jobs)->lockForUpdate()->get();

            return $write();
        });
    }
}
