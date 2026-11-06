<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Expression as RawExpression;
use LogicException;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Related;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;

final readonly class RecordWriter
{
    /**
     * @param  list<string>  $external
     */
    public function __construct(private ResolvedMap $map, private Connection $connection, private array $external = [], private bool $moving = false) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public function insert(Model $legacy, array $values): int|string
    {
        Applying::unidentified($legacy);

        if ($this->map->morphs !== []) {
            $this->refuseRaw($values);

            return (new MassWriter($this->map, $this->connection))->transaction(function () use ($legacy, $values): int|string {
                $values = $this->evaluateRetained($legacy, null, $this->withRetainedDefaults($values));
                $variant = $this->map->morphFor(self::probe($legacy, $values));
                $key = $this->through($variant)->insert($legacy, $values);
                $this->refuseStoredMorph($legacy, null, $key, $variant);

                return $key;
            });
        }

        return (new MassWriter($this->map, $this->connection))->transaction(function () use ($legacy, $values): int|string {
            [$stored, $retained, $states, $external] = $this->split($legacy, $values, null);
            $primary = $this->successor($this->map->primary->model);
            $table = $this->map->primary->table;
            $supplied = $retained[$this->map->successorKey] ?? null;
            unset($retained[$this->map->successorKey]);

            if ($supplied !== null) {
                $this->refuseNamedRoot(
                    legacy: $legacy,
                    root: $supplied,
                );
                $primary->setAttribute(
                    key: $this->map->primary->key,
                    value: $supplied,
                );
            }

            $stored = $this->transition($legacy, null, [$table => $primary], array_intersect_key($states, [$table => true]), $stored);
            $this->assign($primary, $stored[$table] ?? []);
            $this->writeExternal($primary, $external);
            $known = $this->createTarget($legacy, $primary, $stored, $external, array_filter([$this->map->target]));
            $this->save($legacy, $primary, fn () => $primary->save());
            $key = $this->identify(
                legacy: $legacy,
                primary: $primary,
                retained: $retained,
            );
            [$models, $existing, $missing] = $this->locate($primary, $stored + $states + $this->reachedBy($external), $known);
            $stored = $this->transition($legacy, $key, $models, array_diff_key($states, [$table => true]), $stored);
            $this->assignEach($models, $stored);
            $this->saveRelated($legacy, $existing, $external);
            $this->create($legacy, $key, $models, $missing, $stored, $states, $external);
            $this->refuseRepointed($legacy, $primary, null, $known, []);
            $this->readBack($legacy, $key, array_keys($values));
            $this->readBackExternal($legacy, $key, array_keys($external));
            ExternalAttributes::refreshed($legacy, $key);

            return $key;
        });
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(Model $legacy, array $values, bool $deleting = false, bool $restoring = false): int
    {
        if ($this->map->morphs !== []) {
            return (new MassWriter($this->map, $this->connection))->transaction(fn (): int => $this->updateMorph($legacy, $values, $deleting, $restoring));
        }

        return (new MassWriter($this->map, $this->connection))->transaction(function () use ($legacy, $values, $deleting, $restoring): int {
            $key = self::keyOf($legacy);
            $primary = $this->lock($legacy, $key);

            if ($primary === null) {
                return 0;
            }

            $original = $primary->getRawOriginal();
            [$stored, $retained, $states, $external] = $this->split($legacy, $values, $key);
            $retained = $this->guard($legacy, $primary, $key, $values, [], $retained, $this->moving ? $this->map : null);
            $this->retain($key, $primary, $retained, []);
            [$models, $existing, $missing] = $this->locate($primary, $stored + $states + $this->reachedBy($external));
            $stored = $this->transition($legacy, $key, $models, $states, $stored);
            $this->assignEach($models, $stored);
            $created = $this->createTarget($legacy, $primary, $stored, $external, $missing);
            [$touched, $pending] = $this->writeExternalOnto($primary, $stored[$this->map->primary->table] ?? [], $external);
            $this->savePrimary($legacy, $primary, $original, $pending, $deleting, $restoring, $touched);
            $this->saveRelated($legacy, $existing, $external);
            $this->create($legacy, $key, $models, $missing, $stored, $states, $external);
            $this->refuseRepointed($legacy, $primary, $key, $created, $original);
            $after = self::keyAfter(
                legacy: $legacy,
                key: $key,
                values: $values,
            );
            $this->readBack($legacy, $after, array_keys($values));
            $this->readBackExternal($legacy, $after, array_keys($external));
            self::rebind($legacy, $key, $after);

            return 1;
        });
    }

    /**
     * @param  array<string, float|int|numeric-string>  $amounts
     * @param  array<string, mixed>  $extra
     */
    public function increment(Model $legacy, array $amounts, array $extra): int
    {
        if ($this->map->morphs !== []) {
            return (new MassWriter($this->map, $this->connection))->transaction(fn (): int => $this->incrementMorph($legacy, $amounts, $extra));
        }

        return (new MassWriter($this->map, $this->connection))->transaction(function () use ($legacy, $amounts, $extra): int {
            $key = self::keyOf($legacy);
            $primary = $this->lock($legacy, $key);

            if ($primary === null) {
                return 0;
            }

            $original = $primary->getRawOriginal();
            [$stored, $retained, $states, $external] = $this->split($legacy, $extra, $key);
            [$added, $retainedAmounts] = (new MassWriter($this->map, $this->connection))->amounts($amounts, $key);
            $retained = $this->guard($legacy, $primary, $key, $extra, $amounts, $retained);
            $this->retain($key, $primary, $retained, array_diff_key($retainedAmounts, $retained));
            [$models, $existing, $missing] = $this->locate($primary, $stored + $states + array_fill_keys(array_keys($added), []) + $this->reachedBy($external));
            $stored = $this->transition($legacy, $key, $models, $states, $stored);
            $this->assignEach($models, $stored);
            $this->addEach($models, $added, $stored);
            $created = $this->createTarget($legacy, $primary, $stored, $external, $missing);
            [$touched, $pending] = $this->writeExternalOnto($primary, $stored[$this->map->primary->table] ?? [], $external);
            $this->savePrimary($legacy, $primary, $original, $pending, false, false, $touched);
            $this->saveRelated($legacy, $existing, $external);
            $this->create($legacy, $key, $models, $missing, $stored, $states, $external);
            $this->refuseRepointed($legacy, $primary, $key, $created, $original);
            $after = self::keyAfter(
                legacy: $legacy,
                key: $key,
                values: array_key_exists(
                    key: $legacy->getKeyName(),
                    array: $amounts,
                ) && is_int($key) ? [$legacy->getKeyName() => $key + (int) $amounts[$legacy->getKeyName()]] : $extra,
            );
            $this->readBack($legacy, $after, [...array_keys($amounts), ...array_keys($extra)]);
            $this->readBackExternal($legacy, $after, array_keys($external));
            self::rebind($legacy, $key, $after);

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

            ExternalAttributes::resolveAll($legacy);
            $this->save($legacy, $primary, fn () => $primary->forceDelete());

            return 1;
        });
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function updateMorph(Model $legacy, array $values, bool $deleting, bool $restoring): int
    {
        $this->refuseRaw($values);
        $key = self::keyOf($legacy);
        $primary = $this->lock($legacy, $key);

        if ($primary === null) {
            return 0;
        }

        $type = $primary->getAttributes()[(string) $this->map->morph?->typeColumn] ?? null;
        $current = $this->map->morphHolding($type);
        $before = $this->before($legacy, $key);
        $target = $this->map->morphFor(self::probe($legacy, array_replace($before, $values)));

        if (! $this->writesTarget($values) && ! $this->holds($current, $primary)) {
            return $this->through($current ?? $target)->update($legacy, $values, $deleting, $restoring);
        }

        if ($current === null) {
            $this->refuseUnsupplied($legacy, $key, $type, $target, $values);
        }

        if ($target === $current) {
            $updated = $this->through($current)->update($legacy, $values, $deleting, $restoring);
            $this->refuseStoredMorph($legacy, $key, self::keyAfter($legacy, $key, $values), $target);

            return $updated;
        }

        $updated = (new MorphMove($this->map, $this->connection))->run($legacy, $primary, $current, $target, $before, $values, $deleting, $restoring);
        $stored = self::keyAfter($legacy, $key, $values);
        $this->refuseStoredMorph($legacy, $key, $stored, $target);
        $this->readBack($legacy, $stored, array_keys(array_intersect_key($before, $legacy->getAttributes() + $values)));
        $this->readBackExternal($legacy, $stored, [...array_keys($legacy->getAttributes()), ...array_keys($values)]);
        ExternalAttributes::refreshed($legacy, $stored);

        return $updated;
    }

    /**
     * @param  array<string, float|int|numeric-string>  $amounts
     * @param  array<string, mixed>  $extra
     */
    private function incrementMorph(Model $legacy, array $amounts, array $extra): int
    {
        $this->refuseRaw($extra);
        $key = self::keyOf($legacy);
        $primary = $this->lock($legacy, $key);

        if ($primary === null) {
            return 0;
        }

        $holding = $this->map->morphHolding($primary->getAttributes()[(string) $this->map->morph?->typeColumn] ?? null);
        $current = $holding ?? $this->map->morphFor(self::probe($legacy, $this->before($legacy, $key)));
        $held = $this->holds($holding, $primary);
        $incremented = $this->through($current)->increment($legacy, $amounts, $extra);

        if ($held) {
            $this->refuseStoredMorph($legacy, $key, self::keyAfter($legacy, $key, $extra), $current);
        }

        return $incremented;
    }

    private function holds(null|ResolvedMap $variant, Model $primary): bool
    {
        $related = $variant?->target;

        return $related !== null && $this->morphTarget($related, $primary) !== null;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function writesTarget(array $values): bool
    {
        foreach ($values as $name => $value) {
            if ($value !== null && $this->map->isMorphed((string) $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function refuseUnsupplied(Model $legacy, int|string $key, mixed $type, ResolvedMap $variant, array $values): void
    {
        $related = $variant->target ?? throw new LogicException("Compatibility map '{$this->map->table}' moves a record into no relatedMorph() target.");
        $columns = (new ColumnCatalog($this->connection, throughSearchPath: true))->of($related->table);
        $unsupplied = [];
        $required = [];

        foreach ($variant->attributes as $name => $attribute) {
            $column = $attribute->column();

            if ($variant->owners[$name] === $related->successor && $attribute->isWritable() && $column !== null && ($columns[$column] ?? null)?->needsAValue() === true && ($values[$name] ?? null) === null) {
                $unsupplied[] = $name;
                $required[] = $related->table.'.'.$column;
            }
        }

        if ($unsupplied !== []) {
            $column = (string) $this->map->morph?->typeColumn;
            $stores = $type === null ? 'no '.$column : $column.' '.self::quoted($type);

            throw CompatibilityWriteRefused::unsupported($legacy::class, 'a write that moves record '.$key.', whose '.class_basename($this->map->primary->model).' stores '.$stores.', into '.class_basename($related->model).' without $'.implode(', $', $unsupplied), 'targets: declares no target for that '.$column.', so the view serves no value for the move to keep, and '.implode(' and ', $required).' needs a value.');
        }
    }

    /**
     * @param  array<string, Model>  $created
     * @param  array<string, mixed>  $original
     */
    private function refuseRepointed(Model $legacy, Model $primary, null|int|string $key, array $created, array $original): void
    {
        $morph = $this->map->morph;
        $related = $this->map->target;

        if ($morph === null || $related === null) {
            return;
        }

        $target = $created[$related->table] ?? null;
        $expected = $target === null ? [$original[$morph->typeColumn] ?? null, $original[$morph->idColumn] ?? null] : [$related->morphAlias, $target->getKey()];
        $table = $this->map->primary->table;
        $stored = (array) $this->connection->table($table)->where($table.'.'.$this->map->primary->key, $primary->getKey())->first([$morph->typeColumn, $morph->idColumn]);
        $pointer = [$stored[$morph->typeColumn] ?? null, $stored[$morph->idColumn] ?? null];
        $record = $key === null ? 'a new record' : 'record '.$key;
        $successor = class_basename($this->map->primary->model);

        if (array_map(self::quoted(...), $pointer) !== array_map(self::quoted(...), $expected)) {
            throw CompatibilityWriteRefused::unsupported($legacy::class, 'a successor listener that repoints '.$record, $successor.' stored '.$morph->typeColumn.' '.self::quoted($pointer[0]).' and '.$morph->idColumn.' '.self::quoted($pointer[1]).' where the write gives '.self::quoted($expected[0]).' and '.self::quoted($expected[1]).', so the record would point at a target the write did not store.');
        }

        if ($target !== null && ! $this->successor($related->model)->newQuery()->whereKey($target->getKey())->exists()) {
            throw CompatibilityWriteRefused::unsupported($legacy::class, 'a successor listener that removes the target of '.$record, $successor.' names '.class_basename($related->model).' '.self::quoted($target->getKey()).', and no such row remains for the record to read.');
        }
    }

    private static function quoted(mixed $value): string
    {
        return is_scalar($value) ? SqlFragments::literal((string) $value) : 'null';
    }

    private function refuseStoredMorph(Model $legacy, null|int|string $key, int|string $stored, ResolvedMap $held): void
    {
        $row = $this->connection->table($this->map->table)->where($legacy->getKeyName(), $stored)->first();
        $chosen = $row === null ? $held : $this->map->morphFor(self::probe($legacy, (array) $row));

        if ($chosen !== $held) {
            $target = class_basename((string) $chosen->target?->model);

            throw CompatibilityWriteRefused::unsupported($legacy::class, 'a write that stores '.($key === null ? 'a new record' : 'record '.$key).' in '.class_basename((string) $held->target?->model).' where relatedMorph() chooses '.$target, 'The view serves the record with values that the relatedMorph() choose: closure reads as a '.$target.', so the record would point at a target its choose: closure does not pick.');
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $stored
     * @param  array<string, mixed>  $external
     * @param  list<Related>  $missing
     * @return array<string, Model>
     */
    private function createTarget(Model $legacy, Model $primary, array $stored, array $external, array $missing): array
    {
        $morph = $this->map->morph;
        $related = $this->map->target;

        if ($morph === null || $related === null || ! in_array($related, $missing, true)) {
            return [];
        }

        if ($primary->exists && ! $this->moving && ! $this->stores($related->table, $stored, $external)) {
            return [];
        }

        $target = $this->successor($related->model);
        $this->assign($target, $stored[$related->table] ?? []);
        $this->writeExternal($target, $external);
        $this->save($legacy, $target, fn () => $target->save());
        $this->assign($primary, [$morph->typeColumn => $related->morphAlias, $morph->idColumn => $target->getKey()]);

        return [$related->table => $target];
    }

    /**
     * @param  array<string, array<string, mixed>>  $stored
     * @param  array<string, mixed>  $external
     */
    private function stores(string $table, array $stored, array $external): bool
    {
        $written = array_filter($external, fn (mixed $value, string $name) => $value !== null && $this->map->owners[$name]->table === $table, ARRAY_FILTER_USE_BOTH);

        return $written !== [] || array_filter($stored[$table] ?? [], fn (mixed $value) => $value !== null) !== [];
    }

    private function morphTarget(Related $related, Model $parent): null|Model
    {
        $attributes = $parent->getAttributes();
        $key = $attributes[$related->parentKey] ?? null;

        if (($attributes[(string) $related->morphType] ?? null) !== $related->morphAlias || $key === null) {
            return null;
        }

        return $this->successor($related->model)->newQuery()->whereKey($key)->lockForUpdate()->first();
    }

    /**
     * @param  array<string, mixed>  $external
     * @return array<string, array{}>
     */
    private function reachedBy(array $external): array
    {
        $reached = [];

        foreach (array_keys($external) as $name) {
            $table = $this->map->owners[$name]->table;

            if ($table !== $this->map->primary->table) {
                $reached[$table] = [];
            }
        }

        return $reached;
    }

    /**
     * @param  list<Model>  $models
     * @param  array<string, mixed>  $external
     */
    private function saveRelated(Model $legacy, array $models, array $external): void
    {
        foreach ($models as $model) {
            $this->saveEach($legacy, [$model], $this->writeExternal($model, $external));
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function keyAfter(Model $legacy, int|string $key, array $values): int|string
    {
        $moved = $values[$legacy->getKeyName()] ?? null;

        return (is_int($moved) || is_string($moved)) && (string) $moved !== (string) $key ? $moved : $key;
    }

    private static function rebind(Model $legacy, int|string $key, int|string $after): void
    {
        if ($after !== $key) {
            ExternalAttributes::refreshed($legacy, $after);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function evaluateRetained(Model $legacy, null|int|string $key, array $values): array
    {
        return array_replace($values, (new MassWriter(
            map: $this->map,
            connection: $this->connection,
        ))->typed(
            values: array_diff_key($values, $this->map->attributes),
            key: $key,
        ));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withRetainedDefaults(array $values): array
    {
        if ($this->map->retainedTable === null) {
            return $values;
        }

        $defaults = [];

        foreach ((new ColumnCatalog($this->connection, throughSearchPath: true))->of($this->map->retainedTable) as $name => $column) {
            if ($column->default !== null && $name !== $this->map->successorKey && ! array_key_exists(
                key: $name,
                array: $values,
            ) && $this->map->legacyOnly([$name]) !== []) {
                $defaults[$name] = new RawExpression('CAST(('.$column->default.') AS '.$column->type.')');
            }
        }

        return $values + $defaults;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function refuseRaw(array $values): void
    {
        foreach ($values as $name => $value) {
            $name = (string) $name;

            if (array_key_exists(
                key: $name,
                array: $this->map->attributes,
            ) && ! $this->map->attributes[$name]->isExternal()) {
                MassWriter::refuseRaw($this->map, $name, $value);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function before(Model $legacy, int|string $key): array
    {
        $row = $this->connection->table($this->map->table)->where($legacy->getKeyName(), $key)->first();

        return $row === null ? throw new LogicException("{$this->map->table} serves no row {$key} for the successor row the write locked.") : (array) $row;
    }

    private function through(ResolvedMap $branch): self
    {
        return new self($branch, $this->connection, $this->map->externals());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function probe(Model $legacy, array $attributes): Model
    {
        return (clone $legacy)->setRawAttributes($attributes);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{array<string, array<string, mixed>>, array<string, mixed>, array<string, array<string, array{string, mixed}>>, array<string, mixed>}
     */
    private function split(Model $legacy, array $values, null|int|string $key): array
    {
        $stored = [];
        $retained = [];
        $states = [];
        $external = [];

        foreach ($values as $name => $value) {
            $name = (string) $name;
            $attribute = $this->map->attributes[$name] ?? null;

            if ($attribute === null && $name === $this->map->successorKey && $key !== null) {
                throw CompatibilityWriteRefused::unsupported(
                    model: $legacy::class,
                    operation: "a write of its successor key {$name} on record {$key}",
                    because: 'The successor key binds the record to its '.class_basename($this->map->primary->model).', and a write that rebinds it would serve another root under the same legacy identity.',
                );
            }

            if ($attribute === null) {
                $retained[$name] = $value;

                continue;
            }

            if ($attribute->isExternal()) {
                $declined = $attribute->declinedBecause();

                if ($declined !== null) {
                    throw CompatibilityWriteRefused::declined($legacy::class, $name, $declined);
                }

                if (MassWriter::computesInSql($value)) {
                    throw CompatibilityWriteRefused::rawExpression($legacy::class, $name, 'It is declared external(), so its value lives outside the database, and no SQL computes it.');
                }

                $external[$name] = $value;

                continue;
            }

            $readOnly = $attribute->readOnlyBecause();

            if ($key !== null && $readOnly !== null) {
                throw CompatibilityWriteRefused::readOnly($legacy::class, $name, $key, $readOnly);
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

        return [$stored, $retained, $states, $external];
    }

    /**
     * @param  array<string, mixed>  $external
     */
    private function writeExternal(Model $successor, array $external): bool
    {
        $written = false;

        foreach ($external as $name => $value) {
            if ($this->map->owners[$name]->table === $successor->getTable()) {
                $this->map->attributes[$name]->writeExternally($successor, $value);
                $written = true;
            }
        }

        return $written;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $external
     * @return array{bool, array<string, mixed>}
     */
    private function writeExternalOnto(Model $primary, array $values, array $external): array
    {
        if ($external === []) {
            return [false, $values];
        }

        $stamps = array_filter([method_exists($primary, 'getDeletedAtColumn') ? (string) $primary->getDeletedAtColumn() : null, $primary->usesTimestamps() ? $primary->getUpdatedAtColumn() : null], fn (null|string $column) => $column !== null);
        $pending = array_diff_key($values, array_flip($stamps));
        $this->assign($primary, $pending);

        return [$this->writeExternal($primary, $external), array_replace($values, array_intersect_key($primary->getAttributes(), $pending))];
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

            if ($key !== null && $model->exists && $model->usesTimestamps()) {
                $this->assign($model, array_intersect_key($stored[$table] ?? [], [(string) $model->getUpdatedAtColumn() => true]));
            }

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
    private function guard(Model $legacy, Model $primary, int|string $key, array $values, array $amounts, array $retained, null|ResolvedMap $target = null): array
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

        $projected = $target === null ? [] : (new MassWriter(
            map: $target,
            connection: $this->connection,
        ))->served(
            values: $values,
            fixed: true,
        ) ?? [];
        $after = clone $before;
        $after->setRawAttributes(array_replace($before->getAttributes(), $projected, $values));

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
    private function evaluate(Model $legacy, null|int|string $key, array $expressions): array
    {
        if ($expressions === []) {
            return [];
        }

        $grammar = $this->connection->getQueryGrammar();
        $selects = [];

        foreach (array_values($expressions) as $index => $expression) {
            $selects[] = '('.$expression->getValue($grammar).') as '.$grammar->wrap('value_'.$index);
        }

        $row = $key === null
            ? (array) $this->connection->selectOne('select '.implode(', ', $selects))
            : (array) $this->connection->table($legacy->getTable())->where($legacy->getKeyName(), $key)->selectRaw(implode(', ', $selects))->first();

        return array_combine(array_keys($expressions), array_values($row));
    }

    /**
     * @param  list<int|string>  $names
     */
    private function readBack(Model $legacy, int|string $key, array $names): void
    {
        $names = array_values(array_filter($names, fn (int|string $name) => ! in_array((string) $name, $this->external, true) && ! $this->map->isExternal((string) $name)));

        if ($names === []) {
            return;
        }

        $row = (array) $this->connection->table($legacy->getTable())->where($legacy->getKeyName(), $key)->first(array_map(fn (int|string $name) => (string) $name, $names));

        if ($row !== []) {
            $legacy->setRawAttributes(array_replace($legacy->getAttributes(), $row));
        }
    }

    /**
     * @param  list<int|string>  $names
     */
    private function readBackExternal(Model $legacy, int|string $key, array $names): void
    {
        $read = [];

        foreach (array_intersect($this->map->externals(), $names) as $name) {
            $read[$name] = ExternalAttributes::values($this->map, $name, [$key], $this->connection->getName(), true)[$key] ?? null;
        }

        if ($read !== []) {
            $legacy->setRawAttributes(array_replace($legacy->getAttributes(), $read));
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, float|int>  $amounts
     */
    private function retain(int|string $key, Model $primary, array $values, array $amounts): void
    {
        $retained = new RetainedRows($this->map, $this->connection);

        if ($amounts === [] && array_filter($values, fn (mixed $value) => $value instanceof Expression) === []) {
            $retained->set(
                key: $key,
                values: $values,
            );

            return;
        }

        $root = $this->map->primary->table.'.'.$this->map->primary->key;
        $retained->update(roots: $this->connection->table($this->map->primary->table)->where(column: $root, operator: '=', value: self::keyOf($primary))->select($root), values: $values, amounts: $amounts);
    }

    /**
     * @param  array<string, Model>  $models
     * @param  array<string, array<string, float|int>>  $added
     * @param  array<string, array<string, mixed>>  $stored
     */
    private function addEach(array $models, array $added, array $stored): void
    {
        $catalog = new ColumnCatalog($this->connection, throughSearchPath: true);
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
        $retained = ViewCompiler::retainedTable($this->map);
        $root = $this->connection->table($retained)->where(column: $retained.'.'.$legacy->getKeyName(), operator: '=', value: $key)->value($retained.'.'.$this->map->successorKey);

        if ($root === null) {
            return null;
        }

        $query = $this->successor($this->map->primary->model)->newModelQuery()->whereKey($root);

        foreach (ViewCompiler::hidden($this->map) as $condition) {
            $query->whereRaw('NOT COALESCE(('.$condition.'), false)');
        }

        return $query->lockForUpdate()->first();
    }

    private function refuseNamedRoot(Model $legacy, mixed $root): void
    {
        $table = $this->map->primary->table;

        if ($this->connection->table($table)->where(
            column: $table.'.'.$this->map->primary->key,
            operator: '=',
            value: $root,
        )->exists()) {
            throw CompatibilityWriteRefused::unsupported(
                model: $legacy::class,
                operation: "a create that names the existing {$this->map->primary->table} row ".(is_scalar($root) ? (string) $root : '')." as its {$this->map->successorKey}",
                because: 'A legacy create creates its own '.class_basename($this->map->primary->model).', and a successor key that names an existing one would bind a second record to it.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $retained
     */
    private function identify(Model $legacy, Model $primary, array $retained): int|string
    {
        $key = (new RetainedRows(
            map: $this->map,
            connection: $this->connection,
        ))->insert(
            root: self::keyOf($primary),
            values: $retained,
        );
        Applying::identified($legacy, $key);

        return $key;
    }

    /**
     * @param  array<string, array<string, mixed>>  $stored
     * @param  array<string, Model>  $known
     * @return array{array<string, Model>, list<Model>, list<Related>}
     */
    private function locate(Model $primary, array $stored, array $known = []): array
    {
        $parents = [];
        $reached = $this->map->target === null || ! $this->moving ? [] : [$this->map->target->table => true];
        $models = [$this->map->primary->table => $primary, ...$known];
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
            if (! array_key_exists($related->table, $reached) || array_key_exists($related->table, $known)) {
                continue;
            }

            $parent = $models[$related->parentTable] ?? null;
            $model = match (true) {
                $parent === null => null,
                $related->morphType !== null => $this->morphTarget($related, $parent),
                default => $this->relation($related, $parent)->getQuery()->lockForUpdate()->first(),
            };

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
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $values
     */
    private function savePrimary(Model $legacy, Model $primary, array $original, array $values, bool $deleting, bool $restoring, bool $touched): void
    {
        $deletedAt = method_exists($primary, 'getDeletedAtColumn') ? (string) $primary->getDeletedAtColumn() : null;
        $trashed = $deletedAt !== null && ($primary->getAttributes()[$deletedAt] ?? null) !== null;
        $moves = method_exists($primary, 'restore') && $deletedAt !== null && array_key_exists($deletedAt, $values)
            && ($values[$deletedAt] === null ? $restoring || $trashed : $deleting || ! $trashed);

        if (! $moves) {
            $this->assign($primary, $values);

            if ($primary->isDirty()) {
                $this->keepTimestamp($primary, $original, $values);
            }

            $this->saveEach($legacy, [$primary], $touched);

            return;
        }

        if ($values[$deletedAt] === null) {
            $this->assign($primary, array_diff_key($values, [$deletedAt => true]));
            $this->keepTimestamp($primary, $original, $values);
            $this->save($legacy, $primary, fn () => $primary->restore());

            return;
        }

        $updatedAt = $primary->usesTimestamps() ? $primary->getUpdatedAtColumn() : null;
        ExternalAttributes::resolveAll($legacy);

        if (array_diff_key($values, array_flip(array_filter([$deletedAt, $updatedAt], fn (null|string $column) => $column !== null))) !== [] || $touched) {
            $this->assign($primary, array_diff_key($values, [$deletedAt => true]));
            $this->saveEach($legacy, [$primary], $touched);
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
     * @param  array<string, mixed>  $external
     */
    private function create(Model $legacy, int|string $key, array $models, array $missing, array $stored, array $states, array $external): void
    {
        foreach ($missing as $related) {
            $values = $stored[$related->table] ?? [];

            if ($related->morphType !== null || (array_filter($values, fn (mixed $value) => $value !== null) === [] && ! array_key_exists($related->table, $this->reachedBy($external)))) {
                continue;
            }

            $parent = $models[$related->parentTable] ?? throw CompatibilityWriteRefused::noParentRow($legacy::class, $this->written($related, $values), $related->table, $related->parentTable);
            $relation = $this->relation($related, $parent);
            $created = $relation->getQuery()->lockForUpdate()->first();

            if ($created !== null) {
                $transitioned = array_intersect_key($states, [$related->table => true]);
                $this->transition($legacy, $key, [$related->table => $created], $transitioned, []);
                $this->assign($created, array_diff_key($values, $transitioned[$related->table] ?? []));
                $this->saveRelated($legacy, [$created], $external);
                $models[$related->table] = $created;

                continue;
            }

            if (! $relation instanceof HasOneOrMany) {
                throw CompatibilityWriteRefused::noRelatedRow($legacy::class, $this->written($related, $values), $related->table, $related->parentTable);
            }

            $model = $relation->make();
            $this->assign($model, $values);
            $this->writeExternal($model, $external);
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
    private function saveEach(Model $legacy, array $models, bool $touched = false): void
    {
        foreach ($models as $model) {
            if ($model->isDirty() || $touched) {
                $this->save($legacy, $model, fn () => $model->save());
            }
        }
    }

    /**
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $values
     */
    private function keepTimestamp(Model $model, array $original, array $values): void
    {
        $column = $model->usesTimestamps() ? $model->getUpdatedAtColumn() : null;

        if ($column === null || ! array_key_exists($column, $values) || $model->isDirty($column)) {
            return;
        }

        $model->setRawAttributes(array_replace($model->getAttributes(), [$column => $original[$column] ?? null]));
        $model->syncOriginalAttribute($column);
        $this->assign($model, [$column => $values[$column]]);
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

        if (! $relation instanceof Relation) {
            throw new LogicException('The related() relation from '.class_basename($related->parent).' to '.class_basename($related->model).' returns no relation.');
        }

        $relation->getRelated()->setConnection($this->connection->getName());
        $query = $relation->getBaseQuery();
        $query->connection = $this->connection;
        $query->grammar = $this->connection->getQueryGrammar();
        $query->processor = $this->connection->getPostProcessor();

        return $relation;
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
