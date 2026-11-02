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

    private const string REACHED = 'pg_temp.compatibility_reached';

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
        [$stored, $retained] = $this->split($values);
        [$added, $retainedAmounts] = $this->amounts($amounts, null);

        return $this->transaction(function () use ($keys, $stored, $retained, $added, $retainedAmounts): int {
            $pinned = $this->pin($keys);

            if ($pinned > 0) {
                $primary = $this->map->primary;
                $related = array_values($this->map->related);
                $reached = array_filter($related, fn (Related $related) => $this->withAmounts($stored[$related->table] ?? [], $added[$related->table] ?? []) !== []) !== [];

                if ($reached) {
                    $this->capture();
                }

                (new RetainedRows($this->map, $this->connection))->upsertAll($this->pinned(), $retained, array_diff_key($retainedAmounts, $retained));
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
        $locked = $this->connection->table(self::PINNED)->insertUsing(['key'], $this->connection->table($primary->table)->select($key)->whereIn($legacy, $keys)->orderBy($key)->lockForUpdate());
        $this->lockDependents($this->pinned());
        $filter = $keys->cloneWithout(['orders', 'limit', 'offset'])->cloneWithoutBindings(['order']);
        $moved = $this->connection->table(self::PINNED.' as pinned')->whereNotExists(fn (QueryBuilder $matching) => $matching->from($primary->table)->whereColumn($key, 'pinned.key')->whereIn($legacy, $filter))->delete();

        return $locked - $moved;
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
            $retainedKey = $retained.'.'.$this->map->retainedKey;
            $this->lock($this->connection->table($retained)->select($retainedKey)->whereIn($retainedKey, $keys)->orderBy($retainedKey));
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
        $creates = array_filter($values, fn (mixed $value) => $value !== null) !== [] && $this->pinMissing($related, $index, $values, $catalog);
        $key = $related->table.'.'.$related->successor->key;
        $this->successor($related->model)->newModelQuery()->whereIn($key, $this->reached()->whereNotNull(self::target($index))->select(self::target($index)))->update($changes);

        if ($creates) {
            $this->createMissing($related, $index, $values, $catalog);
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
