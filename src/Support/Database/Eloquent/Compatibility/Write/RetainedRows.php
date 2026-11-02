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
    public function upsertAll(QueryBuilder $keys, array $values, array $amounts): void
    {
        if ($values === [] && $amounts === []) {
            return;
        }

        $retained = ViewCompiler::retainedTable($this->map);
        $table = SqlFragments::identifier($retained);
        $primary = SqlFragments::identifier($this->map->primary->table);
        $primaryKey = $primary.'.'.SqlFragments::identifier($this->map->primary->key);
        $legacy = SqlFragments::identifier($this->map->table);
        $catalog = new ColumnCatalog($this->connection);
        $grammar = $this->connection->table($retained)->getGrammar();
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
        $source = $joined
            ? '(SELECT '.$primaryKey.' FROM '.$primary.' WHERE '.$identifier.' = '.$legacyKey.'), '.implode(', ', $selects).' FROM '.$legacy.' WHERE '.$legacyKey.' IN (SELECT '.$identifier.' FROM '.$primary.' WHERE '.$keyed.')'
            : implode(', ', [$primaryKey, ...$selects]).' FROM '.$primary.' WHERE '.$keyed;
        $retainedKey = SqlFragments::identifier((string) $this->map->retainedKey);

        $this->connection->affectingStatement(
            'INSERT INTO '.$table.' ('.implode(', ', [$retainedKey, ...array_map(fn (string $column) => SqlFragments::identifier($column), [...array_keys($values), ...array_keys($amounts)])]).')'
                .' SELECT '.$source
                .' ON CONFLICT ('.$retainedKey.') DO UPDATE SET '.implode(', ', $updates),
            [...$bindings, ...$keys->getBindings()],
        );
    }
}
