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
    public function __construct(private ResolvedMap $map, private Connection $connection) {}

    /**
     * @param  list<int|string>  $keys
     */
    public static function arrayOf(array $keys): string
    {
        return '{'.implode(',', array_map(fn (int|string $key) => '"'.addcslashes((string) $key, '"\\').'"', $keys)).'}';
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

            if ($pinned === []) {
                return 0;
            }

            $primary = $this->map->primary;
            $ids = self::arrayOf($pinned);
            $values = $this->withAmounts($stored[$primary->table] ?? [], $added[$primary->table] ?? []);

            if ($values !== []) {
                $this->successor($primary->model)->newModelQuery()->whereRaw($this->pinnedBy(), [$ids])->update($values);
            }

            foreach ($this->map->related as $related) {
                $this->updateRelated($related, $ids, $stored[$related->table] ?? [], $added[$related->table] ?? []);
            }

            (new RetainedRows($this->map, $this->connection))->upsertAll($pinned, $retained, array_diff_key($retainedAmounts, $retained));

            return count($pinned);
        });
    }

    public function delete(QueryBuilder $keys): int
    {
        $primary = $this->map->primary;

        return (int) $this->successor($primary->model)->newModelQuery()->whereIn($primary->table.'.'.$this->legacyColumn(), $keys)->delete();
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
                $stored[$this->map->primary->table][(string) $this->map->identifiers[$name]->column()] = $value;

                continue;
            }

            $attribute = $this->map->attributes[$name] ?? null;

            if ($attribute === null) {
                $retained[$name] = $value;

                continue;
            }

            $this->refuse($name, null);
            $raw = $value instanceof Expression || $value instanceof QueryBuilder || $value instanceof Builder || $value instanceof Relation || $value instanceof Closure;

            if ($raw && $attribute->conversions() !== []) {
                throw CompatibilityWriteRefused::unsupported($this->map->model, "a raw expression for \${$name}", 'Its conversions read stored values, and SQL written for the legacy value does not pass through them.');
            }

            if (! $raw) {
                try {
                    $value = $attribute->write($value);
                } catch (LogicException $exception) {
                    throw CompatibilityWriteRefused::unstorable($this->map->model, $name, $exception->getMessage());
                }
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

    /**
     * @return list<int|string>
     */
    private function pin(QueryBuilder $keys): array
    {
        $primary = $this->map->primary;
        $key = $primary->table.'.'.$primary->key;

        return array_values(array_map(
            fn (mixed $value) => is_int($value) || is_string($value) ? $value : throw new LogicException("{$key} holds a key that is neither an integer nor a string."),
            $this->connection->table($primary->table)->whereIn($primary->table.'.'.$this->legacyColumn(), $keys)->orderBy($key)->lockForUpdate()->pluck($key)->all(),
        ));
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, float|int>  $amounts
     */
    private function updateRelated(Related $related, string $ids, array $values, array $amounts): void
    {
        $changes = $this->withAmounts($values, $amounts);

        if ($changes === []) {
            return;
        }

        $table = SqlFragments::identifier($related->table);
        $key = $related->successor->key;
        $reached = $this->reach($related)->whereNotNull($related->table.'.'.$key)->select($related->table.'.'.$key)->whereRaw($this->pinnedBy(), [$ids]);
        $this->successor($related->model)->newModelQuery()->whereIn($related->table.'.'.$key, $reached)->update($changes);

        if (array_filter($values, fn (mixed $value) => $value !== null) === []) {
            return;
        }

        $missing = $table.'.'.SqlFragments::identifier($related->relatedKey).' IS NULL';
        $parent = SqlFragments::identifier($related->parentTable).'.'.SqlFragments::identifier($related->parentKey);
        $written = array_keys(array_filter($related->successor->attributes(), fn ($attribute) => array_key_exists((string) $attribute->column(), $values)));

        if ($related->belongsTo && $this->reach($related)->whereRaw($this->pinnedBy(), [$ids])->whereRaw($missing)->exists()) {
            throw CompatibilityWriteRefused::noRelatedRow($this->map->model, $written, $related->table, $related->parentTable);
        }

        if ($related->parentTable !== $this->map->primary->table && $this->reach($related)->whereRaw($this->pinnedBy(), [$ids])->whereRaw($missing)->whereRaw($parent.' IS NULL')->exists()) {
            throw CompatibilityWriteRefused::noParentRow($this->map->model, $written, $related->table, $related->parentTable);
        }

        $this->createMissing($related, $ids, $values, $missing, $parent);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function createMissing(Related $related, string $ids, array $values, string $missing, string $parent): void
    {
        $model = $this->successor($related->model);
        $catalog = new ColumnCatalog($this->connection);
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

        $model->newModelQuery()->insertUsing(array_map(fn (int|string $column) => (string) $column, array_keys($row)), $this->reach($related)->selectRaw(implode(', ', $selects), $bindings)->whereRaw($this->pinnedBy(), [$ids])->whereRaw($missing)->whereNotNull(new RawExpression($parent)));
    }

    private function reach(Related $related): QueryBuilder
    {
        return $this->connection->query()->fromRaw(SqlFragments::identifier($this->map->primary->table).' '.implode(' ', ViewCompiler::laterals($this->map, $related->table)));
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

    private function pinnedBy(): string
    {
        return SqlFragments::identifier($this->map->primary->table).'.'.SqlFragments::identifier($this->map->primary->key).' = any(?)';
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
