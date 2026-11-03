<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression as RawExpression;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use LogicException;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\DividedBy;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\Fixed;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\MultipliedBy;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\ReadAs;
use Support\Database\Eloquent\Compatibility\Definition\Related;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;

final readonly class MassWriter
{
    private const string PINNED = 'pg_temp.compatibility_pinned';

    private const string SELECTED = 'pg_temp.compatibility_selected';

    private const string REACHED = 'pg_temp.compatibility_reached';

    private const string EVALUATED = 'pg_temp.compatibility_evaluated';

    private const string TYPED = 'pg_temp.compatibility_typed';

    private const string HELD = 'pg_temp.compatibility_held';

    public function __construct(private ResolvedMap $map, private Connection $connection) {}

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
     * @param  array<array-key, mixed>  $amounts
     */
    public static function refuseIdentifierAmounts(ResolvedMap $map, array $amounts): void
    {
        foreach (array_keys(self::named($amounts)) as $name) {
            if (array_key_exists($name, $map->identifiers)) {
                throw CompatibilityWriteRefused::unsupported($map->model, "an increment of its identifier {$name}", 'An increment computes the new '.$name.' in SQL, and a record of '.implode(' or ', array_map(fn (ResolvedMap $branch) => class_basename($branch->primary->model), $map->branches)).' must claim its '.$name.' across both successors and move its retained values before it takes one.');
            }
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $write
     * @return TResult
     */
    public function transaction(Closure $write): mixed
    {
        return $this->connection->transaction(function () use ($write): mixed {
            $session = $this->connection->transactionLevel() > 1 ? $this->connection->scalar("select current_setting('TimeZone')") : null;
            $this->connection->select("select set_config('TimeZone', ?, true)", [Config::string('app.timezone', 'UTC')]);
            $result = $write();

            if (is_string($session)) {
                $this->connection->select("select set_config('TimeZone', ?, true)", [$session]);
            }

            return $result;
        });
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, float|int|numeric-string>  $amounts
     */
    public function update(QueryBuilder $keys, array $values, array $amounts = [], null|string $alias = null): int
    {
        if ($this->map->branches !== []) {
            return $this->updateBranches($keys, $values, $amounts, $alias ?? $this->map->table);
        }

        if ($this->map->morphs !== []) {
            return $this->updateMorphs($keys, $values, $amounts, $alias ?? $this->map->table);
        }

        [$stored, $retained] = $this->split($values);
        [$added, $retainedAmounts] = $this->amounts($amounts, null);

        return $this->transaction(function () use ($keys, $stored, $retained, $added, $retainedAmounts, $alias): int {
            $pinned = $this->pin($keys);

            if ($pinned > 0) {
                $primary = $this->map->primary;
                $related = array_values($this->map->related);
                $reached = array_filter($related, fn (Related $related) => $this->withAmounts($stored[$related->table] ?? [], $added[$related->table] ?? []) !== []) !== [];

                if ($reached) {
                    $this->capture();
                }

                (new RetainedRows($this->map, $this->connection))->upsertAll($this->pinned(), $retained, array_diff_key($retainedAmounts, $retained), $alias);
                $values = $this->withAmounts($stored[$primary->table] ?? [], $added[$primary->table] ?? []);

                if ($values !== []) {
                    $this->successor($primary->model)->newModelQuery()->whereIn($primary->table.'.'.$primary->key, $this->pinned())->update($values);
                }

                foreach ($related as $index => $table) {
                    $this->updateRelated($table, $index, $stored[$table->table] ?? [], $added[$table->table] ?? []);
                }

                if ($reached) {
                    $this->connection->statement('DROP TABLE '.$this->connection->getQueryGrammar()->wrapTable(self::REACHED));
                }
            }

            $this->connection->statement('DROP TABLE '.$this->connection->getQueryGrammar()->wrapTable(self::PINNED));

            return $pinned;
        });
    }

    public function delete(QueryBuilder $keys): int
    {
        if ($this->map->branches !== []) {
            return $this->acrossBranches($keys, function (QueryBuilder $selected): int {
                $deleted = 0;

                foreach ($this->map->branches as $branch) {
                    $deleted += (new self($branch, $this->connection))->delete($selected);
                }

                if ($this->map->retainedTable !== null) {
                    $this->connection->table($this->map->retainedTable)->whereIn((string) $this->map->retainedKey, $selected)->delete();
                }

                return $deleted;
            });
        }

        return $this->transaction(function () use ($keys): int {
            $primary = $this->map->primary;
            $deleted = $this->pin($keys) > 0 ? (int) $this->successor($primary->model)->newModelQuery()->whereIn($primary->table.'.'.$primary->key, $this->pinned())->delete() : 0;
            $this->connection->statement('DROP TABLE '.$this->connection->getQueryGrammar()->wrapTable(self::PINNED));

            return $deleted;
        });
    }

    /**
     * @param  array<array-key, float|int|numeric-string>  $amounts
     * @return array{array<string, array<string, float|int>>, array<string, float|int>}
     */
    public function amounts(array $amounts, null|int|string $key): array
    {
        $added = [];
        $retained = [];

        foreach ($amounts as $name => $amount) {
            $name = Str::afterLast((string) $name, '.');
            $amount += 0;

            if (array_key_exists($name, $this->map->identifiers)) {
                $added[$this->map->primary->table][(string) $this->map->identifiers[$name]->column()] = $amount;

                continue;
            }

            $attribute = $this->map->attributes[$name] ?? null;

            if ($attribute === null) {
                $retained[$name] = $amount;

                continue;
            }

            $this->refuse($name, $key);
            $conversions = $attribute->conversions();
            $column = $attribute->column();

            if ($column === null || array_filter($conversions, fn (object $conversion) => ! $conversion instanceof DividedBy && ! $conversion instanceof MultipliedBy) !== []) {
                throw CompatibilityWriteRefused::unsupported($this->map->model, "an increment of \${$name}", 'Its conversions are not linear, so an amount added to it has no one stored amount.');
            }

            $stored = $attribute->write($amount);
            $added[$this->map->owners[$name]->table][$column] = is_int($stored) || is_float($stored) ? $stored : throw new LogicException("Attribute '{$name}' stored no number for the amount {$amount}.");
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

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, float|int>  $amounts
     */
    public function retainByIdentity(QueryBuilder $keys, array $values, array $amounts, null|string $alias = null): void
    {
        if ($values === [] && $amounts === []) {
            return;
        }

        $retained = ViewCompiler::retainedTable($this->map);
        $table = SqlFragments::identifier($retained);
        $alias ??= $this->map->table;
        $legacy = SqlFragments::identifier($alias);
        $from = SqlFragments::identifier($this->map->table).($alias === $this->map->table ? '' : ' AS '.$legacy);
        $legacyKey = $legacy.'.'.SqlFragments::identifier((new $this->map->model)->getKeyName());
        $retainedKey = SqlFragments::identifier((string) $this->map->retainedKey);
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

        $source = $joined
            ? implode(', ', [$legacyKey, ...$selects]).' FROM '.$from.' WHERE '.$legacyKey.' IN ('.$keys->toSql().')'
            : implode(', ', ['selected.key', ...$selects]).' FROM ('.$keys->toSql().') AS selected (key)';

        $this->connection->affectingStatement(
            'INSERT INTO '.$table.' ('.implode(', ', [$retainedKey, ...array_map(fn (string $column) => SqlFragments::identifier($column), [...array_keys($values), ...array_keys($amounts)])]).')'
                .' SELECT '.$source
                .' ON CONFLICT ('.$retainedKey.') DO UPDATE SET '.implode(', ', $updates),
            [...$query->cleanBindings($bindings), ...$keys->getBindings()],
        );
    }

    /**
     * @param  array<string, mixed>  $identities
     */
    public function claim(array $identities, null|ResolvedMap $holder = null, null|int|string $except = null): void
    {
        $claimed = [];

        foreach ($this->map->identifiers as $name => $identifier) {
            $value = $identities[$name] ?? null;

            if ($value !== null) {
                self::refuseRaw($this->map, $name, $value);
                $this->lockIdentity($name, $value);
                $claimed[$name] = $value;
            }
        }

        $key = (new $this->map->model)->getKeyName();

        foreach ($this->map->branches as $branch) {
            if ($branch === $holder) {
                continue;
            }

            $table = $branch->primary->table;

            foreach ($claimed as $name => $value) {
                $taken = $this->connection->table($table)
                    ->where($table.'.'.(string) $branch->membership?->column, $branch->membership?->alias)
                    ->where($table.'.'.(string) $branch->identifiers[$name]->column(), $value);

                if ($except !== null) {
                    $taken->where($table.'.'.(string) $branch->identifiers[$key]->column(), '<>', $except);
                }

                if ($taken->exists()) {
                    $this->duplicate(class_basename($this->map->model)." {$name} ".(is_scalar($value) ? (string) $value : '')." is already taken in {$table}, and a ".class_basename($this->map->model)." {$name} is unique across ".implode(' and ', array_map(fn (ResolvedMap $branch) => $branch->primary->table, $this->map->branches)).'.');
                }
            }
        }
    }

    public function lockIdentity(string $name, mixed $value): void
    {
        $this->connection->select('select pg_advisory_xact_lock(hashtextextended(CAST(? AS text) || CAST(CAST(? AS '.$this->identityType($name).') AS text), 0))', [$this->identityLock($name), $value]);
    }

    private function identityLock(string $name): string
    {
        return ($this->map->membership->alias ?? $this->map->table).':'.$name.':';
    }

    private function identityType(string $name): string
    {
        return $this->map->identifiers[$name]->isUuid() ? 'uuid' : 'bigint';
    }

    private function duplicate(string $message): never
    {
        $this->connection->unprepared("DO \$compatibility\$\nBEGIN\n  RAISE EXCEPTION USING ERRCODE = 'unique_violation', MESSAGE = ".SqlFragments::literal($message).";\nEND\n\$compatibility\$");

        throw new LogicException($message);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, float|int|numeric-string>  $amounts
     */
    private function updateBranches(QueryBuilder $keys, array $values, array $amounts, string $alias): int
    {
        [$mapped, $retained] = $this->byOwner($values);
        [$mappedAmounts, $retainedAmounts] = $this->byOwner($amounts);

        foreach (self::named($mapped) as $name => $value) {
            if ($this->map->isExternal($name)) {
                throw CompatibilityWriteRefused::external($this->map->model, $name);
            }

            self::refuseRaw($this->map, $name, $value);
        }

        self::refuseIdentifierAmounts($this->map, $mappedAmounts);

        return $this->acrossBranches($keys, function (QueryBuilder $selected, array $holding) use ($values, $mapped, $retained, $mappedAmounts, $retainedAmounts, $alias): int {
            $added = array_map(fn (float|int|string $amount) => $amount + 0, array_diff_key(self::named($retainedAmounts), self::named($retained)));
            $exact = array_diff_key(self::named($mappedAmounts), self::named($mapped)) === [];
            $sums = [];

            foreach ($exact ? $added : [] as $column => $amount) {
                $sums[$column] = new RawExpression(SqlFragments::identifier($alias).'.'.SqlFragments::identifier($column).' + '.$amount);
            }

            $reads = $this->evaluateOnce($selected, $retained + $sums, $alias);
            $evaluated = array_intersect_key($reads, $retained);
            $literals = $this->typed(array_filter($retained, fn (mixed $value) => ! $value instanceof Expression));
            $values = array_replace($values, $literals, $evaluated);
            $retained = array_replace($retained, $literals, $evaluated);

            if ($exact) {
                $this->refuseMoves($selected, array_replace($values, array_intersect_key($reads, $sums)), $alias);
            }

            $identities = array_intersect_key(self::named($mapped), $this->map->identifiers);

            if ($identities !== []) {
                $this->claimAcross($selected, $identities);
            }

            $this->retainByIdentity($selected, self::named($retained), $added, $alias);
            $updated = 0;

            foreach ($holding as $branch) {
                $updated += (new self($branch, $this->connection))->update($selected, $mapped, $mappedAmounts);
            }

            $key = (new $this->map->model)->getKeyName();

            if (array_key_exists($key, $identities) && $this->map->retainedTable !== null) {
                $this->connection->table($this->map->retainedTable)->whereIn((string) $this->map->retainedKey, $selected)->update([(string) $this->map->retainedKey => $identities[$key]]);
            }

            if (! $exact) {
                $this->refuseMoved(array_key_exists($key, $identities) ? $this->connection->query()->selectRaw('CAST(? AS '.$this->identityType($key).')', [$identities[$key]]) : $selected, $holding);
            }

            if ($reads !== []) {
                $this->connection->statement('DROP TABLE '.$this->connection->getQueryGrammar()->wrapTable(self::EVALUATED));
            }

            return $updated;
        });
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, float|int|numeric-string>  $amounts
     */
    private function updateMorphs(QueryBuilder $keys, array $values, array $amounts, string $alias): int
    {
        return $this->transaction(function () use ($keys, $values, $amounts, $alias): int {
            $grammar = $this->connection->getQueryGrammar();
            $key = (new $this->map->model)->getKeyName();
            $selected = $this->connection->table(self::SELECTED)->select('key');
            $this->connection->statement('CREATE TEMPORARY TABLE '.$grammar->wrapTable(self::SELECTED).' ON COMMIT DROP AS SELECT DISTINCT '.$grammar->wrap('keys.'.$key).' AS '.$grammar->wrap('key').' FROM ('.$keys->toSql().') AS '.$grammar->wrap('keys'), $keys->getBindings());
            $primary = $this->map->primary;
            $this->members($this->connection->table($primary->table))
                ->whereIn($primary->table.'.'.$this->legacyColumn(), $selected)
                ->orderBy($primary->table.'.'.$primary->key)
                ->lockForUpdate()
                ->pluck($primary->table.'.'.$primary->key);

            foreach ($this->map->morphs as $variant) {
                $writer = new self($variant, $this->connection);
                $writer->lockDependents($writer->members($this->connection->table($primary->table))->whereIn($primary->table.'.'.$this->legacyColumn(), $selected)->select($primary->table.'.'.$primary->key));
            }

            $this->connection->table(self::SELECTED)->whereNotIn('key', $keys->cloneWithout(['orders', 'limit', 'offset'])->cloneWithoutBindings(['order']))->delete();

            [$mapped, $retained] = $this->byOwner($values);
            [$mappedAmounts, $retainedAmounts] = $this->byOwner($amounts);
            $assigned = self::named($values);
            $whole = array_filter($retained, fn (int|string $name) => ! str_contains((string) $name, '->'), ARRAY_FILTER_USE_KEY);
            $exact = array_diff_key(self::named($mappedAmounts), $assigned) === [] && array_filter(array_keys($assigned), fn (int|string $name) => str_contains((string) $name, '->')) === [];
            $sums = [];

            foreach (array_diff_key(self::named($retainedAmounts), $assigned) as $column => $amount) {
                $sums[$column] = new RawExpression(SqlFragments::identifier($alias).'.'.SqlFragments::identifier($column).' + '.($amount + 0));
            }

            $reads = $this->evaluateOnce($selected, array_filter($whole, fn (mixed $value) => $value instanceof Expression) + $sums, $alias);
            $literals = $this->typed(array_filter($whole, fn (mixed $value) => ! $value instanceof Expression));
            $values = array_replace($values, $literals, array_intersect_key($reads, $retained));
            $known = array_filter(self::named(array_replace($mapped, $literals)), fn (mixed $value) => ! self::computesInSql($value));
            [$holding, $chosen] = $this->refuseMorphMoves($selected, $known, self::named($reads), $alias, $exact);
            $held = $this->connection->table(self::HELD)->select('key');

            if (! $exact) {
                $successors = $this->members($this->connection->table($primary->table))
                    ->whereIn($primary->table.'.'.$this->legacyColumn(), $selected)
                    ->select($primary->table.'.'.$primary->key.' as key');
                $this->connection->statement('CREATE TEMPORARY TABLE '.$grammar->wrapTable(self::HELD).' ON COMMIT DROP AS '.$successors->toSql(), $successors->getBindings());
            }

            $updated = 0;

            foreach ($holding as $variant) {
                $updated += (new self($variant, $this->connection))->update($selected, $values, $amounts, $alias);
            }

            if (! $exact) {
                $this->refuseMorphMoved($held, $chosen, $alias);
                $this->connection->statement('DROP TABLE '.$grammar->wrapTable(self::HELD));
            }

            if ($reads !== []) {
                $this->connection->statement('DROP TABLE '.$grammar->wrapTable(self::EVALUATED));
            }

            $this->connection->statement('DROP TABLE '.$grammar->wrapTable(self::SELECTED));

            return $updated;
        });
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, Expression>  $reads
     * @return array{list<ResolvedMap>, array<array-key, array{int|string, ResolvedMap}>}
     */
    private function refuseMorphMoves(QueryBuilder $selected, array $values, array $reads, string $alias, bool $exact): array
    {
        $morph = $this->map->morph ?? throw new LogicException("Compatibility map '{$this->map->table}' declares no relatedMorph().");
        $key = (new $this->map->model)->getKeyName();
        $holding = [];
        $chosen = [];
        $served = [];

        foreach ($this->morphRows($alias.'.'.$key, $selected, $reads, $alias)->cursor() as $row) {
            [$before, $type, $evaluated, $successor] = $this->morphRow((array) $row, $reads);
            $from = $this->map->morphHolding($type);
            $record = $before[$key] ?? null;
            $record = is_int($record) || is_string($record) ? $record : '';

            if ($from === null) {
                throw CompatibilityWriteRefused::unsupported($this->map->model, 'a mass write that reaches record '.$record.', whose '.class_basename($this->map->primary->model).' stores '.(is_scalar($type) ? $morph->typeColumn.' '.SqlFragments::literal((string) $type) : 'no '.$morph->typeColumn), 'successors: declares no target for it, so no target statement would write the record, and the mass write would skip it.');
            }

            $holding[spl_object_id($from)] = $from;
            $choice = $this->map->morphFor((new $this->map->model)->setRawAttributes($before));

            if (is_int($successor) || is_string($successor)) {
                $chosen[$successor] = [$record, $choice];
            }

            if ($exact) {
                $served[spl_object_id($from)] ??= (new self($from, $this->connection))->served($values) ?? [];
                $this->refuseMorphMove($record, $from, $choice, array_replace($before, $values, $served[spl_object_id($from)], $evaluated));
            }
        }

        return [array_values($holding), $chosen];
    }

    /**
     * @param  array<array-key, array{int|string, ResolvedMap}>  $chosen
     */
    private function refuseMorphMoved(QueryBuilder $held, array $chosen, string $alias): void
    {
        foreach ($this->morphRows($this->map->primary->table.'.'.$this->map->primary->key, $held, [], $alias)->cursor() as $row) {
            [$after, $type, , $successor] = $this->morphRow((array) $row, []);
            $from = $this->map->morphHolding($type);

            if ($from !== null && (is_int($successor) || is_string($successor)) && array_key_exists($successor, $chosen)) {
                $this->refuseMorphMove($chosen[$successor][0], $from, $chosen[$successor][1], $after);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function refuseMorphMove(int|string $record, ResolvedMap $from, ResolvedMap $chosen, array $after): void
    {
        $to = $this->map->morphFor((new $this->map->model)->setRawAttributes($after));

        if ($to !== $from && $to !== $chosen) {
            throw CompatibilityWriteRefused::unsupported($this->map->model, 'a mass write that changes what the relatedMorph() choose: closure picks', 'It would move record '.$record.' from '.class_basename((string) $from->target?->model).' to '.class_basename((string) $to->target?->model).', and a move creates and deletes each target through its model, one record at a time.');
        }
    }

    /**
     * @param  array<string, Expression>  $reads
     */
    private function morphRows(string $by, QueryBuilder $keys, array $reads, string $alias): QueryBuilder
    {
        $morph = $this->map->morph ?? throw new LogicException("Compatibility map '{$this->map->table}' declares no relatedMorph().");
        $key = (new $this->map->model)->getKeyName();
        $primary = $this->map->primary->table;
        $grammar = $this->connection->getQueryGrammar();
        $rows = $this->connection->table($alias === $this->map->table ? $this->map->table : $this->map->table.' as '.$alias)
            ->select($alias.'.*')
            ->selectRaw(SqlFragments::identifier($primary).'.'.SqlFragments::identifier($morph->typeColumn).' AS '.$grammar->wrap('compatibility_morph_type'))
            ->selectRaw(SqlFragments::identifier($primary).'.'.SqlFragments::identifier($this->map->primary->key).' AS '.$grammar->wrap('compatibility_morph_successor'))
            ->join($primary, $primary.'.'.$this->legacyColumn(), '=', $alias.'.'.$key)
            ->whereIn($by, $keys)
            ->orderBy($alias.'.'.$key);

        if ($this->map->membership !== null) {
            $rows->whereRaw($this->map->membership->of($primary));
        }

        foreach (array_values($reads) as $index => $read) {
            $rows->selectRaw('('.$read->getValue($grammar).') as '.$grammar->wrap('compatibility_value_'.$index));
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, Expression>  $reads
     * @return array{array<string, mixed>, mixed, array<string, mixed>, mixed}
     */
    private function morphRow(array $row, array $reads): array
    {
        $type = $row['compatibility_morph_type'] ?? null;
        $successor = $row['compatibility_morph_successor'] ?? null;
        $evaluated = [];
        unset($row['compatibility_morph_type'], $row['compatibility_morph_successor']);

        foreach (array_keys($reads) as $index => $name) {
            $evaluated[$name] = $row['compatibility_value_'.$index] ?? null;
            unset($row['compatibility_value_'.$index]);
        }

        return [$row, $type, $evaluated, $successor];
    }

    /**
     * @param  array<array-key, mixed>  $retained
     * @return array<array-key, Expression>
     */
    private function evaluateOnce(QueryBuilder $selected, array $retained, string $alias): array
    {
        $expressions = array_filter($retained, fn (mixed $value) => $value instanceof Expression);

        if ($expressions === []) {
            return [];
        }

        $grammar = $this->connection->getQueryGrammar();
        $evaluated = $grammar->wrapTable(self::EVALUATED);
        $retainedTable = SqlFragments::identifier(ViewCompiler::retainedTable($this->map));
        $legacy = SqlFragments::identifier($alias);
        $from = SqlFragments::identifier($this->map->table).($alias === $this->map->table ? '' : ' AS '.$legacy);
        $legacyKey = $legacy.'.'.SqlFragments::identifier((new $this->map->model)->getKeyName());
        $view = SqlFragments::identifier($this->map->table);
        $shape = [$view.'.'.SqlFragments::identifier((new $this->map->model)->getKeyName()).' AS '.$grammar->wrap('key')];
        $columns = [$grammar->wrap('key')];
        $selects = [$legacyKey];
        $reads = [];

        foreach (array_keys($expressions) as $index => $name) {
            $value = $expressions[$name];
            $column = $grammar->wrap('value_'.$index);
            $shape[] = $retainedTable.'.'.SqlFragments::identifier(Str::afterLast((string) $name, '.')).' AS '.$column;
            $columns[] = $column;
            $selects[] = '('.($value instanceof Expression ? (string) $value->getValue($grammar) : '').')';
            $reads[$name] = new RawExpression('(SELECT evaluated.'.$column.' FROM '.$evaluated.' AS evaluated WHERE evaluated.'.$grammar->wrap('key').' = '.$legacyKey.')');
        }

        $this->connection->statement('CREATE TEMPORARY TABLE '.$evaluated.' ON COMMIT DROP AS SELECT '.implode(', ', $shape).' FROM '.$retainedTable.', '.$view.' WITH NO DATA');
        $this->connection->insert('INSERT INTO '.$evaluated.' ('.implode(', ', $columns).') SELECT '.implode(', ', $selects).' FROM '.$from.' WHERE '.$legacyKey.' IN ('.$selected->toSql().')', $selected->getBindings());
        $this->connection->statement('ALTER TABLE '.$evaluated.' ADD PRIMARY KEY ('.$grammar->wrap('key').')');
        $this->connection->statement('ANALYZE '.$evaluated);

        return $reads;
    }

    /**
     * @param  array<string, mixed>  $identities
     */
    private function claimAcross(QueryBuilder $selected, array $identities): void
    {
        $records = (clone $selected)->count();

        if ($records > 1) {
            $this->duplicate('A mass write gives '.$records.' '.class_basename($this->map->model).' records one '.implode(' and one ', array_keys($identities)).', and each is unique.');
        }

        $current = (clone $selected)->value('key');

        if ($records === 1 && (is_int($current) || is_string($current))) {
            $this->claim($identities, null, $current);
        }
    }

    /**
     * @param  Closure(QueryBuilder, list<ResolvedMap>): int  $write
     */
    private function acrossBranches(QueryBuilder $keys, Closure $write): int
    {
        return $this->transaction(function () use ($keys, $write): int {
            $grammar = $this->connection->getQueryGrammar();
            $key = (new $this->map->model)->getKeyName();
            $selected = $this->connection->table(self::SELECTED)->select('key');
            $holding = [];

            $this->connection->statement('CREATE TEMPORARY TABLE '.$grammar->wrapTable(self::SELECTED).' ON COMMIT DROP AS SELECT DISTINCT '.$grammar->wrap('keys.'.$key).' AS '.$grammar->wrap('key').' FROM ('.$keys->toSql().') AS '.$grammar->wrap('keys'), $keys->getBindings());
            $this->connection->table(self::SELECTED)
                ->selectRaw('pg_advisory_xact_lock(hashtextextended(CAST(? AS text) || CAST(CAST('.$grammar->wrap('key').' AS '.$this->identityType($key).') AS text), 0))', [$this->identityLock($key)])
                ->orderBy('key')
                ->get();

            foreach ($this->map->branches as $branch) {
                $primary = $branch->primary;
                $locked = $this->connection->table($primary->table)
                    ->where($primary->table.'.'.(string) $branch->membership?->column, $branch->membership?->alias)
                    ->whereIn($primary->table.'.'.(string) $branch->identifiers[$key]->column(), $selected)
                    ->orderBy($primary->table.'.'.$primary->key)
                    ->lockForUpdate()
                    ->pluck($primary->table.'.'.$primary->key);

                if ($locked->isNotEmpty()) {
                    (new self($branch, $this->connection))->lockDependents($locked->all());
                    $holding[] = $branch;
                }
            }

            $this->connection->table(self::SELECTED)->whereNotIn('key', $keys->cloneWithout(['orders', 'limit', 'offset'])->cloneWithoutBindings(['order']))->delete();
            $written = $write($selected, $holding);
            $this->connection->statement('DROP TABLE '.$grammar->wrapTable(self::SELECTED));

            return $written;
        });
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function refuseMoves(QueryBuilder $selected, array $values, string $alias): void
    {
        if ($values === []) {
            return;
        }

        $key = (new $this->map->model)->getKeyName();
        $values = self::named($values);
        $grammar = $this->connection->getQueryGrammar();
        $from = $alias === $this->map->table ? $this->map->table : $this->map->table.' as '.$alias;
        $rows = $this->connection->table($from)->select($alias.'.*')->whereIn($alias.'.'.$key, $selected)->orderBy($alias.'.'.$key);
        $expressions = array_keys(array_filter($values, fn (mixed $value) => $value instanceof Expression));

        foreach ($expressions as $index => $name) {
            $value = $values[$name];
            $rows->selectRaw('('.($value instanceof Expression ? $value->getValue($grammar) : '').') as '.$grammar->wrap('compatibility_value_'.$index));
        }

        $served = [];

        foreach ($rows->cursor() as $row) {
            $before = (array) $row;
            $evaluated = [];

            foreach ($expressions as $index => $name) {
                $evaluated[$name] = $before['compatibility_value_'.$index] ?? null;
                unset($before['compatibility_value_'.$index]);
            }

            $from = $this->map->branchFor((new $this->map->model)->setRawAttributes($before));
            $served[spl_object_id($from)] ??= (new self($from, $this->connection))->served($values) ?? [];
            $this->refuseMove($before, $from, array_replace($before, $values, $served[spl_object_id($from)], $evaluated));
        }
    }

    /**
     * @param  list<ResolvedMap>  $holding
     */
    private function refuseMoved(QueryBuilder $keys, array $holding): void
    {
        $key = (new $this->map->model)->getKeyName();

        foreach ($holding as $branch) {
            $table = $branch->primary->table;
            $identifier = $table.'.'.(string) $branch->identifiers[$key]->column();
            $held = $this->connection->table($table)
                ->where($table.'.'.(string) $branch->membership?->column, $branch->membership?->alias)
                ->whereIn($identifier, $keys)
                ->select($identifier);

            foreach ($this->connection->table($this->map->table)->whereIn($key, $held)->orderBy($key)->cursor() as $row) {
                $this->refuseMove((array) $row, $branch, (array) $row);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function refuseMove(array $before, ResolvedMap $from, array $after): void
    {
        $to = $this->map->branchFor((new $this->map->model)->setRawAttributes($after));

        if ($from !== $to) {
            $record = $before[(new $this->map->model)->getKeyName()] ?? null;

            throw CompatibilityWriteRefused::unsupported($this->map->model, 'a mass write that changes what servedFrom() chooses', 'It would move record '.(is_int($record) || is_string($record) ? $record : '').' from '.class_basename($from->primary->model).' to '.class_basename($to->primary->model).', and a move deletes and recreates each record through its successor models, one at a time.');
        }
    }

    /**
     * @template TValue
     *
     * @param  array<array-key, TValue>  $values
     * @return array{array<array-key, TValue>, array<array-key, TValue>}
     */
    private function byOwner(array $values): array
    {
        $mapped = [];
        $retained = [];

        foreach ($values as $name => $value) {
            $attribute = Str::afterLast((string) $name, '.');

            if (array_key_exists($attribute, $this->map->identifiers) || array_key_exists($attribute, $this->map->attributes)) {
                $mapped[$name] = $value;
            } else {
                $retained[$name] = $value;
            }
        }

        return [$mapped, $retained];
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

    /**
     * @param  array<array-key, mixed>  $values
     * @return array{array<string, array<string, mixed>>, array<string, mixed>}
     */
    private function split(array $values): array
    {
        $stored = [];
        $retained = [];

        foreach ($values as $key => $value) {
            [$name, $path] = array_pad(explode('->', (string) $key, 2), 2, null);
            $name = Str::afterLast($name, '.');
            $within = $path === null ? '' : '->'.$path;

            if (array_key_exists($name, $this->map->identifiers)) {
                self::refuseRaw($this->map, $name, $value);
                $stored[$this->map->primary->table][(string) $this->map->identifiers[$name]->column().$within] = $value;

                continue;
            }

            $attribute = $this->map->attributes[$name] ?? null;

            if ($attribute === null) {
                $retained[$name.$within] = $value;

                continue;
            }

            $this->refuse($name, null);
            self::refuseRaw($this->map, $name, $value);

            if ($path !== null) {
                $column = $attribute->column();

                if ($column === null || $attribute->conversions() !== []) {
                    throw CompatibilityWriteRefused::unsupported($this->map->model, "a JSON path write of \${$name}", 'Its conversions read the whole stored value, so a path inside the legacy value has no one stored path.');
                }

                $stored[$this->map->owners[$name]->table][$column.$within] = $value;

                continue;
            }

            try {
                $value = $attribute->write($value);
            } catch (LogicException $exception) {
                throw CompatibilityWriteRefused::unstorable($this->map->model, $name, $exception->getMessage());
            }

            $column = $attribute->column();

            if ($column !== null) {
                $stored[$this->map->owners[$name]->table][$column] = $value;
            }
        }

        return [$stored, $retained];
    }

    private function refuse(string $name, null|int|string $key): void
    {
        $attribute = $this->map->attributes[$name];
        $readOnly = $attribute->readOnlyBecause();
        $refusal = match (true) {
            $attribute->isExternal() => CompatibilityWriteRefused::external($this->map->model, $name),
            $attribute->runsTransitions() => CompatibilityWriteRefused::transitions($this->map->model, $name),
            $readOnly !== null => CompatibilityWriteRefused::readOnly($this->map->model, $name, $key, $readOnly),
            default => null,
        };

        if ($refusal !== null) {
            throw $refusal;
        }
    }

    private function pin(QueryBuilder $keys): int
    {
        $primary = $this->map->primary;
        $key = $primary->table.'.'.$primary->key;
        $legacy = $primary->table.'.'.$this->legacyColumn();
        $grammar = $this->connection->getQueryGrammar();

        $this->connection->statement('CREATE TEMPORARY TABLE '.$grammar->wrapTable(self::PINNED).' ON COMMIT DROP AS SELECT '.$grammar->wrap($key).' AS '.$grammar->wrap('key').' FROM '.$grammar->wrapTable($primary->table).' WITH NO DATA');
        $locked = $this->connection->table(self::PINNED)->insertUsing(['key'], $this->members($this->connection->table($primary->table))->select($key)->whereIn($legacy, $keys)->orderBy($key)->lockForUpdate());
        $this->lockDependents($this->pinned());
        $filter = $keys->cloneWithout(['orders', 'limit', 'offset'])->cloneWithoutBindings(['order']);
        $moved = $this->connection->table(self::PINNED.' as pinned')->whereNotExists(fn (QueryBuilder $matching) => $this->members($matching->from($primary->table))->whereColumn($key, 'pinned.key')->whereIn($legacy, $filter))->delete();

        return $locked - $moved;
    }

    private function members(QueryBuilder $query): QueryBuilder
    {
        $membership = $this->map->membership;
        $morph = $this->map->morph;
        $target = $this->map->target;

        if ($membership !== null) {
            $query->where($membership->table.'.'.$membership->column, $membership->alias);
        }

        return $morph === null || $target === null ? $query : $query->where($this->map->primary->table.'.'.$morph->typeColumn, $target->morphAlias);
    }

    public function lockDependentsOf(int|string $key): void
    {
        $this->lockDependents([$key]);
    }

    /**
     * @param  QueryBuilder|list<int|string>  $keys
     */
    private function lockDependents(QueryBuilder|array $keys): void
    {
        $retained = $this->map->retainedTable;

        if ($retained !== null) {
            $primary = $this->map->primary;
            $retainedKey = $retained.'.'.$this->map->retainedKey;
            $owners = $this->map->branch ? $this->connection->table($primary->table)->select($primary->table.'.'.$this->legacyColumn())->whereIn($primary->table.'.'.$primary->key, $keys) : $keys;
            $this->lock($this->connection->table($retained)->select($retainedKey)->whereIn($retainedKey, $owners)->orderBy($retainedKey));
        }

        foreach ($this->map->related as $related) {
            $key = $related->table.'.'.$related->successor->key;
            $this->lock($this->connection->table($related->table)->select($key)->whereIn($key, $this->reach($related, $keys)->whereNotNull($key)->select($key))->orderBy($key));
        }
    }

    private function lock(QueryBuilder $rows): void
    {
        $this->connection->query()->fromSub($rows->lockForUpdate(), 'locked')->count();
    }

    private function pinned(): QueryBuilder
    {
        return $this->connection->table(self::PINNED)->select('key');
    }

    private function capture(): void
    {
        $primary = $this->map->primary;
        $grammar = $this->connection->getQueryGrammar();
        $columns = [];

        foreach (array_values($this->map->related) as $index => $related) {
            $columns[self::target($index)] = $grammar->wrap($related->table.'.'.$related->successor->key);
            $columns[self::parentOf($index)] = self::parent($related);
        }

        $selects = implode(', ', array_map(fn (string $expression, string $name) => $expression.' AS '.$grammar->wrap($name), $columns, array_keys($columns)));
        $reached = SqlFragments::identifier($primary->table).' '.implode(' ', ViewCompiler::laterals($this->map));
        $this->connection->statement('CREATE TEMPORARY TABLE '.$grammar->wrapTable(self::REACHED).' ON COMMIT DROP AS SELECT '.$selects.' FROM '.$reached.' WITH NO DATA');
        $this->connection->table(self::REACHED)->insertUsing(array_keys($columns), $this->connection->query()->fromRaw($reached)->selectRaw($selects)->whereIn($primary->table.'.'.$primary->key, $this->pinned()));
    }

    private function reached(): QueryBuilder
    {
        return $this->connection->table(self::REACHED);
    }

    private static function target(int $index): string
    {
        return 'target_'.$index;
    }

    private static function parentOf(int $index): string
    {
        return 'parent_'.$index;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, float|int>  $amounts
     */
    private function updateRelated(Related $related, int $index, array $values, array $amounts): void
    {
        $changes = $this->withAmounts($values, $amounts);

        if ($changes === []) {
            return;
        }

        $catalog = new ColumnCatalog($this->connection, throughSearchPath: true);
        $whole = array_filter($values, fn (int|string $column) => ! str_contains((string) $column, '->'), ARRAY_FILTER_USE_KEY);
        $creates = array_filter($whole, fn (mixed $value) => $value !== null) !== [] && $this->pinMissing($related, $index, $whole, $catalog);
        $key = $related->table.'.'.$related->successor->key;
        $this->successor($related->model)->newModelQuery()->whereIn($key, $this->reached()->whereNotNull(self::target($index))->select(self::target($index)))->update($changes);

        if ($creates) {
            $this->createMissing($related, $index, $whole, $catalog);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function pinMissing(Related $related, int $index, array $values, ColumnCatalog $catalog): bool
    {
        $written = array_keys(array_filter($related->successor->attributes(), fn ($attribute) => array_key_exists((string) $attribute->column(), $values)));

        if ($related->belongsTo && $this->reached()->whereNull(self::target($index))->exists()) {
            throw CompatibilityWriteRefused::noRelatedRow($this->map->model, $written, $related->table, $related->parentTable);
        }

        if ($related->parentTable !== $this->map->primary->table && $this->reached()->whereNull(self::target($index))->whereNull(self::parentOf($index))->exists()) {
            throw CompatibilityWriteRefused::noParentRow($this->map->model, $written, $related->table, $related->parentTable);
        }

        $key = $catalog->of($related->table)[$related->successor->key] ?? null;

        if ($key !== null && $key->default === null && ! $key->generated) {
            if ($this->reached()->whereNull(self::target($index))->whereNotNull(self::parentOf($index))->exists()) {
                throw CompatibilityWriteRefused::noKeyDefault($this->map->model, $written, $related->table, $related->successor->key);
            }

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function createMissing(Related $related, int $index, array $values, ColumnCatalog $catalog): void
    {
        $model = $this->successor($related->model);
        $grammar = $this->connection->getQueryGrammar();
        $parent = $grammar->wrap(self::parentOf($index));
        $timestamps = [];

        if ($model->usesTimestamps()) {
            foreach ([$model->getCreatedAtColumn(), $model->getUpdatedAtColumn()] as $column) {
                if ($column !== null) {
                    $timestamps[$column] = $model->freshTimestampString();
                }
            }
        }

        $row = [...$timestamps, ...array_diff_key($model->getAttributes(), $timestamps), $related->relatedKey => new RawExpression($parent), ...$related->attributes, ...$values];

        $selects = [];
        $bindings = [];

        foreach ($row as $column => $value) {
            if ($value instanceof Expression) {
                $selects[] = (string) $value->getValue($grammar);

                continue;
            }

            $selects[] = 'CAST(? AS '.($catalog->type($related->table, (string) $column) ?? 'text').')';
            $bindings = [...$bindings, ...$grammar->prepareBindingsForUpdate([], [(string) $column => $value])];
        }

        $source = $this->reached()->selectRaw(implode(', ', $selects), $bindings)->distinct(new RawExpression($parent))->whereNull(self::target($index))->whereNotNull(self::parentOf($index));
        $model->newModelQuery()->insertUsing(array_map(fn (int|string $column) => (string) $column, array_keys($row)), $source);
        $this->adopt($related, $index);
    }

    private function adopt(Related $reachable, int $index): void
    {
        $grammar = $this->connection->getQueryGrammar();
        $children = array_filter(array_values($this->map->related), fn (Related $related) => $related->parentTable === $reachable->table);
        $unresolved = 'reached.'.$grammar->wrap(self::target($index)).' IS NULL AND reached.'.$grammar->wrap(self::parentOf($index)).' IS NOT NULL';

        foreach ($children as $child => $related) {
            $this->connection->update('UPDATE '.$grammar->wrapTable(self::REACHED).' AS reached SET '.$grammar->wrap(self::parentOf($child)).' = '.$this->first($reachable, $index, $related->parentKey).' WHERE '.$unresolved.' AND reached.'.$grammar->wrap(self::parentOf($child)).' IS NULL AND reached.'.$grammar->wrap(self::target($child)).' IS NULL');
        }

        $this->connection->update('UPDATE '.$grammar->wrapTable(self::REACHED).' AS reached SET '.$grammar->wrap(self::target($index)).' = '.$this->first($reachable, $index, $reachable->successor->key).' WHERE '.$unresolved);

        foreach ($children as $child => $related) {
            $key = $related->table.'.'.$related->successor->key;
            $rows = $this->connection->table($related->table)->select($key)->whereIn($related->table.'.'.$related->relatedKey, $this->reached()->whereNull(self::target($child))->whereNotNull(self::parentOf($child))->select(self::parentOf($child)));

            foreach (ViewCompiler::conditions($related, false) as $condition) {
                $rows->whereRaw($condition);
            }

            $this->lock($rows->orderBy($key));
            $this->adopt($related, $child);
        }
    }

    private function first(Related $related, int $index, string $column): string
    {
        $table = SqlFragments::identifier($related->table);

        return '(SELECT '.$table.'.'.SqlFragments::identifier($column).' FROM '.$table.' WHERE '.implode(' AND ', [
            $table.'.'.SqlFragments::identifier($related->relatedKey).' = reached.'.$this->connection->getQueryGrammar()->wrap(self::parentOf($index)),
            ...ViewCompiler::conditions($related, false),
        ]).($related->orders === null ? '' : ' ORDER BY '.$related->orders).' LIMIT 1)';
    }

    private static function parent(Related $related): string
    {
        return SqlFragments::identifier($related->parentTable).'.'.SqlFragments::identifier($related->parentKey);
    }

    /**
     * @param  null|QueryBuilder|list<int|string>  $keys
     */
    private function reach(Related $related, null|QueryBuilder|array $keys = null): QueryBuilder
    {
        $primary = $this->map->primary;

        return $this->connection->query()->fromRaw(SqlFragments::identifier($primary->table).' '.implode(' ', ViewCompiler::laterals($this->map, $related->table)))->whereIn($primary->table.'.'.$primary->key, $keys ?? $this->pinned());
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, float|int>  $amounts
     * @return array<string, mixed>
     */
    private function withAmounts(array $values, array $amounts): array
    {
        $grammar = $this->connection->getQueryGrammar();

        foreach ($amounts as $column => $amount) {
            if (! array_key_exists($column, $values)) {
                $values[$column] = new RawExpression($grammar->wrap($column).' + '.$amount);
            }
        }

        return $values;
    }

    private function legacyColumn(): string
    {
        return (string) $this->map->identifiers[(new $this->map->model)->getKeyName()]->column();
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function successor(string $class): Model
    {
        return (new $class)->setConnection($this->connection->getName());
    }
}
