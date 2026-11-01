<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Mapping\Assignment;
use Support\Database\Eloquent\Compatibility\Mapping\DependentCurrentModel;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Mapping\UnrepresentableStateKind;

/**
 * Splits each write through a previous model into writes of its current models and its retained row.
 *
 * Every current row is written through its own Eloquent model. On a model's own write ($events), those models fire
 * their own events, casts are not applied twice, and their listeners may refuse the write by vetoing the save. On a
 * query builder write, the current models save quietly, as Eloquent's own mass writes fire no events.
 *
 * The writer runs inside the write router's transaction. It locks the primary row, then the retained row, then each
 * dependent row it reads, and reads the record through the compatibility view only after those locks.
 */
final readonly class PreviousWriter
{
    public function __construct(
        private WriteRouter $router,
        private ResolvedMap $map,
        private Records $records,
        private bool $events,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed> the record as the previous model reads it
     */
    public function insert(array $row): array
    {
        $this->refuseUnknown(array_keys($row));
        $this->refuseRaw($row);
        $this->refuseNulls($row, null);

        $previous = $this->map->previousModel;
        $primaryAlias = $this->map->primaryAlias();
        $primary = $this->create($primaryAlias, [
            ...$this->insertValues($primaryAlias, $row),
            ...$this->fills($this->map->primaryCurrentModel->fillWhenCreatedFromPrevious, $row),
        ], $row);
        $primaryKey = $this->key($primary);

        $retained = $this->insertValues($this->map->previousAlias(), $row);
        $retained[(string) $previous->primaryForeignKey] = $primaryKey;
        $this->serializeRetained($primaryKey);
        $previousKey = $this->records->connection->table((string) $previous->table)->insertGetId($retained, (string) $previous->keyColumn);
        $previousKey = is_int($previousKey) || is_string($previousKey) ? $previousKey : (string) $previousKey;
        $this->records->refuseStates($this->map, $this->map->previousAlias(), [$previousKey]);

        foreach ($this->map->dependentCurrentModels as $dependent) {
            $assignments = $this->map->insertAssignmentsTo((string) $dependent->alias);

            if (($assignments !== [] || $dependent->fillWhenCreatedFromPrevious !== []) && $this->createdWhen($dependent, $assignments, $row)) {
                $this->createDependent($dependent, $row, $primaryKey, $previousKey);
            }
        }

        return $this->records->viewRow($this->map, $previousKey) ?? [...$row, (string) $previous->keyColumn => $previousKey];
    }

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $changes  receives the record as it stands after the locks
     * @param  null|Closure(): bool  $matches  whether the locked record still matches the write's conditions
     */
    public function update(int|string $key, Closure $changes, null|Closure $matches = null): bool
    {
        $previous = $this->map->previousModel;
        $primaryKey = $this->records->primaryKeyOf($this->map, $key);

        if ($primaryKey === null) {
            return false;
        }

        $this->records->lockPrimaries($this->map, [$primaryKey]);
        $retained = $this->records->retainedRow($this->map, $key, true);

        if ($retained === null) {
            return false;
        }

        $located = [];

        foreach ($this->map->dependentCurrentModels as $dependent) {
            $designation = $dependent->designationColumn === null ? null : $retained[$dependent->designationColumn] ?? null;
            $located[(string) $dependent->alias] = $this->records->locate($this->map, $dependent, $primaryKey, $designation, true);
        }

        $old = $this->records->viewRow($this->map, $key);

        if ($old === null || ($matches !== null && ! $matches())) {
            return false;
        }

        $values = $changes($old);
        $this->refuseUnknown(array_keys($values));
        $this->refuseRaw($values);
        $new = [...$old, ...$values];
        $changed = [];

        foreach ($values as $column => $value) {
            if (! Values::same($old[$column] ?? null, $value, $this->records->columns->type($previous->view, (string) $column))) {
                $changed[(string) $column] = true;
            }
        }

        if ($changed === []) {
            return true;
        }

        foreach ($this->map->readOnlyAttributes() as $attribute) {
            if (isset($changed[$attribute->column])) {
                throw CompatibilityWriteRefused::readOnly($this->map->name, $previous->view, $attribute->column, $key, (string) $attribute->readOnlyBecause);
            }
        }

        $this->refuseNulls(array_intersect_key($new, $changed), $key);

        foreach ($this->map->unrepresentableStates(UnrepresentableStateKind::Refuse) as $state) {
            if ($state->previousUpdateCondition !== null && ($state->previousUpdateCondition)($new, $old) === true) {
                throw CompatibilityWriteRefused::refusedPreviousUpdate($this->map->name, $state->description, $previous->view, $key, $state->because, $state->instead);
            }
        }

        $primaryAlias = $this->map->primaryAlias();
        $primaryValues = $this->changedValues($primaryAlias, $changed, $new);

        if ($primaryValues !== []) {
            $this->apply($this->find($primaryAlias, $primaryKey) ?? throw CompatibilityWriteRefused::missingRow($this->map->name, $previous->view, $key, $this->map->table($primaryAlias)), $primaryValues);
        }

        $retainedValues = $this->changedValues($this->map->previousAlias(), $changed, $new);

        if ($retainedValues !== []) {
            $this->serializeRetained($primaryKey);
            $this->records->connection->table((string) $previous->table)->where((string) $previous->keyColumn, $key)->update($retainedValues);
            $this->records->refuseStates($this->map, $this->map->previousAlias(), [$key]);
        }

        foreach ($this->map->dependentCurrentModels as $dependent) {
            $alias = (string) $dependent->alias;
            $dependentValues = $this->changedValues($alias, $changed, $new);

            if ($dependentValues === []) {
                continue;
            }

            if ($located[$alias] !== null) {
                $this->apply($this->find($alias, $located[$alias]) ?? throw CompatibilityWriteRefused::missingRow($this->map->name, $previous->view, $key, $this->map->table($alias)), $dependentValues);

                continue;
            }

            $columns = array_values(array_unique(array_map(
                fn (Assignment $assignment) => $assignment->attribute->column,
                array_filter($this->map->assignmentsTo($alias), fn (Assignment $assignment) => isset($changed[$assignment->attribute->column])),
            )));
            $designation = $dependent->designationColumn === null ? null : $retained[$dependent->designationColumn] ?? null;

            if ($designation !== null && $this->records->exists($this->map->table($alias), $this->map->keyColumn($alias), $designation)) {
                throw CompatibilityWriteRefused::designationMismatch($this->map->name, $previous->view, $key, $columns, $this->map->table($alias), $previous->table.'.'.$dependent->designationColumn, $dependent->readableWhen);
            }

            if (! $this->createdWhen($dependent, $this->map->assignmentsTo($alias), $new)) {
                throw CompatibilityWriteRefused::noDependentRow($this->map->name, $previous->view, $key, $columns, $this->map->table($alias));
            }

            $this->createDependent($dependent, $new, $primaryKey, $key);
        }

        return true;
    }

    /**
     * @param  null|Closure(): bool  $matches  whether the locked record still matches the write's conditions
     */
    public function delete(int|string $key, null|Closure $matches = null): bool
    {
        $previous = $this->map->previousModel;
        $primaryKey = $this->records->primaryKeyOf($this->map, $key);

        if ($primaryKey === null) {
            return false;
        }

        $this->records->lockPrimaries($this->map, [$primaryKey]);

        if ($this->records->retainedRow($this->map, $key, true) === null || ($matches !== null && ! $matches())) {
            return false;
        }

        $this->records->connection->table((string) $previous->table)->where((string) $previous->keyColumn, $key)->delete();

        foreach (array_reverse($this->map->dependentCurrentModels) as $dependent) {
            $alias = (string) $dependent->alias;
            $rows = $this->newQuery($alias)
                ->where($this->map->primaryForeignKey($dependent), $primaryKey)
                ->orderBy($this->map->keyColumn($alias))
                ->get()
                ->all();

            foreach ($this->referencingFirst($alias, $rows) as $row) {
                $this->remove($row);
            }
        }

        $primary = $this->find($this->map->primaryAlias(), $primaryKey);

        if ($primary !== null) {
            $this->remove($primary);
        }

        return true;
    }

    /**
     * Orders a record's dependent rows so each row goes before any row of the same table it references, as one
     * DELETE statement would check its foreign keys only at its end.
     *
     * @param  list<Model>  $rows
     * @return list<Model>
     */
    private function referencingFirst(string $alias, array $rows): array
    {
        $references = $this->records->selfReferences($this->map->table($alias));

        if ($references === [] || count($rows) < 2) {
            return $rows;
        }

        $ordered = [];

        while ($rows !== []) {
            $leaves = array_filter($rows, fn (Model $row) => ! array_any(
                $rows,
                fn (Model $other) => $other !== $row && array_any($references, fn (string $column) => (string) $other->getRawOriginal($column) === (string) $row->getKey()),
            ));
            $next = $leaves === [] ? $rows : $leaves;

            foreach ($next as $row) {
                $ordered[] = $row;
            }

            $rows = array_values(array_filter($rows, fn (Model $row) => ! in_array($row, $next, true)));
        }

        return $ordered;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function apply(Model $model, array $values): void
    {
        $deletedAt = method_exists($model, 'getDeletedAtColumn') ? (string) $model->getDeletedAtColumn() : null;

        if ($deletedAt !== null && array_key_exists($deletedAt, $values) && ($model->getRawOriginal($deletedAt) === null) !== ($values[$deletedAt] === null)) {
            $this->changeTrashed($model, $deletedAt, $values);

            return;
        }

        $model->setRawAttributes([...$model->getAttributes(), ...$values]);

        if ($model->isDirty()) {
            $this->save($model);
        }
    }

    /**
     * Soft-deletes or restores a current model through its own methods, so it fires its own deleting, trashed,
     * restoring and restored events, as it would when the application changed it directly.
     *
     * @param  array<string, mixed>  $values
     */
    private function changeTrashed(Model $model, string $deletedAt, array $values): void
    {
        $updatedAt = $model->usesTimestamps() ? $model->getUpdatedAtColumn() : null;

        if ($values[$deletedAt] === null) {
            $model->setRawAttributes([...$model->getAttributes(), ...array_diff_key($values, [$deletedAt => true])]);
            $this->run($model, fn () => method_exists($model, 'restore') ? $model->restore() : $model->save());

            return;
        }

        $others = array_diff_key($values, array_filter([$deletedAt => true, (string) $updatedAt => true], fn (string $column) => $column !== '', ARRAY_FILTER_USE_KEY));

        if ($others !== []) {
            $model->setRawAttributes([...$model->getAttributes(), ...$others]);
            $this->save($model);
        }

        $this->run($model, fn () => $model->delete());
        $exact = array_intersect_key($values, array_filter([$deletedAt => true, (string) $updatedAt => true], fn (string $column) => $column !== '', ARRAY_FILTER_USE_KEY));
        $drifted = array_filter($exact, fn (mixed $value, string $column) => ! Values::same($model->getAttributes()[$column] ?? null, $value, $this->records->columns->type($model->getTable(), $column)), ARRAY_FILTER_USE_BOTH);

        if ($drifted !== []) {
            $model->setRawAttributes([...$model->getAttributes(), ...$drifted]);
            $timestamps = $model->timestamps;
            $model->timestamps = false;

            try {
                $this->router->applying($model, $this->map->name, fn () => Model::withoutEvents(fn () => $model->save()));
            } finally {
                $model->timestamps = $timestamps;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $attributes  the previous model's raw attributes, for the model's onCreate() hooks
     */
    private function create(string $alias, array $values, array $attributes): Model
    {
        $model = $this->newModel($alias);
        $model->setRawAttributes($values);
        $current = $alias === $this->map->primaryAlias() ? $this->map->primaryCurrentModel : $this->map->dependent($alias);

        foreach ($current->creating ?? [] as $hook) {
            $hook($model, $attributes);
        }

        $this->save($model);

        return $model;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createDependent(DependentCurrentModel $dependent, array $attributes, int|string $primaryKey, int|string $previousKey): void
    {
        $alias = (string) $dependent->alias;
        $model = $this->create($alias, [
            ...$this->insertValues($alias, $attributes),
            ...$this->fills($dependent->fillWhenCreatedFromPrevious, $attributes),
            $this->map->primaryForeignKey($dependent) => $primaryKey,
        ], $attributes);

        if ($dependent->designationColumn !== null) {
            $previous = $this->map->previousModel;
            $this->records->connection->table((string) $previous->table)
                ->where((string) $previous->keyColumn, $previousKey)
                ->update([$dependent->designationColumn => $this->key($model)]);
        }
    }

    private function save(Model $model): void
    {
        $this->run($model, fn () => $model->save());
    }

    private function remove(Model $model): void
    {
        $this->run($model, fn () => method_exists($model, 'forceDelete') ? $model->forceDelete() : $model->delete());
    }

    /**
     * @param  Closure(): mixed  $write
     */
    private function run(Model $model, Closure $write): void
    {
        $result = $this->router->applying($model, $this->map->name, fn () => $this->events ? $write() : Model::withoutEvents($write));

        if ($result === false) {
            throw CompatibilityWriteRefused::byCurrentListener($this->map->name, $model);
        }
    }

    private function find(string $alias, int|string $key): null|Model
    {
        return $this->newQuery($alias)->whereKey($key)->first();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Model>
     */
    private function newQuery(string $alias): \Illuminate\Database\Eloquent\Builder
    {
        return $this->newModel($alias)->newQueryWithoutScopes();
    }

    private function newModel(string $alias): Model
    {
        $class = (string) array_search($alias, $this->map->currentModels(), true);
        $model = new $class;
        $model->setConnection($this->records->connection->getName());

        return $model;
    }

    private function key(Model $model): int|string
    {
        $key = $model->getKey();

        return is_int($key) || is_string($key) ? $key : (string) $key;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function insertValues(string $alias, array $attributes): array
    {
        $columns = $this->records->columns->of($this->map->table($alias));
        $values = [];

        foreach ($this->map->insertAssignmentsTo($alias) as $assignment) {
            if (! array_key_exists($assignment->attribute->column, $attributes)) {
                continue;
            }

            $column = $columns[$assignment->targetColumn->column] ?? null;

            if ($column !== null && $column->alwaysGenerated) {
                continue;
            }

            $value = $assignment->targetColumn->value($attributes);

            if ($value === null && $column !== null && $column->notNull && $column->default !== null) {
                continue;
            }

            $values[$assignment->targetColumn->column] = $value;
        }

        return $values;
    }

    /**
     * @param  array<string, true>  $changed
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function changedValues(string $alias, array $changed, array $attributes): array
    {
        $values = [];

        foreach ($this->map->assignmentsTo($alias) as $assignment) {
            if (isset($changed[$assignment->attribute->column])) {
                $values[$assignment->targetColumn->column] = $assignment->targetColumn->value($attributes);
            }
        }

        return $values;
    }

    /**
     * @param  array<string, Closure(array<string, mixed>): mixed>  $fills
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function fills(array $fills, array $attributes): array
    {
        $values = [];

        foreach ($fills as $column => $fill) {
            $value = $fill($attributes);

            if ($value !== null) {
                $values[$column] = $value;
            }
        }

        return $values;
    }

    /**
     * @param  list<Assignment>  $assignments
     * @param  array<string, mixed>  $attributes
     */
    private function createdWhen(DependentCurrentModel $dependent, array $assignments, array $attributes): bool
    {
        if ($dependent->createdWhen !== null) {
            return ($dependent->createdWhen)($attributes) === true;
        }

        $columns = array_unique(array_map(fn (Assignment $assignment) => $assignment->attribute->column, $assignments));

        return $columns === [] || array_any($columns, fn (string $column) => ($attributes[$column] ?? null) !== null);
    }

    private function serializeRetained(int|string $primaryKey): void
    {
        foreach ($this->records->refusedStatesOn($this->map, $this->map->previousAlias()) as $state) {
            $this->records->serialize($this->map, $state, $primaryKey);
        }
    }

    /**
     * @param  list<array-key>  $columns
     */
    private function refuseUnknown(array $columns): void
    {
        $unknown = array_values(array_filter(array_map(strval(...), $columns), fn (string $column) => $this->map->attribute($column) === null));

        if ($unknown !== []) {
            throw CompatibilityWriteRefused::unknownAttributes($this->map->name, $this->map->previousModel->view, $unknown);
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function refuseRaw(array $values): void
    {
        foreach ($values as $column => $value) {
            if ($value instanceof Expression) {
                throw CompatibilityWriteRefused::rawExpression($this->map->name, $this->map->previousModel->view, (string) $column);
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function refuseNulls(array $values, null|int|string $key): void
    {
        $previous = $this->map->previousModel;

        foreach ($values as $column => $value) {
            $attribute = $this->map->attribute((string) $column);

            if ($value === null && $attribute !== null && ! $attribute->retained && $this->records->columns->notNull((string) $previous->table, $attribute->column)) {
                throw CompatibilityWriteRefused::nullValue($this->map->name, $previous->view, $attribute->column, $key, (string) $previous->table);
            }
        }
    }
}
