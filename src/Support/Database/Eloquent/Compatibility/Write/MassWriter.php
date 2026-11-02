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
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use LogicException;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\DividedBy;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\MultipliedBy;
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

    private const string MISSING = 'pg_temp.compatibility_missing';

    public function __construct(private ResolvedMap $map, private Connection $connection) {}

    public static function refuseRaw(ResolvedMap $map, string $name, mixed $value): void
    {
        if (! $value instanceof Expression && ! $value instanceof QueryBuilder && ! $value instanceof Builder && ! $value instanceof Relation && ! $value instanceof Closure) {
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
    public function update(QueryBuilder $keys, array $values, array $amounts = []): int
    {
        if ($this->map->branches !== []) {
            return $this->updateBranches($keys, $values, $amounts);
        }

        [$stored, $retained] = $this->split($values);
        [$added, $retainedAmounts] = $this->amounts($amounts, null);

        return $this->transaction(function () use ($keys, $stored, $retained, $added, $retainedAmounts): int {
            $pinned = $this->pin($keys);

            if ($pinned > 0) {
                $primary = $this->map->primary;
                (new RetainedRows($this->map, $this->connection))->upsertAll($this->pinned(), $retained, array_diff_key($retainedAmounts, $retained));
                $values = $this->withAmounts($stored[$primary->table] ?? [], $added[$primary->table] ?? []);

                if ($values !== []) {
                    $this->successor($primary->model)->newModelQuery()->whereIn($primary->table.'.'.$primary->key, $this->pinned())->update($values);
                }

                foreach ($this->map->related as $related) {
                    $this->updateRelated($related, $stored[$related->table] ?? [], $added[$related->table] ?? []);
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
     * @param  array<string, mixed>  $values
     * @param  array<string, float|int>  $amounts
     */
    public function retainByIdentity(QueryBuilder $keys, array $values, array $amounts): void
    {
        if ($values === [] && $amounts === []) {
            return;
        }

        $retained = ViewCompiler::retainedTable($this->map);
        $table = SqlFragments::identifier($retained);
        $legacy = SqlFragments::identifier($this->map->table);
        $legacyKey = $legacy.'.'.SqlFragments::identifier((new $this->map->model)->getKeyName());
        $retainedKey = SqlFragments::identifier((string) $this->map->retainedKey);
        $catalog = new ColumnCatalog($this->connection);
        $grammar = $this->connection->getQueryGrammar();
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
            ? implode(', ', [$legacyKey, ...$selects]).' FROM '.$legacy.' WHERE '.$legacyKey.' IN ('.$keys->toSql().')'
            : implode(', ', ['selected.key', ...$selects]).' FROM ('.$keys->toSql().') AS selected (key)';

        $this->connection->affectingStatement(
            'INSERT INTO '.$table.' ('.implode(', ', [$retainedKey, ...array_map(fn (string $column) => SqlFragments::identifier($column), [...array_keys($values), ...array_keys($amounts)])]).')'
                .' SELECT '.$source
                .' ON CONFLICT ('.$retainedKey.') DO UPDATE SET '.implode(', ', $updates),
            [...$bindings, ...$keys->getBindings()],
        );
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, float|int|numeric-string>  $amounts
     */
    private function updateBranches(QueryBuilder $keys, array $values, array $amounts): int
    {
        [$mapped, $retained] = $this->byOwner($values);
        [$mappedAmounts, $retainedAmounts] = $this->byOwner($amounts);

        return $this->acrossBranches($keys, function (QueryBuilder $selected, array $holding) use ($values, $amounts, $mapped, $retained, $mappedAmounts, $retainedAmounts): int {
            $this->refuseMoves($selected, $values, $amounts);
            $updated = 0;

            foreach ($holding as $branch) {
                $updated += (new self($branch, $this->connection))->update($selected, $mapped, $mappedAmounts);
            }

            $this->retainByIdentity($selected, self::named($retained), array_map(fn (float|int|string $amount) => $amount + 0, array_diff_key(self::named($retainedAmounts), self::named($retained))));

            return $updated;
        });
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

            $this->connection->statement('CREATE TEMPORARY TABLE '.$grammar->wrapTable(self::SELECTED).' ON COMMIT DROP AS SELECT '.$grammar->wrap('keys.'.$key).' AS '.$grammar->wrap('key').' FROM ('.$keys->toSql().') AS '.$grammar->wrap('keys'), $keys->getBindings());

            foreach ($this->map->branches as $branch) {
                $primary = $branch->primary;
                $locked = $this->connection->table($primary->table)
                    ->where($primary->table.'.'.(string) $branch->membership?->column, $branch->membership?->alias)
                    ->whereIn($primary->table.'.'.(string) $branch->identifiers[$key]->column(), $selected)
                    ->orderBy($primary->table.'.'.$primary->key)
                    ->lockForUpdate()
                    ->pluck($primary->table.'.'.$primary->key);

                if ($locked->isNotEmpty()) {
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
     * @param  array<array-key, float|int|numeric-string>  $amounts
     */
    private function refuseMoves(QueryBuilder $selected, array $values, array $amounts): void
    {
        if ($values === [] && $amounts === []) {
            return;
        }

        $key = (new $this->map->model)->getKeyName();
        $values = self::named($values);
        $amounts = self::named($amounts);

        foreach ($this->connection->table($this->map->table)->whereIn($key, $selected)->orderBy($key)->cursor() as $row) {
            $before = (array) $row;
            $after = array_replace($before, $values);

            foreach ($amounts as $name => $amount) {
                $after[$name] = is_numeric($after[$name] ?? null) ? $after[$name] + $amount : $after[$name] ?? null;
            }

            $from = $this->map->branchFor((new $this->map->model)->setRawAttributes($before));
            $to = $this->map->branchFor((new $this->map->model)->setRawAttributes($after));

            if ($from !== $to) {
                $record = $before[$key] ?? null;

                throw CompatibilityWriteRefused::unsupported($this->map->model, 'a mass write that changes what servedFrom() chooses', 'It would move record '.(is_int($record) || is_string($record) ? $record : '').' from '.class_basename($from->primary->model).' to '.class_basename($to->primary->model).', and a move deletes and recreates each record through its successor models, one at a time.');
            }
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

        foreach ($values as $name => $value) {
            $name = Str::afterLast((string) $name, '.');

            if (array_key_exists($name, $this->map->identifiers)) {
                self::refuseRaw($this->map, $name, $value);
                $stored[$this->map->primary->table][(string) $this->map->identifiers[$name]->column()] = $value;

                continue;
            }

            $attribute = $this->map->attributes[$name] ?? null;

            if ($attribute === null) {
                $retained[$name] = $value;

                continue;
            }

            $this->refuse($name, null);
            self::refuseRaw($this->map, $name, $value);

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
        $this->lockDependents();
        $filter = $keys->cloneWithout(['orders', 'limit', 'offset'])->cloneWithoutBindings(['order']);
        $moved = $this->connection->table(self::PINNED.' as pinned')->whereNotExists(fn (QueryBuilder $matching) => $this->members($matching->from($primary->table))->whereColumn($key, 'pinned.key')->whereIn($legacy, $filter))->delete();

        return $locked - $moved;
    }

    private function members(QueryBuilder $query): QueryBuilder
    {
        $membership = $this->map->membership;

        return $membership === null ? $query : $query->where($membership->table.'.'.$membership->column, $membership->alias);
    }

    private function lockDependents(): void
    {
        $retained = $this->map->retainedTable;

        if ($retained !== null) {
            $primary = $this->map->primary;
            $retainedKey = $retained.'.'.$this->map->retainedKey;
            $owners = $this->map->branch ? $this->connection->table($primary->table)->select($primary->table.'.'.$this->legacyColumn())->whereIn($primary->table.'.'.$primary->key, $this->pinned()) : $this->pinned();
            $this->lock($this->connection->table($retained)->select($retainedKey)->whereIn($retainedKey, $owners)->orderBy($retainedKey));
        }

        foreach ($this->map->related as $related) {
            $key = $related->table.'.'.$related->successor->key;
            $this->lock($this->connection->table($related->table)->select($key)->whereIn($key, $this->reach($related)->whereNotNull($key)->select($key))->orderBy($key));
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

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, float|int>  $amounts
     */
    private function updateRelated(Related $related, array $values, array $amounts): void
    {
        $changes = $this->withAmounts($values, $amounts);

        if ($changes === []) {
            return;
        }

        $catalog = new ColumnCatalog($this->connection);
        $creates = array_filter($values, fn (mixed $value) => $value !== null) !== [] && $this->pinMissing($related, $values, $catalog);
        $key = $related->table.'.'.$related->successor->key;
        $this->successor($related->model)->newModelQuery()->whereIn($key, $this->reach($related)->whereNotNull($key)->select($key))->update($changes);

        if ($creates) {
            $this->createMissing($related, $values, $catalog);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function pinMissing(Related $related, array $values, ColumnCatalog $catalog): bool
    {
        $missing = self::missing($related);
        $parent = self::parent($related);
        $written = array_keys(array_filter($related->successor->attributes(), fn ($attribute) => array_key_exists((string) $attribute->column(), $values)));

        if ($related->belongsTo && $this->reach($related)->whereRaw($missing)->exists()) {
            throw CompatibilityWriteRefused::noRelatedRow($this->map->model, $written, $related->table, $related->parentTable);
        }

        if ($related->parentTable !== $this->map->primary->table && $this->reach($related)->whereRaw($missing)->whereRaw($parent.' IS NULL')->exists()) {
            throw CompatibilityWriteRefused::noParentRow($this->map->model, $written, $related->table, $related->parentTable);
        }

        $key = $catalog->of($related->table)[$related->successor->key] ?? null;
        $lacking = $this->reach($related)->whereRaw($missing)->whereNotNull(new RawExpression($parent));

        if ($key !== null && $key->default === null && ! $key->generated) {
            if ($lacking->exists()) {
                throw CompatibilityWriteRefused::noKeyDefault($this->map->model, $written, $related->table, $related->successor->key);
            }

            return false;
        }

        $primary = $this->map->primary;
        $grammar = $this->connection->getQueryGrammar();
        $this->connection->statement('CREATE TEMPORARY TABLE '.$grammar->wrapTable(self::MISSING).' ON COMMIT DROP AS SELECT '.$grammar->wrap($primary->table.'.'.$primary->key).' AS '.$grammar->wrap('key').' FROM '.$grammar->wrapTable($primary->table).' WITH NO DATA');
        $this->connection->table(self::MISSING)->insertUsing(['key'], $lacking->select($primary->table.'.'.$primary->key));

        return true;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function createMissing(Related $related, array $values, ColumnCatalog $catalog): void
    {
        $model = $this->successor($related->model);
        $parent = self::parent($related);
        $row = [$related->relatedKey => new RawExpression($parent), ...$related->attributes, ...$values];

        if ($model->usesTimestamps()) {
            $timestamps = [];

            foreach ([$model->getCreatedAtColumn(), $model->getUpdatedAtColumn()] as $column) {
                if ($column !== null) {
                    $timestamps[$column] = $model->freshTimestampString();
                }
            }

            $row = [...$timestamps, ...$row];
        }

        $selects = [];
        $bindings = [];

        foreach ($row as $column => $value) {
            if ($value instanceof Expression) {
                $selects[] = (string) $value->getValue($this->connection->getQueryGrammar());

                continue;
            }

            $selects[] = 'CAST(? AS '.($catalog->type($related->table, (string) $column) ?? 'text').')';
            $bindings[] = $value;
        }

        $primary = $this->map->primary;
        $source = $this->reach($related)->selectRaw(implode(', ', $selects), $bindings)->distinct(new RawExpression($parent))->whereIn($primary->table.'.'.$primary->key, $this->connection->table(self::MISSING)->select('key'))->whereRaw(self::missing($related));
        $model->newModelQuery()->insertUsing(array_map(fn (int|string $column) => (string) $column, array_keys($row)), $source);
        $this->connection->statement('DROP TABLE '.$this->connection->getQueryGrammar()->wrapTable(self::MISSING));
    }

    private static function missing(Related $related): string
    {
        return SqlFragments::identifier($related->table).'.'.SqlFragments::identifier($related->relatedKey).' IS NULL';
    }

    private static function parent(Related $related): string
    {
        return SqlFragments::identifier($related->parentTable).'.'.SqlFragments::identifier($related->parentKey);
    }

    private function reach(Related $related): QueryBuilder
    {
        $primary = $this->map->primary;

        return $this->connection->query()->fromRaw(SqlFragments::identifier($primary->table).' '.implode(' ', ViewCompiler::laterals($this->map, $related->table)))->whereIn($primary->table.'.'.$primary->key, $this->pinned());
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
