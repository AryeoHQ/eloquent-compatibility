<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Listings;

use Closure;
use Illuminate\Support\Facades\DB;
use Support\Database\Eloquent\Compatibility\Contracts\ScopesWrites;

final class TransactionRecordingWriteScope implements ScopesWrites
{
    /** @var list<array{transaction: string, ledger: int}> */
    public static array $received = [];

    public function run(array $successors, Closure $write): mixed
    {
        self::$received[] = [
            'transaction' => (string) DB::scalar('select txid_current()'),
            'ledger' => (int) DB::scalar('select count(*) from listings_backfill'),
        ];

        return $write();
    }
}
