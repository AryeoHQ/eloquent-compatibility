<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Backfills;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

final class CompletedPass
{
    /**
     * @param  class-string<Model>  $model
     */
    public static function recordHandBackfill(string $model): void
    {
        $legacy = new $model;
        $recorded = DB::connection($legacy->getConnectionName())->update(
            query: 'update compatibility_backfills set watermark_at = now(), pass = pass + 1 where legacy_type = ?',
            bindings: [$legacy->getTable()],
        );

        if ($recorded !== 1) {
            throw new LogicException("compatibility_backfills holds no row for {$legacy->getTable()}, so no hand backfill can stand in for its pass. Run Compatibility::prepare() first.");
        }
    }
}
