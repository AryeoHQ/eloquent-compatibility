<?php

declare(strict_types=1);

namespace Tests\Fixtures\Support\Backfills;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;

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

    /**
     * @param  class-string<Model>  $model
     */
    public static function agree(string $model): void
    {
        $legacy = new $model;
        $connection = DB::connection($legacy->getConnectionName());
        $map = Compatibility::of($model)->resolve();
        $catalog = new ColumnCatalog(
            connection: $connection,
            throughWriteConnection: true,
        );
        $served = (new ViewCompiler(
            columnCatalog: $catalog,
            connection: $connection,
        ))->beforeInstall($map);
        $columns = $catalog->of($map->table);
        $key = SqlFragments::identifier($legacy->getKeyName());

        foreach (array_keys($map->attributes) as $name) {
            $column = $columns[$name] ?? null;

            if ($column === null || $column->generated || $map->isExternal($name)) {
                continue;
            }

            $wrapped = SqlFragments::identifier($name);

            try {
                $connection->transaction(fn () => $connection->update('UPDATE '.SqlFragments::identifier($map->table)." AS compatibility_legacy SET {$wrapped} = compatibility_served.{$wrapped} FROM ({$served}) AS compatibility_served WHERE compatibility_served.{$key} = compatibility_legacy.{$key}"));
            } catch (QueryException) {
                continue;
            }
        }
    }
}
