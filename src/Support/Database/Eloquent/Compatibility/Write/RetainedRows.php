<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;
use LogicException;
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
    public function insert(int|string $root, array $values): int|string
    {
        $key = (new $this->map->model)->getKeyName();
        $query = $this->connection->table(ViewCompiler::retainedTable($this->map));
        $row = [...$values, $this->map->successorKey => $root];
        $inserted = ((array) $this->connection->selectOne(
            query: $query->getGrammar()->compileInsertGetId(
                query: $query,
                values: $row,
                sequence: $key,
            ),
            bindings: $query->cleanBindings($row),
            useReadPdo: false,
        ))[$key] ?? null;

        if ($this->connection instanceof Connection) {
            $this->connection->recordsHaveBeenModified();
        }

        return is_int($inserted) || is_string($inserted) ? $inserted : throw new LogicException(ViewCompiler::retainedTable($this->map)." returned no {$key} for the new ".class_basename($this->map->model).'.');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function set(int|string $key, array $values): void
    {
        if ($values === []) {
            return;
        }

        $this->connection->table(ViewCompiler::retainedTable($this->map))
            ->where(column: (new $this->map->model)->getKeyName(), operator: '=', value: $key)
            ->update($values);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, float|int>  $amounts
     */
    public function update(QueryBuilder $roots, array $values, array $amounts, null|string $alias = null): void
    {
        if ($values === [] && $amounts === []) {
            return;
        }

        $retained = ViewCompiler::retainedTable($this->map);
        $table = SqlFragments::identifier($retained);
        $alias ??= $this->map->table;
        $legacy = SqlFragments::identifier($alias);
        $from = SqlFragments::identifier($this->map->table).($alias === $this->map->table ? '' : ' AS '.$legacy);
        $successorKey = SqlFragments::identifier($this->map->successorKey);
        $catalog = new ColumnCatalog($this->connection, throughSearchPath: true);
        $query = $this->connection->table($retained);
        $grammar = $query->getGrammar();
        $columns = [];
        $selects = [];
        $bindings = [];

        foreach ($values as $key => $value) {
            $column = Str::before($key, '->');
            $columns[] = SqlFragments::identifier($column);

            if ($column !== $key) {
                $selects[] = Str::after($grammar->compileUpdate($query, [$key => $value]), ' set '.$grammar->wrap($column).' = ');
                $bindings = [...$bindings, ...$grammar->prepareBindingsForUpdate([], [$key => $value])];

                continue;
            }

            if ($value instanceof Expression) {
                $selects[] = (string) $value->getValue($grammar);

                continue;
            }

            $selects[] = 'CAST(? AS '.($catalog->type($retained, $column) ?? 'text').')';
            $bindings = [...$bindings, ...$grammar->prepareBindingsForUpdate([], [$key => $value])];
        }

        foreach ($amounts as $column => $amount) {
            $columns[] = SqlFragments::identifier($column);
            $selects[] = $legacy.'.'.SqlFragments::identifier($column).' + '.$amount;
        }

        $names = array_map(
            callback: fn (int $index) => 'compatibility_value_'.$index,
            array: array_keys($columns),
        );

        $this->connection->affectingStatement(
            'UPDATE '.$table.' SET '.implode(
                separator: ', ',
                array: array_map(fn (string $column, string $name) => $column.' = source.'.$name, $columns, $names),
            )
                .' FROM (SELECT '.$legacy.'.'.$successorKey.' AS compatibility_root, '.implode(
                    separator: ', ',
                    array: array_map(fn (string $select, string $name) => $select.' AS '.$name, $selects, $names),
                )
                .' FROM '.$from.' WHERE '.$legacy.'.'.$successorKey.' IN ('.$roots->toSql().')) AS source'
                .' WHERE '.$table.'.'.$successorKey.' = source.compatibility_root',
            [...$query->cleanBindings($bindings), ...$roots->getBindings()],
        );
    }
}
