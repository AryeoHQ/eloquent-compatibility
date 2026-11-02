<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;

final readonly class RetainedRows
{
    public function __construct(private ResolvedMap $map, private ConnectionInterface $connection) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public function upsert(int|string $key, array $values): void
    {
        if ($values === []) {
            return;
        }

        $retainedKey = (string) $this->map->retainedKey;

        $this->connection->table(ViewCompiler::retainedTable($this->map))->upsert([[$retainedKey => $key, ...$values]], [$retainedKey], array_keys($values));
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, float|int>  $amounts
     */
    public function upsertAll(QueryBuilder $keys, array $values, array $amounts, null|string $alias = null): void
    {
        $retained = ViewCompiler::retainedTable($this->map);
        $paths = array_filter($values, fn (string $column) => str_contains($column, '->'), ARRAY_FILTER_USE_KEY);
        $values = array_diff_key($values, $paths);

        if ($paths !== []) {
            $this->connection->table($retained)->whereIn((string) $this->map->retainedKey, $keys)->update($paths);
        }

        if ($values === [] && $amounts === []) {
            return;
        }

        $table = SqlFragments::identifier($retained);
        $primary = SqlFragments::identifier($this->map->primary->table);
        $primaryKey = $primary.'.'.SqlFragments::identifier($this->map->primary->key);
        $alias ??= $this->map->table;
        $legacy = SqlFragments::identifier($alias);
        $from = SqlFragments::identifier($this->map->table).($alias === $this->map->table ? '' : ' AS '.$legacy);
        $catalog = new ColumnCatalog($this->connection, throughSearchPath: true);
        $query = $this->connection->table($retained);
        $grammar = $query->getGrammar();
        $selects = [];
        $updates = [];
        $bindings = [];
        $joined = $amounts !== [];

        foreach ($values as $column => $value) {
            $wrapped = SqlFragments::identifier($column);
            $updates[] = $wrapped.' = excluded.'.$wrapped;

            if ($value instanceof Expression) {
                $selects[] = (string) $value->getValue($grammar);
                $joined = true;

                continue;
            }

            $selects[] = 'CAST(? AS '.($catalog->type($retained, $column) ?? 'text').')';
            $bindings[] = $value;
        }

        foreach ($amounts as $column => $amount) {
            $wrapped = SqlFragments::identifier($column);
            $selects[] = $legacy.'.'.$wrapped.' + '.$amount;
            $updates[] = $wrapped.' = '.$table.'.'.$wrapped.' + '.$amount;
        }

        $key = (new $this->map->model)->getKeyName();
        $legacyKey = $legacy.'.'.SqlFragments::identifier($key);
        $identifier = $primary.'.'.SqlFragments::identifier((string) $this->map->identifiers[$key]->column());
        $keyed = $primaryKey.' IN ('.$keys->toSql().')';
        $member = $this->map->membership === null ? '' : ' AND '.$this->map->membership->of($this->map->primary->table);
        $source = $joined
            ? '(SELECT '.$primaryKey.' FROM '.$primary.' WHERE '.$identifier.' = '.$legacyKey.$member.'), '.implode(', ', $selects).' FROM '.$from.' WHERE '.$legacyKey.' IN (SELECT '.$identifier.' FROM '.$primary.' WHERE '.$keyed.')'
            : implode(', ', [$primaryKey, ...$selects]).' FROM '.$primary.' WHERE '.$keyed;
        $retainedKey = SqlFragments::identifier((string) $this->map->retainedKey);

        $this->connection->affectingStatement(
            'INSERT INTO '.$table.' ('.implode(', ', [$retainedKey, ...array_map(fn (string $column) => SqlFragments::identifier($column), [...array_keys($values), ...array_keys($amounts)])]).')'
                .' SELECT '.$source
                .' ON CONFLICT ('.$retainedKey.') DO UPDATE SET '.implode(', ', $updates),
            [...$query->cleanBindings($bindings), ...$keys->getBindings()],
        );
    }
}
