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
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, float|int|numeric-string>  $amounts
     */
    public function update(QueryBuilder $keys, array $values, array $amounts = []): int
    {
        [$stored, $retained] = $this->split($values);
        [$added, $retainedAmounts] = $this->amounts($amounts, null);

        return $this->connection->transaction(function () use ($keys, $stored, $retained, $added, $retainedAmounts): int {
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
        $primary = $this->map->primary;

        $query = $this->successor($primary->model)->newModelQuery()->whereIn($primary->table.'.'.$this->legacyColumn(), $keys);
        $this->members($query->getQuery());

        return (int) $query->delete();
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
        $locked = $this->connection->table(self::PINNED)->insertUsing(['key'], $this->members($this->connection->table($primary->table))->select($key)->whereIn($legacy, $keys)->orderBy($key)->lockForUpdate());
        $filter = $keys->cloneWithout(['orders', 'limit', 'offset'])->cloneWithoutBindings(['order']);
        $moved = $this->connection->table(self::PINNED.' as pinned')->whereNotExists(fn (QueryBuilder $matching) => $this->members($matching->from($primary->table))->whereColumn($key, 'pinned.key')->whereIn($legacy, $filter))->delete();

        return $locked - $moved;
    }

    private function members(QueryBuilder $query): QueryBuilder
    {
        $membership = $this->map->membership;

        return $membership === null ? $query : $query->where($membership->table.'.'.$membership->column, $membership->alias);
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

        $table = SqlFragments::identifier($related->table);
        $key = $related->successor->key;
        $reached = $this->reach($related)->whereNotNull($related->table.'.'.$key)->select($related->table.'.'.$key);
        $this->successor($related->model)->newModelQuery()->whereIn($related->table.'.'.$key, $reached)->update($changes);

        if (array_filter($values, fn (mixed $value) => $value !== null) === []) {
            return;
        }

        $missing = $table.'.'.SqlFragments::identifier($related->relatedKey).' IS NULL';
        $parent = SqlFragments::identifier($related->parentTable).'.'.SqlFragments::identifier($related->parentKey);
        $written = array_keys(array_filter($related->successor->attributes(), fn ($attribute) => array_key_exists((string) $attribute->column(), $values)));

        if ($related->belongsTo && $this->reach($related)->whereRaw($missing)->exists()) {
            throw CompatibilityWriteRefused::noRelatedRow($this->map->model, $written, $related->table, $related->parentTable);
        }

        if ($related->parentTable !== $this->map->primary->table && $this->reach($related)->whereRaw($missing)->whereRaw($parent.' IS NULL')->exists()) {
            throw CompatibilityWriteRefused::noParentRow($this->map->model, $written, $related->table, $related->parentTable);
        }

        $this->createMissing($related, $values, $written, $missing, $parent);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $written
     */
    private function createMissing(Related $related, array $values, array $written, string $missing, string $parent): void
    {
        $model = $this->successor($related->model);
        $catalog = new ColumnCatalog($this->connection);
        $key = $catalog->of($related->table)[$related->successor->key] ?? null;

        if ($key !== null && $key->default === null && ! $key->generated) {
            if ($this->reach($related)->whereRaw($missing)->whereNotNull(new RawExpression($parent))->exists()) {
                throw CompatibilityWriteRefused::noKeyDefault($this->map->model, $written, $related->table, $related->successor->key);
            }

            return;
        }

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

        $model->newModelQuery()->insertUsing(array_map(fn (int|string $column) => (string) $column, array_keys($row)), $this->reach($related)->selectRaw(implode(', ', $selects), $bindings)->whereRaw($missing)->whereNotNull(new RawExpression($parent)));
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
