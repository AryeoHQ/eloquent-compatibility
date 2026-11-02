<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Related;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;

final readonly class RecordWriter
{
    public function __construct(private ResolvedMap $map, private Connection $connection) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public function insert(Model $legacy, array $values): int|string
    {
        return (new MassWriter($this->map, $this->connection))->transaction(function () use ($legacy, $values): int|string {
            [$stored, $retained, $states] = $this->split($legacy, $values, null);
            $primary = $this->successor($this->map->primary->model);
            $table = $this->map->primary->table;
            $stored = $this->transition($legacy, null, [$table => $primary], array_intersect_key($states, [$table => true]), $stored);
            $this->assign($primary, $stored[$table] ?? []);
            $this->save($legacy, $primary, fn () => $primary->save());
            $key = $this->identify($legacy, $primary);
            [$models, $existing, $missing] = $this->locate($primary, $stored + $states);
            $stored = $this->transition($legacy, $key, $models, array_diff_key($states, [$table => true]), $stored);
            $this->assignEach($models, $stored);
            $this->saveEach($legacy, $existing);
            $this->create($legacy, $key, $models, $missing, $stored, $states);
            (new RetainedRows($this->map, $this->connection))->upsert(self::keyOf($primary), $retained);
            $this->readBack($legacy, $key, array_keys($values));

            return $key;
        });
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(Model $legacy, array $values): int
    {
        return (new MassWriter($this->map, $this->connection))->transaction(function () use ($legacy, $values): int {
            $key = self::keyOf($legacy);
            $primary = $this->lock($legacy, $key);

            if ($primary === null) {
                return 0;
            }

            [$stored, $retained, $states] = $this->split($legacy, $values, $key);
            $retained = $this->guard($legacy, $primary, $key, $values, [], $retained);
            $this->retain($primary, $retained, []);
            [$models, $existing, $missing] = $this->locate($primary, $stored + $states);
            $stored = $this->transition($legacy, $key, $models, $states, $stored);
            $this->assignEach($models, $stored);
            $this->savePrimary($legacy, $primary, $stored[$this->map->primary->table] ?? []);
            $this->saveEach($legacy, $existing);
            $this->create($legacy, $key, $models, $missing, $stored, $states);
            $this->readBack($legacy, $key, array_keys($values));

            return 1;
        });
    }

    /**
     * @param  array<string, float|int|numeric-string>  $amounts
     * @param  array<string, mixed>  $extra
     */
    public function increment(Model $legacy, array $amounts, array $extra): int
    {
        return (new MassWriter($this->map, $this->connection))->transaction(function () use ($legacy, $amounts, $extra): int {
            $key = self::keyOf($legacy);
            $primary = $this->lock($legacy, $key);

            if ($primary === null) {
                return 0;
            }

            [$stored, $retained, $states] = $this->split($legacy, $extra, $key);
            [$added, $retainedAmounts] = (new MassWriter($this->map, $this->connection))->amounts($amounts, $key);
            $retained = $this->guard($legacy, $primary, $key, $extra, $amounts, $retained);
            $this->retain($primary, $retained, array_diff_key($retainedAmounts, $retained));
            [$models, $existing, $missing] = $this->locate($primary, $stored + $states + array_fill_keys(array_keys($added), []));
            $stored = $this->transition($legacy, $key, $models, $states, $stored);
            $this->assignEach($models, $stored);
            $this->addEach($models, $added, $stored);
            $this->savePrimary($legacy, $primary, $stored[$this->map->primary->table] ?? []);
            $this->saveEach($legacy, $existing);
            $this->create($legacy, $key, $models, $missing, $stored, $states);
            $this->readBack($legacy, $key, [...array_keys($amounts), ...array_keys($extra)]);

            return 1;
        });
    }

    public function delete(Model $legacy): int
    {
        return (new MassWriter($this->map, $this->connection))->transaction(function () use ($legacy): int {
            $primary = $this->lock($legacy, self::keyOf($legacy));

            if ($primary === null) {
                return 0;
            }

            $this->save($legacy, $primary, fn () => $primary->forceDelete());

            return 1;
        });
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{array<string, array<string, mixed>>, array<string, mixed>, array<string, array<string, array{string, mixed}>>}
     */
    private function split(Model $legacy, array $values, null|int|string $key): array
    {
        $stored = [];
        $retained = [];
        $states = [];

        foreach ($values as $name => $value) {
            $name = (string) $name;
            $attribute = $this->map->attributes[$name] ?? null;

            if (array_key_exists($name, $this->map->identifiers)) {
                MassWriter::refuseRaw($this->map, $name, $value);
                $stored[$this->map->primary->table][(string) $this->map->identifiers[$name]->column()] = $value;

                continue;
            }

            if ($attribute === null) {
                $retained[$name] = $value;

                continue;
            }

            $readOnly = $attribute->readOnlyBecause();
            $refusal = match (true) {
                $attribute->isExternal() => CompatibilityWriteRefused::external($legacy::class, $name),
                $key !== null && $readOnly !== null => CompatibilityWriteRefused::readOnly($legacy::class, $name, $key, $readOnly),
                default => null,
            };

            if ($refusal !== null) {
                throw $refusal;
            }

            MassWriter::refuseRaw($this->map, $name, $value);

            try {
                $value = $attribute->write($value);
            } catch (LogicException $exception) {
                throw CompatibilityWriteRefused::unstorable($legacy::class, $name, $exception->getMessage());
            }

            $column = $attribute->column();

            if ($column !== null && $attribute->runsTransitions()) {
                $states[$this->map->owners[$name]->table][$column] = [$name, $value];
            } elseif ($column !== null) {
                $stored[$this->map->owners[$name]->table][$column] = $value;
            }
        }

        return [$stored, $retained, $states];
    }

    /**
     * @param  array<string, Model>  $models
     * @param  array<string, array<string, array{string, mixed}>>  $states
     * @param  array<string, array<string, mixed>>  $stored
     * @return array<string, array<string, mixed>>
     */
    private function transition(Model $legacy, null|int|string $key, array $models, array $states, array $stored): array
    {
        $transitions = new Transitions($this->connection);

        foreach ($states as $table => $columns) {
            $model = $models[$table] ?? $this->successor($this->ownerOf($table));

            foreach ($columns as $column => [$name, $value]) {
                if ($key !== null && $model->exists) {
                    $transitions->run($legacy, $key, $name, $model, $column, $value);

                    continue;
                }

                $transitions->initial($legacy, $name, $model, $column, $value);
                $stored[$table][$column] = $value;
            }
        }

        return $stored;
    }

    /**
     * @return class-string<Model>
     */
    private function ownerOf(string $table): string
    {
        foreach ($this->map->related as $related) {
            if ($related->table === $table) {
                return $related->model;
            }
        }

        return $this->map->primary->model;
    }

    /**
     * @param  array<string, Model>  $models
     * @param  array<string, array<string, mixed>>  $stored
     */
    private function assignEach(array $models, array $stored): void
    {
        foreach ($models as $table => $model) {
            if ($table !== $this->map->primary->table) {
                $this->assign($model, $stored[$table] ?? []);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, float|int|numeric-string>  $amounts
     * @param  array<string, mixed>  $retained
     * @return array<string, mixed>
     */
    private function guard(Model $legacy, Model $primary, int|string $key, array $values, array $amounts, array $retained): array
    {
        if ($this->map->guards === []) {
            return $retained;
        }

        (new MassWriter($this->map, $this->connection))->lockDependentsOf(self::keyOf($primary));
        $before = $legacy->newModelQuery()->where($legacy->getKeyName(), $key)->first();

        if ($before === null) {
            return $retained;
        }

        $evaluated = $this->evaluate($legacy, $key, array_filter($retained, fn (mixed $value) => $value instanceof Expression));
        $values = array_replace($values, $evaluated);
        $retained = array_replace($retained, $evaluated);

        foreach ($amounts as $name => $amount) {
            $current = $before->getAttributes()[$name] ?? null;

            if (is_numeric($current) && ! array_key_exists($name, $values)) {
                $values[$name] = $current + $amount;
            }
        }

        $after = clone $before;
        $after->setRawAttributes(array_replace($before->getAttributes(), $values));

        foreach ($this->map->guards as $guard) {
            if ($guard->refuses($after, $before)) {
                throw CompatibilityWriteRefused::byGuard($legacy::class, $guard, $key);
            }
        }

        return $retained;
    }

    /**
     * @param  array<string, Expression>  $expressions
     * @return array<string, mixed>
     */
    private function evaluate(Model $legacy, int|string $key, array $expressions): array
    {
        if ($expressions === []) {
            return [];
        }

        $grammar = $this->connection->getQueryGrammar();
        $selects = [];

        foreach (array_values($expressions) as $index => $expression) {
            $selects[] = '('.$expression->getValue($grammar).') as '.$grammar->wrap('value_'.$index);
        }

        $row = (array) $this->connection->table($legacy->getTable())->where($legacy->getKeyName(), $key)->selectRaw(implode(', ', $selects))->first();

        return array_combine(array_keys($expressions), array_values($row));
    }

    /**
     * @param  list<int|string>  $names
     */
    private function readBack(Model $legacy, int|string $key, array $names): void
    {
        if ($names === []) {
            return;
        }

        $row = (array) $this->connection->table($legacy->getTable())->where($legacy->getKeyName(), $key)->first(array_map(fn (int|string $name) => (string) $name, $names));

        if ($row !== []) {
            $legacy->setRawAttributes(array_replace($legacy->getAttributes(), $row));
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, float|int>  $amounts
     */
    private function retain(Model $primary, array $values, array $amounts): void
    {
        $retained = new RetainedRows($this->map, $this->connection);

        if ($amounts === [] && array_filter($values, fn (mixed $value) => $value instanceof Expression) === []) {
            $retained->upsert(self::keyOf($primary), $values);

            return;
        }

        $key = $this->map->primary->table.'.'.$this->map->primary->key;
        $retained->upsertAll($this->connection->table($this->map->primary->table)->where($key, self::keyOf($primary))->select($key), $values, $amounts);
    }

    /**
     * @param  array<string, Model>  $models
     * @param  array<string, array<string, float|int>>  $added
     * @param  array<string, array<string, mixed>>  $stored
     */
    private function addEach(array $models, array $added, array $stored): void
    {
        $catalog = new ColumnCatalog($this->connection);
        $grammar = $this->connection->getQueryGrammar();
        $sums = [];
        $bindings = [];
        $targets = [];

        foreach ($added as $table => $columns) {
            foreach ($columns as $column => $amount) {
                $current = ($models[$table] ?? null)?->getAttributes()[$column] ?? null;

                if ($current === null || array_key_exists($column, $stored[$table] ?? [])) {
                    continue;
                }

                if (is_int($current) && is_int($amount)) {
                    $this->assign($models[$table], [$column => $current + $amount]);

                    continue;
                }

                $type = $catalog->type($table, $column) ?? 'numeric';
                $sums[] = 'cast(cast(? as '.$type.') + cast(? as numeric) as '.$type.') as '.$grammar->wrap('sum_'.count($targets));
                array_push($bindings, $current, $amount);
                $targets[] = [$table, $column];
            }
        }

        if ($targets === []) {
            return;
        }

        $sum = (array) $this->connection->selectOne('select '.implode(', ', $sums), $bindings);

        foreach ($targets as $index => [$table, $column]) {
            $this->assign($models[$table], [$column => $sum['sum_'.$index]]);
        }
    }

    private function lock(Model $legacy, int|string $key): null|Model
    {
        $query = $this->successor($this->map->primary->model)->newModelQuery()->where((string) $this->map->identifiers[$legacy->getKeyName()]->column(), $key);

        foreach (ViewCompiler::hidden($this->map) as $condition) {
            $query->whereRaw('NOT COALESCE(('.$condition.'), false)');
        }

        return $query->lockForUpdate()->first();
    }

    private function identify(Model $legacy, Model $primary): int|string
    {
        $columns = [];

        foreach ($this->map->identifiers as $name => $identifier) {
            $columns[$name] = (string) $identifier->column();
        }

        $identity = (array) $primary->newModelQuery()->whereKey($primary->getKey())->toBase()->first(array_values($columns));
        $primary->setRawAttributes(array_replace($primary->getAttributes(), $identity), true);
        $key = $identity[$columns[$legacy->getKeyName()]] ?? null;

        return is_int($key) || is_string($key) ? $key : throw new LogicException("{$this->map->primary->table}.{$columns[$legacy->getKeyName()]} read back no key for the new ".class_basename($legacy).'.');
    }

    /**
     * @param  array<string, array<string, mixed>>  $stored
     * @return array{array<string, Model>, list<Model>, list<Related>}
     */
    private function locate(Model $primary, array $stored): array
    {
        $parents = [];
        $reached = [];
        $models = [$this->map->primary->table => $primary];
        $existing = [];
        $missing = [];

        foreach ($this->map->related as $related) {
            $parents[$related->table] = $related->parentTable;
        }

        foreach (array_keys($stored) as $table) {
            for ($reach = $table; array_key_exists($reach, $parents); $reach = $parents[$reach]) {
                $reached[$reach] = true;
            }
        }

        foreach ($this->map->related as $related) {
            if (! array_key_exists($related->table, $reached)) {
                continue;
            }

            $parent = $models[$related->parentTable] ?? null;
            $model = $parent === null ? null : $this->relation($related, $parent)->getQuery()->lockForUpdate()->first();

            if ($model === null) {
                $missing[] = $related;

                continue;
            }

            $models[$related->table] = $model;
            $existing[] = $model;
        }

        return [$models, $existing, $missing];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function savePrimary(Model $legacy, Model $primary, array $values): void
    {
        $deletedAt = method_exists($primary, 'getDeletedAtColumn') ? (string) $primary->getDeletedAtColumn() : null;

        if (! method_exists($primary, 'restore') || $deletedAt === null || ! array_key_exists($deletedAt, $values) || (($primary->getAttributes()[$deletedAt] ?? null) === null) === ($values[$deletedAt] === null)) {
            $this->assign($primary, $values);
            $this->saveEach($legacy, [$primary]);

            return;
        }

        if ($values[$deletedAt] === null) {
            $this->assign($primary, array_diff_key($values, [$deletedAt => true]));
            $this->save($legacy, $primary, fn () => $primary->restore());

            return;
        }

        $updatedAt = $primary->usesTimestamps() ? $primary->getUpdatedAtColumn() : null;

        if (array_diff_key($values, array_flip(array_filter([$deletedAt, $updatedAt], fn (null|string $column) => $column !== null))) !== []) {
            $this->assign($primary, array_diff_key($values, [$deletedAt => true]));
            $this->saveEach($legacy, [$primary]);
        }

        $this->save($legacy, $primary, fn () => $primary->delete());
        $stamped = array_filter(
            array_intersect_key($values, array_flip(array_filter([$deletedAt, $updatedAt], fn (null|string $column) => $column !== null))),
            fn (mixed $value, string $column) => $value !== ($primary->getAttributes()[$column] ?? null),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($stamped !== []) {
            $primary->newQueryWithoutScopes()->toBase()->where($primary->getKeyName(), $primary->getKey())->update($stamped);
            $primary->setRawAttributes(array_replace($primary->getAttributes(), $stamped));
            $primary->syncOriginalAttributes(array_keys($stamped));
        }
    }

    /**
     * @param  array<string, Model>  $models
     * @param  list<Related>  $missing
     * @param  array<string, array<string, mixed>>  $stored
     * @param  array<string, array<string, array{string, mixed}>>  $states
     */
    private function create(Model $legacy, int|string $key, array $models, array $missing, array $stored, array $states): void
    {
        foreach ($missing as $related) {
            $values = $stored[$related->table] ?? [];

            if (array_filter($values, fn (mixed $value) => $value !== null) === []) {
                continue;
            }

            $parent = $models[$related->parentTable] ?? throw CompatibilityWriteRefused::noParentRow($legacy::class, $this->written($related, $values), $related->table, $related->parentTable);
            $relation = $this->relation($related, $parent);
            $created = $relation->getQuery()->lockForUpdate()->first();

            if ($created !== null) {
                $transitioned = array_intersect_key($states, [$related->table => true]);
                $this->transition($legacy, $key, [$related->table => $created], $transitioned, []);
                $this->assign($created, array_diff_key($values, $transitioned[$related->table] ?? []));
                $this->saveEach($legacy, [$created]);
                $models[$related->table] = $created;

                continue;
            }

            if (! $relation instanceof HasOneOrMany) {
                throw CompatibilityWriteRefused::noRelatedRow($legacy::class, $this->written($related, $values), $related->table, $related->parentTable);
            }

            $model = $relation->make();
            $this->assign($model, $values);
            $this->save($legacy, $model, fn () => $model->save());
            $models[$related->table] = $model;
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private function written(Related $related, array $values): array
    {
        return array_keys(array_filter($related->successor->attributes(), fn (Attribute $attribute) => array_key_exists((string) $attribute->column(), $values)));
    }

    /**
     * @param  list<Model>  $models
     */
    private function saveEach(Model $legacy, array $models): void
    {
        foreach ($models as $model) {
            if ($model->isDirty()) {
                $this->save($legacy, $model, fn () => $model->save());
            }
        }
    }

    /**
     * @param  Closure(): mixed  $write
     */
    private function save(Model $legacy, Model $successor, Closure $write): void
    {
        if (Applying::run($legacy, $successor, $write) === false) {
            throw CompatibilityWriteRefused::bySuccessorListener($legacy::class, $successor);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function assign(Model $model, array $values): void
    {
        if ($values !== []) {
            $model->setRawAttributes(array_replace($model->getAttributes(), $values));
        }
    }

    /**
     * @return Relation<Model, Model, mixed>
     */
    private function relation(Related $related, Model $parent): Relation
    {
        $relation = ($related->relation)($parent);

        return $relation instanceof Relation ? $relation : throw new LogicException('The related() relation from '.class_basename($related->parent).' to '.class_basename($related->model).' returns no relation.');
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function successor(string $class): Model
    {
        return (new $class)->setConnection($this->connection->getName());
    }

    private static function keyOf(Model $model): int|string
    {
        $key = $model->getRawOriginal($model->getKeyName()) ?? $model->getKey();

        return is_int($key) || is_string($key) ? $key : throw new LogicException(class_basename($model).' has no key to write by.');
    }
}
