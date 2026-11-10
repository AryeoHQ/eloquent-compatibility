<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use LogicException;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\Fixed;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\ReadAs;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;

final readonly class MassWriter
{
    private const string TYPED = 'pg_temp.compatibility_typed';

    private UnitOfWork $unit;

    public function __construct(private ResolvedMap $map, private Connection $connection)
    {
        $this->unit = new UnitOfWork(
            map: $map,
            connection: $connection,
        );
    }

    public static function computesInSql(mixed $value): bool
    {
        return $value instanceof Expression || $value instanceof QueryBuilder || $value instanceof Builder || $value instanceof Relation || $value instanceof Closure;
    }

    public static function refuseRaw(ResolvedMap $map, string $name, mixed $value): void
    {
        if (! self::computesInSql($value)) {
            return;
        }

        $attribute = $map->attributes[$name] ?? null;
        $table = $attribute === null ? $map->primary->table : $map->owners[$name]->table;

        throw CompatibilityWriteRefused::rawExpression($map->model, $name, $attribute !== null && $attribute->conversions() !== []
            ? 'Its conversions read stored values, and SQL written for the legacy value does not pass through them.'
            : "It is stored in {$table}, and SQL written for the legacy table would read the columns of {$table} instead.");
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $write
     * @return TResult
     */
    public function transaction(Closure $write): mixed
    {
        return $this->unit->transaction($write);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, float|int|numeric-string>  $amounts
     */
    public function update(QueryBuilder $keys, array $values, array $amounts = [], null|string $alias = null): int
    {
        [$mapped, $retained] = $this->split($values);
        [$added, $retainedAmounts] = $this->amounts($amounts);
        $added = array_diff_key($added, $mapped);

        return $this->each($keys, function (array $records, array $roots) use ($mapped, $retained, $added, $retainedAmounts, $alias): int {
            $primary = $this->map->primary;
            (new RetainedRows(
                map: $this->map,
                connection: $this->connection,
            ))->update(
                roots: $this->connection->table($primary->table)->whereIn($primary->table.'.'.$primary->key, array_keys($roots))->select($primary->table.'.'.$primary->key),
                values: $retained,
                amounts: array_diff_key($retainedAmounts, $retained),
                alias: $alias,
            );
            $writer = new RecordWriter(
                map: $this->map,
                connection: $this->connection,
            );
            $written = 0;
            $keyName = (new $this->map->model)->getKeyName();
            $renamed = $retained[$keyName] ?? null;
            $stored = $this->retainedValues(
                roots: array_keys($roots),
                names: [...array_keys($retained), ...array_keys(array_diff_key($retainedAmounts, $retained))],
            );
            $rootOf = array_flip($roots);

            foreach ($records as $key => [$original, $graph]) {
                $values = is_int($renamed) || is_string($renamed) ? [...$mapped, $keyName => $renamed] : $mapped;
                $after = clone $original;
                $after->setRawAttributes(array_replace($original->getAttributes(), $stored[(string) ($rootOf[$key] ?? '')] ?? [], array_filter(
                    array: $mapped,
                    callback: fn (int|string $name) => ! str_contains((string) $name, '->'),
                    mode: ARRAY_FILTER_USE_KEY,
                )));

                foreach ($added as $name => $amount) {
                    $before = $original->getAttributes()[$name] ?? null;

                    if (is_numeric($before) && ! $this->map->attributes[$name]->ignoresWrites()) {
                        $after->setAttribute(
                            key: $name,
                            value: (string) BigDecimal::of(self::number($before))->plus(self::number($amount)),
                        );
                    }
                }

                if ($added !== []) {
                    $this->unit->assign(
                        graph: $graph,
                        record: $after,
                        original: $original,
                        values: $mapped,
                        amounts: $added,
                        origin: Origin::Live,
                        creating: false,
                    );
                    $renumbered = $retainedAmounts[$original->getKeyName()] ?? null;
                    $written += $writer->finish(
                        legacy: $after,
                        key: $key,
                        graph: $graph,
                        original: $original,
                        values: is_int($key) && is_int($renumbered) ? [...$values, $original->getKeyName() => $key + $renumbered] : $values,
                        mapped: $mapped,
                        added: $added,
                    );

                    continue;
                }

                $written += $writer->apply(
                    legacy: $after,
                    key: $key,
                    graph: $graph,
                    original: $original,
                    chosen: $writer->chosen(
                        legacy: $after,
                        original: $original,
                        graph: $graph,
                        mapped: $mapped,
                        added: [],
                    ),
                    values: $values,
                    mapped: $mapped,
                );
            }

            return $written;
        });
    }

    public function delete(QueryBuilder $keys): int
    {
        return $this->each($keys, function (array $records): int {
            $deleted = 0;

            foreach ($records as [$original, $graph]) {
                $root = $graph->root();
                $this->unit->run($original, $root, fn () => $root->forceDelete());
                $deleted++;
            }

            return $deleted;
        });
    }

    /**
     * @param  Closure(array<int|string, array{Model, Staging<Model>}>, array<int|string, int|string>): int  $write
     */
    private function each(QueryBuilder $keys, Closure $write): int
    {
        return $this->unit->transaction(function () use ($keys, $write): int {
            $legacyKeys = $this->legacyKeys($keys);

            if ($legacyKeys === []) {
                return 0;
            }

            $rootKeys = $this->rootKeys($legacyKeys);
            $writer = new RecordWriter(
                map: $this->map,
                connection: $this->connection,
            );
            $chunks = array_chunk(
                array: $legacyKeys,
                length: UnitOfWork::CHUNK,
            );
            $scoped = [];

            if ($this->unit->scopes()) {
                foreach ($chunks as $index => $chunk) {
                    $scoped[$index] = $this->unit->touched($this->graphsOf(
                        writer: $writer,
                        roots: $this->unit->roots(
                            keys: self::rootsOf(
                                rootKeys: $rootKeys,
                                chunk: $chunk,
                            ),
                            lock: false,
                        ),
                        lock: false,
                    ));
                }
            }

            return $this->unit->scoped(touched: UnitOfWork::merged($scoped), write: function () use ($keys, $rootKeys, $chunks, $scoped, $writer, $write): int {
                $this->unit->lock(array_values(array_unique(
                    array: $rootKeys,
                    flags: SORT_REGULAR,
                )));
                $written = 0;

                foreach ($chunks as $index => $chunk) {
                    $locked = $this->graphsOf(
                        writer: $writer,
                        roots: $this->unit->roots(
                            keys: self::rootsOf(
                                rootKeys: $rootKeys,
                                chunk: $chunk,
                            ),
                            lock: false,
                        ),
                        lock: true,
                    );
                    $this->unit->refuseMoved(
                        scoped: $scoped[$index] ?? [],
                        graphs: $locked,
                    );
                    $legacyKeys = $this->legacyKeys(
                        keys: $keys->cloneWithout(['orders', 'limit', 'offset'])->cloneWithoutBindings(['order']),
                        within: $chunk,
                    );
                    $originals = $this->originals($legacyKeys);
                    $records = [];
                    $held = [];

                    foreach ($legacyKeys as $key) {
                        $root = $rootKeys[$key] ?? null;
                        $original = $originals[$key] ?? null;

                        if ($root === null || $original === null || ! array_key_exists($root, $locked)) {
                            continue;
                        }

                        $records[$key] = [$original, $locked[$root]];
                        $held[$key] = $root;
                    }

                    $written += $records === [] ? 0 : $write($records, array_flip(array_map(strval(...), $held)));
                }

                return $written;
            });
        });
    }

    /**
     * @param  array<int|string, Model>  $roots
     * @return array<int|string, Staging<Model>>
     */
    private function graphsOf(RecordWriter $writer, array $roots, bool $lock): array
    {
        return $this->unit->graphs(array_map(
            callback: fn (Model $root) => [$writer->variantOf($root), $root],
            array: $roots,
        ), lock: $lock);
    }

    /**
     * @param  array<int|string, int|string>  $rootKeys
     * @param  list<int|string>  $chunk
     * @return list<int|string>
     */
    private static function rootsOf(array $rootKeys, array $chunk): array
    {
        $roots = array_values(array_unique(
            array: array_intersect_key($rootKeys, array_flip($chunk)),
            flags: SORT_REGULAR,
        ));
        sort($roots);

        return $roots;
    }

    /**
     * @param  list<int|string>  $roots
     * @param  list<int|string>  $names
     * @return array<string, array<string, mixed>>
     */
    private function retainedValues(array $roots, array $names): array
    {
        $columns = array_values(array_unique(array_map(
            callback: fn (int|string $name) => Str::before((string) $name, '->'),
            array: $names,
        )));

        if ($columns === [] || $roots === []) {
            return [];
        }

        $retained = ViewCompiler::retainedTable($this->map);
        $values = [];

        foreach (array_chunk(array: $roots, length: UnitOfWork::CHUNK) as $chunk) {
            foreach ($this->connection->table($retained)->useWritePdo()->whereIn($retained.'.'.$this->map->successorKey, $chunk)->get([$this->map->successorKey, ...$columns]) as $row) {
                $row = (array) $row;
                $root = $row[$this->map->successorKey] ?? null;

                if (is_int($root) || is_string($root)) {
                    $values[(string) $root] = array_intersect_key($row, array_flip($columns));
                }
            }
        }

        return $values;
    }

    /**
     * @param  null|list<int|string>  $within
     * @return list<int|string>
     */
    private function legacyKeys(QueryBuilder $keys, null|array $within = null): array
    {
        $key = (new $this->map->model)->getKeyName();
        $query = $this->connection->query()->fromSub($keys, 'compatibility_keys')->distinct()->useWritePdo()->orderBy('compatibility_keys.'.$key);

        if ($within !== null) {
            $query->whereIn('compatibility_keys.'.$key, $within);
        }

        return array_values(array_filter(
            array: $query->pluck('compatibility_keys.'.$key)->all(),
            callback: fn (mixed $value) => is_int($value) || is_string($value),
        ));
    }

    /**
     * @param  list<int|string>  $legacyKeys
     * @return array<int|string, int|string>
     */
    private function rootKeys(array $legacyKeys): array
    {
        $retained = ViewCompiler::retainedTable($this->map);
        $key = (new $this->map->model)->getKeyName();
        $roots = [];

        foreach (array_chunk($legacyKeys, UnitOfWork::CHUNK) as $chunk) {
            foreach ($this->connection->table($retained)->useWritePdo()->whereIn($retained.'.'.$key, $chunk)->whereNotNull($retained.'.'.$this->map->successorKey)->pluck($retained.'.'.$this->map->successorKey, $retained.'.'.$key) as $legacy => $root) {
                if (is_int($root) || is_string($root)) {
                    $roots[$legacy] = $root;
                }
            }
        }

        return $roots;
    }

    /**
     * @param  list<int|string>  $legacyKeys
     * @return array<int|string, Model>
     */
    private function originals(array $legacyKeys): array
    {
        $legacy = (new $this->map->model)->setConnection($this->connection->getName());
        $originals = [];

        foreach (array_chunk($legacyKeys, UnitOfWork::CHUNK) as $chunk) {
            foreach ($this->connection->table($this->map->table)->useWritePdo()->whereIn($legacy->getKeyName(), $chunk)->get() as $row) {
                $original = $legacy->newInstance([], true)->setRawAttributes((array) $row, true);
                $originals[UnitOfWork::keyOf($original)] = $original;
            }
        }

        ExternalAttributes::gather(array_values($originals), true);

        return $originals;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function split(array $values): array
    {
        $mapped = [];
        $retained = [];

        foreach ($values as $key => $value) {
            [$name, $path] = array_pad(explode('->', (string) $key, 2), 2, null);
            $name = Str::afterLast($name, '.');
            $within = $path === null ? '' : '->'.$path;
            $attribute = $this->map->attributes[$name] ?? null;

            if ($attribute === null && $name === $this->map->successorKey) {
                throw CompatibilityWriteRefused::readOnly(
                    model: $this->map->model,
                    attribute: $name,
                    key: null,
                    because: 'The successor key binds each record to its '.class_basename($this->map->primary->model).', and a write that changes it would serve another root under the same legacy identity.',
                );
            }

            if ($attribute === null) {
                $retained[$name.$within] = $value;

                continue;
            }

            self::refuseRaw($this->map, $name, $value);

            if ($path !== null && ($attribute->column() === null || $attribute->conversions() !== [] || ! $attribute->invertible() || $attribute->operation() !== null)) {
                throw CompatibilityWriteRefused::unsupported($this->map->model, "a JSON path write of \${$name}", 'Its read is not a plain column, so a path inside the legacy value has no one stored path.');
            }

            $mapped[$name.$within] = $value;
        }

        return [$mapped, $retained];
    }

    /**
     * @param  array<array-key, float|int|numeric-string>  $amounts
     * @return array{array<string, float|int>, array<string, float|int>}
     */
    private function amounts(array $amounts): array
    {
        $added = [];
        $retained = [];

        foreach ($amounts as $name => $amount) {
            $name = Str::afterLast((string) $name, '.');
            $amount += 0;

            if ($name === $this->map->successorKey) {
                throw CompatibilityWriteRefused::readOnly(
                    model: $this->map->model,
                    attribute: $name,
                    key: null,
                    because: 'The successor key binds each record to its '.class_basename($this->map->primary->model).', and a write that changes it would serve another root under the same legacy identity.',
                );
            }

            $attribute = $this->map->attributes[$name] ?? null;

            if ($attribute !== null) {
                UnitOfWork::refuseIncrement(
                    map: $this->map,
                    attribute: $attribute,
                    name: $name,
                    key: null,
                );
                $added[$name] = $amount;
            } else {
                $retained[$name] = $amount;
            }
        }

        return [$added, $retained];
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public function typed(array $values, null|int|string $key = null): array
    {
        if ($values === []) {
            return [];
        }

        $grammar = $this->connection->getQueryGrammar();
        $typed = $grammar->wrapTable(self::TYPED);
        $retained = ViewCompiler::retainedTable($this->map);
        $retainedTable = SqlFragments::identifier($retained);
        $legacy = SqlFragments::identifier($this->map->table);
        $shape = [];
        $columns = [];
        $selects = [];
        $bindings = [];

        foreach (array_keys($values) as $index => $name) {
            $value = $values[$name];
            $column = $grammar->wrap('value_'.$index);
            $shape[] = $retainedTable.'.'.SqlFragments::identifier(Str::afterLast((string) $name, '.')).' AS '.$column;
            $columns[] = $column;

            if ($value instanceof Expression) {
                $selects[] = '('.(string) $value->getValue($grammar).')';

                continue;
            }

            $selects[] = '?';
            $bindings[] = $value;
        }

        $from = $key === null ? '' : ' FROM '.$legacy.' WHERE '.$legacy.'.'.SqlFragments::identifier((new $this->map->model)->getKeyName()).' = ?';
        $this->connection->statement('CREATE TEMPORARY TABLE '.$typed.' ON COMMIT DROP AS SELECT '.implode(', ', $shape).' FROM '.$retainedTable.' WITH NO DATA');
        $rows = $this->connection->select(
            'INSERT INTO '.$typed.' ('.implode(', ', $columns).') SELECT '.implode(', ', $selects).$from.' RETURNING '.implode(', ', $columns),
            [...$this->connection->table($retained)->cleanBindings($bindings), ...($key === null ? [] : [$key])],
        );
        $this->connection->statement('DROP TABLE '.$typed);

        return array_combine(array_keys($values), array_values((array) ($rows[0] ?? throw new LogicException("{$this->map->table} serves no row {$key} for the write."))));
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return null|array<string, mixed>
     */
    public function served(array $values, bool $fixed = false): null|array
    {
        $grammar = $this->connection->getQueryGrammar();
        $catalog = new ColumnCatalog($this->connection, throughSearchPath: true);
        $view = $catalog->of($this->map->table);
        $inputs = [];
        $selects = [];
        $bindings = [];

        $named = self::named($values);

        foreach ($named as $name => $value) {
            $attribute = $this->map->attributes[$name] ?? null;

            if ($attribute === null || $attribute->isExternal() || $value instanceof Expression || ($attribute->conversions()[0] ?? null) instanceof ReadAs || ! array_key_exists($name, $view)) {
                continue;
            }

            try {
                $stored = $attribute->write($value);
            } catch (LogicException) {
                return null;
            }

            $table = $this->map->owners[$name]->table;
            $column = $attribute->column();
            $target = $column === null ? null : ($catalog->of($table)[$column] ?? null);
            $type = $target === null ? null : $target->baseType ?? $target->type;
            $read = $attribute->read($table, $type);
            $input = $grammar->wrap('value_'.count($selects));

            if ($column !== null) {
                $inputs[] = 'CAST(? AS '.($target === null ? 'text' : $target->type).') AS '.$input;
                $bindings[] = $stored;
                $read = str_replace(SqlFragments::identifier($table).'.'.SqlFragments::identifier($column), 'input.'.$input, $read);
            }

            $selects[$name] = ViewCompiler::cast($read, $attribute->type($type), $view[$name]->type, $view[$name]->baseType).' AS '.$grammar->wrap($name);
        }

        foreach ($fixed ? $this->map->attributes : [] as $name => $attribute) {
            if (! array_key_exists($name, $named) && ($attribute->conversions()[0] ?? null) instanceof Fixed && array_key_exists($name, $view)) {
                $selects[$name] = ViewCompiler::cast($attribute->read($this->map->owners[$name]->table), $attribute->type(null), $view[$name]->type, $view[$name]->baseType).' AS '.$grammar->wrap($name);
            }
        }

        if ($selects === []) {
            return [];
        }

        try {
            $row = $this->connection->transaction(fn () => $this->connection->selectOne('SELECT '.implode(', ', $selects).($inputs === [] ? '' : ' FROM (SELECT '.implode(', ', $inputs).') AS input'), $this->connection->table($this->map->table)->cleanBindings($bindings)));
        } catch (QueryException $exception) {
            if (str_starts_with((string) $exception->getCode(), '22')) {
                return null;
            }

            throw $exception;
        }

        return (array) $row;
    }

    private static function number(int|float|string $value): string
    {
        return is_float($value) ? json_encode(value: $value, flags: JSON_THROW_ON_ERROR) : (string) $value;
    }

    /**
     * @template TValue
     *
     * @param  array<array-key, TValue>  $values
     * @return array<string, TValue>
     */
    private static function named(array $values): array
    {
        $named = [];

        foreach ($values as $name => $value) {
            $named[Str::afterLast((string) $name, '.')] = $value;
        }

        return $named;
    }
}
