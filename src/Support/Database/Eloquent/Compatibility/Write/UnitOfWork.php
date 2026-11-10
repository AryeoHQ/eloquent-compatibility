<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use BackedEnum;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use LogicException;
use Support\Database\Eloquent\Compatibility\Contracts\InitializesSuccessors;
use Support\Database\Eloquent\Compatibility\Contracts\ScopesWrites;
use Support\Database\Eloquent\Compatibility\Contracts\WritesAttribute;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\DividedBy;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\MultipliedBy;
use Support\Database\Eloquent\Compatibility\Definition\Related;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Events\WriteIgnored;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;

final readonly class UnitOfWork
{
    public function __construct(private ResolvedMap $map, private Connection $connection) {}

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

    public function scopes(): bool
    {
        return $this->map->scope !== null;
    }

    /**
     * @template TResult
     *
     * @param  array<class-string<Model>, list<int|string>>  $touched
     * @param  Closure(): TResult  $write
     * @return TResult
     */
    public function scoped(array $touched, Closure $write): mixed
    {
        $scope = $this->map->scope;

        if ($scope === null) {
            return $write();
        }

        $instance = Container::getInstance()->make($scope);

        if (! $instance instanceof ScopesWrites) {
            throw new LogicException("The write scope {$scope} does not implement ".ScopesWrites::class.'.');
        }

        return $instance->run($touched, $write);
    }

    /**
     * @param  array<array-key, Staging<Model>>  $graphs
     * @return array<class-string<Model>, list<int|string>>
     */
    public function touched(array $graphs): array
    {
        $touched = [];

        foreach ($graphs as $graph) {
            $root = $graph->model($graph->map->primary->table);
            $touched[$graph->map->primary->model] ??= [];

            if ($root !== null && $root->exists) {
                $touched[$graph->map->primary->model][] = self::keyOf($root);
            }

            foreach ($graph->map->related as $related) {
                $touched[$related->model] ??= [];
                $model = $graph->model($related->table);

                if ($model !== null && $model->exists) {
                    $touched[$related->model][] = self::keyOf($model);
                }
            }
        }

        foreach ($this->map->morphs as $variant) {
            if ($variant->target !== null) {
                $touched[$variant->target->model] ??= [];
            }
        }

        foreach ($touched as $class => $keys) {
            $keys = array_values(array_unique($keys, SORT_REGULAR));
            sort($keys);
            $touched[$class] = $keys;
        }

        ksort($touched);

        return $touched;
    }

    /**
     * @param  Staging<Model>  $graph
     * @param  array<string, mixed>  $values
     * @return Staging<Model>
     */
    public function links(Staging $graph, array $values, bool $lock): Staging
    {
        $map = $graph->map;
        $named = [];

        foreach ($map->attributes as $name => $attribute) {
            $owner = $map->owners[$name];
            $value = $values[$name] ?? null;

            if ($owner !== $map->primary && $value !== null && $attribute->readOnlyBecause() !== null && $attribute->storesColumn() && $attribute->invertible() && $attribute->column() === $owner->key) {
                $named[$owner->table] = $this->stored($map, $attribute, $name, $value);
            }
        }

        $models = [];

        foreach ($map->related as $related) {
            $parent = $models[$related->parentTable] ?? null;

            if (array_key_exists($related->table, $named)) {
                $query = $this->successor($related->model)->newQueryWithoutScopes()->whereKey($named[$related->table]);
                $models[$related->table] = ($lock ? $query->lockForUpdate() : $query)->first();
            } elseif ($parent !== null && ! $related->belongsTo) {
                $models[$related->table] = $this->find($related, $parent, $lock);
            }
        }

        return new Staging(
            map: $map,
            root: $graph->root(),
            models: $models,
        );
    }

    /**
     * @param  array<class-string<Model>, list<int|string>>  $scoped
     * @param  array<array-key, Staging<Model>>  $graphs
     */
    public function refuseMoved(array $scoped, array $graphs): void
    {
        if ($this->map->scope === null) {
            return;
        }

        $locked = $this->touched($graphs);

        foreach (array_unique([...array_keys($scoped), ...array_keys($locked)]) as $class) {
            $before = array_map(strval(...), $scoped[$class] ?? []);
            $after = array_map(strval(...), $locked[$class] ?? []);

            if ($before !== $after) {
                throw CompatibilityWriteRefused::successorsMoved(
                    model: $this->map->model,
                    successor: $class,
                    scoped: $before,
                    locked: $after,
                );
            }
        }
    }

    /**
     * @param  list<int|string>  $keys
     * @return array<int|string, Model>
     */
    public function roots(array $keys, bool $lock): array
    {
        if ($keys === []) {
            return [];
        }

        $primary = $this->map->primary;
        $query = $this->successor($primary->model)->newModelQuery()->whereKey($keys)->orderBy($primary->table.'.'.$primary->key);

        foreach (ViewCompiler::hidden($this->map) as $condition) {
            $query->whereRaw('NOT COALESCE(('.$condition.'), false)');
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        $roots = [];

        foreach ($query->get() as $root) {
            $roots[self::keyOf($root)] = $root;
        }

        $retained = $this->map->retainedTable;

        if ($lock && $retained !== null && $roots !== []) {
            $successorKey = $retained.'.'.$this->map->successorKey;
            $this->connection->query()->fromSub($this->connection->table($retained)->select($successorKey)->whereIn($successorKey, array_keys($roots))->orderBy($successorKey)->lockForUpdate(), 'locked')->count();
        }

        return $roots;
    }

    /**
     * @param  array<array-key, array{ResolvedMap, Model}>  $roots
     * @return array<array-key, Staging<Model>>
     */
    public function graphs(array $roots, bool $lock): array
    {
        $tables = [];
        $models = [];
        $depth = 0;

        foreach ($roots as $index => [$variant, $root]) {
            $tables[$index] = [$variant->primary->table => $root];
            $models[$index] = [];
            $depth = max($depth, count($variant->related));
        }

        for ($level = 0; $level < $depth; $level++) {
            foreach ($roots as $index => [$variant]) {
                $related = $variant->related[$level] ?? null;

                if ($related === null) {
                    continue;
                }

                $parent = $tables[$index][$related->parentTable] ?? null;
                $model = $parent === null || ! $parent->exists ? null : $this->find($related, $parent, $lock);
                $tables[$index][$related->table] = $model;
                $models[$index][$related->table] = $model;
            }
        }

        $graphs = [];

        foreach ($roots as $index => [$variant, $root]) {
            $graphs[$index] = new Staging(
                map: $variant,
                root: $root,
                models: $models[$index],
            );
        }

        return $graphs;
    }

    /**
     * @param  Staging<Model>  $graph
     * @param  array<string, mixed>  $values
     * @param  array<string, float|int>  $amounts
     */
    public function assign(Staging $graph, Model $record, Model $original, array $values, array $amounts, Origin $origin, bool $creating, bool $deleting = false, bool $restoring = false): void
    {
        $map = $graph->map;
        $skipped = $creating ? [] : $this->trashed($graph, $record, $values, $deleting, $restoring);

        foreach ($map->attributes as $name => $attribute) {
            foreach ($values as $key => $value) {
                $key = (string) $key;

                if (! str_starts_with($key, $name.'->')) {
                    continue;
                }

                if ($attribute->readOnlyBecause() !== null) {
                    $this->readOnlyPath(
                        graph: $graph,
                        attribute: $attribute,
                        change: new Change(
                            record: $record,
                            original: $original,
                            attribute: $name,
                            origin: $origin,
                        ),
                        value: $value,
                    );

                    continue;
                }

                $this->assignPath($graph, $attribute, $name, substr($key, strlen($name) + 2), $value);
            }

            if (! array_key_exists(
                key: $name,
                array: $values,
            ) || array_key_exists(
                key: $name,
                array: $skipped,
            )) {
                continue;
            }

            $value = $values[$name];
            $change = new Change(
                record: $record,
                original: $original,
                attribute: $name,
                origin: $origin,
            );

            if ($attribute->readOnlyBecause() !== null) {
                $this->readOnly($graph, $attribute, $name, $value, $change, $creating);

                continue;
            }

            $operation = $attribute->operation();

            if ($operation !== null) {
                $this->operation($operation)->write($graph->successors(), $value, $change);

                continue;
            }

            $this->assignInverse(
                graph: $graph,
                attribute: $attribute,
                name: $name,
                value: $value,
            );
        }

        $this->add($graph, $amounts);
    }

    /**
     * @param  Staging<Model>  $graph
     */
    public function initialize(Staging $graph, Model $record, Origin $origin): void
    {
        $initializer = $this->map->successorsInitializer;

        if ($initializer === null) {
            return;
        }

        $instance = Container::getInstance()->make($initializer);

        if (! $instance instanceof InitializesSuccessors) {
            throw new LogicException("The initializer {$initializer} does not implement ".InitializesSuccessors::class.'.');
        }

        $instance->initialize($graph->successors(), new Creation(
            record: $record,
            origin: $origin,
        ));
    }

    /**
     * @param  Staging<Model>  $graph
     */
    public function save(Staging $graph, Model $legacy): void
    {
        $classes = self::timestampedClasses($graph);

        foreach ($graph->held() as $model) {
            if (! $model->exists && $model->usesTimestamps() && in_array($model::class, $classes, true)) {
                $model->updateTimestamps();
            }
        }

        $this->saveNode(
            graph: $graph,
            table: $graph->map->primary->table,
            legacy: $legacy,
            classes: $classes,
        );

        foreach ($graph->deletions() as $model) {
            $this->run($legacy, $model, fn () => self::untimestamped($classes, fn () => $model->delete()));
        }
    }

    /**
     * @template TResult
     *
     * @param  list<class-string<Model>>  $classes
     * @param  Closure(): TResult  $write
     * @return TResult
     */
    private static function untimestamped(array $classes, Closure $write): mixed
    {
        return $classes === [] ? $write() : Model::withoutTimestampsOn($classes, $write);
    }

    /**
     * @param  Staging<Model>  $graph
     * @return list<class-string<Model>>
     */
    private static function timestampedClasses(Staging $graph): array
    {
        return array_values(array_unique(array_map(
            callback: fn (Model $model) => $model::class,
            array: array_values(array_filter($graph->held(), fn (Model $model) => $graph->timestamped($model))),
        )));
    }

    /**
     * @param  Staging<Model>  $graph
     */
    public function act(Staging $graph, Model $legacy): void
    {
        $classes = self::timestampedClasses($graph);

        for ($actions = $graph->actions(); $actions !== []; $actions = $graph->actions()) {
            foreach ($actions as $action) {
                $this->holding(
                    legacy: $legacy,
                    models: $graph->held(),
                    write: fn () => self::untimestamped($classes, $action),
                );
            }
        }
    }

    /**
     * @template TResult
     *
     * @param  list<Model>  $models
     * @param  Closure(): TResult  $write
     * @return TResult
     */
    public function holding(Model $legacy, array $models, Closure $write): mixed
    {
        $model = array_shift($models);

        if ($model === null) {
            return $write();
        }

        return Applying::run($legacy, $model, fn () => $this->holding($legacy, $models, $write));
    }

    /**
     * @param  Closure(): mixed  $write
     */
    public function run(Model $legacy, Model $successor, Closure $write): void
    {
        if (Applying::run($legacy, $successor, $write) === false) {
            throw CompatibilityWriteRefused::bySuccessorListener($legacy::class, $successor);
        }
    }

    /**
     * @param  Staging<Model>  $graph
     */
    private function readOnly(Staging $graph, Attribute $attribute, string $name, mixed $value, Change $change, bool $creating): void
    {
        $stored = $attribute->storesColumn() && $attribute->invertible();

        if ($attribute->ignoresWrites()) {
            if ($creating && $stored && $this->assignsCreated($graph, $attribute, $name, $value)) {
                return;
            }

            if ($value !== null) {
                Event::dispatch(new WriteIgnored(
                    change: $change,
                    value: $value,
                ));
            }

            return;
        }

        if (! $creating) {
            throw CompatibilityWriteRefused::readOnly($graph->map->model, $name, self::keyOf($change->original), (string) $attribute->readOnlyBecause());
        }

        if (! $stored) {
            if ($change->origin === Origin::Live && $value !== null) {
                throw CompatibilityWriteRefused::readOnly($graph->map->model, $name, null, (string) $attribute->readOnlyBecause());
            }

            return;
        }

        $this->assignsCreated($graph, $attribute, $name, $value);
    }

    /**
     * @param  Staging<Model>  $graph
     */
    private function readOnlyPath(Staging $graph, Attribute $attribute, Change $change, mixed $value): void
    {
        if (! $attribute->ignoresWrites()) {
            throw CompatibilityWriteRefused::readOnly(
                model: $graph->map->model,
                attribute: $change->attribute,
                key: self::keyOf($change->original),
                because: (string) $attribute->readOnlyBecause(),
            );
        }

        if ($value !== null) {
            Event::dispatch(new WriteIgnored(
                change: $change,
                value: $value,
            ));
        }
    }

    /**
     * @param  Staging<Model>  $graph
     */
    private function assignsCreated(Staging $graph, Attribute $attribute, string $name, mixed $value): bool
    {
        $owner = $graph->map->owners[$name];
        $stored = $this->stored($graph->map, $attribute, $name, $value);
        $column = (string) $attribute->column();

        if ($owner === $graph->map->primary) {
            if ($graph->root()->exists) {
                return false;
            }

            self::assignRaw($graph->root(), [$column => $stored]);

            return true;
        }

        $model = $graph->model($owner->table);

        if ($model !== null && ! $graph->created($owner->table)) {
            return false;
        }

        if ($stored === null) {
            return $model !== null;
        }

        $related = $this->relatedOf($graph->map, $owner->table);

        if ($column === $owner->key) {
            $existing = $this->successor($related->model)->newQueryWithoutScopes()->whereKey($stored)->lockForUpdate()->first();

            if ($existing !== null) {
                $graph->link($owner->table, $existing);

                return true;
            }
        }

        self::assignRaw($graph->relatedOrNew($related->model), [$column => $stored]);

        return true;
    }

    /**
     * @param  Staging<Model>  $graph
     */
    private function assignInverse(Staging $graph, Attribute $attribute, string $name, mixed $value): void
    {
        $map = $graph->map;
        $owner = $map->owners[$name];
        $stored = $this->stored($map, $attribute, $name, $value);
        $column = $attribute->column();

        if ($column === null) {
            return;
        }

        [$column, $path] = array_pad(explode('->', $column, 2), 2, null);

        if ($owner === $map->primary) {
            $this->assignColumn($graph, $graph->root(), $column, $path, $stored);

            return;
        }

        $model = $graph->model($owner->table);

        if ($model === null && $stored === null) {
            return;
        }

        $model ??= $graph->relatedOrNew($this->relatedOf($map, $owner->table)->model);
        $this->assignColumn($graph, $model, $column, $path, $stored);
    }

    /**
     * @param  Staging<Model>  $graph
     */
    private function assignPath(Staging $graph, Attribute $attribute, string $name, string $path, mixed $value): void
    {
        $map = $graph->map;
        $owner = $map->owners[$name];
        $column = (string) $attribute->column();
        $model = $owner === $map->primary ? $graph->root() : $graph->model($owner->table);

        if ($model === null || ($model->getAttributes()[$column] ?? null) === null) {
            return;
        }

        $model->forceFill([$column.'->'.$path => $value]);
    }

    /**
     * @param  Staging<Model>  $graph
     */
    private function assignColumn(Staging $graph, Model $model, string $column, null|string $path, mixed $stored): void
    {
        if ($path !== null) {
            $model->forceFill([$column.'->'.$path => $stored]);

            return;
        }

        self::assignRaw($model, [$column => is_array($stored) ? json_encode($stored, JSON_THROW_ON_ERROR) : $stored]);

        if ($model->usesTimestamps() && $column === $model->getUpdatedAtColumn()) {
            $graph->keepTimestamp($model);
        }
    }

    /**
     * @param  Staging<Model>  $graph
     * @param  array<string, mixed>  $values
     * @return array<string, true>
     */
    private function trashed(Staging $graph, Model $record, array $values, bool $deleting, bool $restoring): array
    {
        $map = $graph->map;
        $root = $graph->root();

        if (! method_exists($root, 'getDeletedAtColumn') || ! method_exists($root, 'restore')) {
            return [];
        }

        $deletedAt = (string) $root->getDeletedAtColumn();
        $updatedAt = $root->usesTimestamps() ? $root->getUpdatedAtColumn() : null;
        $names = [];

        foreach ($map->attributes as $name => $attribute) {
            if ($map->owners[$name] === $map->primary && $attribute->invertible() && $attribute->operation() === null && $attribute->readOnlyBecause() === null && array_key_exists($name, $values)) {
                $names[(string) $attribute->column()] = $name;
            }
        }

        $name = $names[$deletedAt] ?? null;

        if ($name === null) {
            return [];
        }

        $stored = $this->stored($map, $map->attributes[$name], $name, $values[$name]);
        $trashed = ($root->getAttributes()[$deletedAt] ?? null) !== null;
        $moves = $stored === null ? $restoring || $trashed : $deleting || ! $trashed;

        if (! $moves) {
            return [];
        }

        if ($stored === null) {
            $graph->defer($root);
            $graph->afterSave(function () use ($root, $map): void {
                if ($root->restore() === false) {
                    throw CompatibilityWriteRefused::bySuccessorListener($map->model, $root);
                }
            });

            return [$name => true];
        }

        ExternalAttributes::resolveAll($record);
        $stamp = [$deletedAt => $stored];
        $skipped = [$name => true];
        $updated = $updatedAt === null ? null : $names[$updatedAt] ?? null;

        if ($updatedAt !== null && $updated !== null) {
            $stamp[$updatedAt] = $this->stored($map, $map->attributes[$updated], $updated, $values[$updated]);
            $skipped[$updated] = true;
        }

        $graph->afterSave(function () use ($root, $stamp, $map): void {
            if ($root->delete() === false) {
                throw CompatibilityWriteRefused::bySuccessorListener($map->model, $root);
            }

            $stamped = array_filter(
                array: $stamp,
                callback: fn (mixed $value, string $column) => $value !== ($root->getAttributes()[$column] ?? null),
                mode: ARRAY_FILTER_USE_BOTH,
            );

            if ($stamped !== []) {
                $root->newQueryWithoutScopes()->toBase()->where($root->getKeyName(), $root->getKey())->update($stamped);
                $root->setRawAttributes(array_replace($root->getAttributes(), $stamped));
                $root->syncOriginalAttributes(array_keys($stamped));
            }
        });

        return $skipped;
    }

    /**
     * @param  Staging<Model>  $graph
     * @param  array<string, float|int>  $amounts
     */
    private function add(Staging $graph, array $amounts): void
    {
        $map = $graph->map;
        $catalog = new ColumnCatalog($this->connection, throughSearchPath: true);

        foreach ($amounts as $name => $amount) {
            $attribute = $map->attributes[$name] ?? null;

            if ($attribute === null) {
                continue;
            }

            $column = $attribute->column();
            $linear = array_filter($attribute->conversions(), fn (object $conversion) => ! $conversion instanceof DividedBy && ! $conversion instanceof MultipliedBy) === [];

            if ($column === null || ! $attribute->invertible() || ! $linear || $attribute->operation() !== null || $attribute->readOnlyBecause() !== null) {
                throw CompatibilityWriteRefused::unsupported($map->model, "an increment of \${$name}", 'Its conversions are not linear, so an amount added to it has no one stored amount.');
            }

            $owner = $map->owners[$name];
            $model = $graph->model($owner->table);
            $current = $model?->getAttributes()[$column] ?? null;

            if ($model === null || $current === null) {
                continue;
            }

            $added = $attribute->write($amount);

            if (is_int($current) && is_int($added)) {
                self::assignRaw($model, [$column => $current + $added]);

                continue;
            }

            $type = $catalog->type($owner->table, $column) ?? 'numeric';
            self::assignRaw($model, [$column => $this->connection->scalar('select cast(cast(? as '.$type.') + cast(? as numeric) as '.$type.')', [$current, $added])]);
        }
    }

    /**
     * @param  Staging<Model>  $graph
     * @param  list<class-string<Model>>  $classes
     */
    private function saveNode(Staging $graph, string $table, Model $legacy, array $classes): void
    {
        $model = $graph->model($table);

        if ($model === null) {
            return;
        }

        foreach ($graph->map->related as $related) {
            if ($related->parentTable !== $table || ! $related->belongsTo) {
                continue;
            }

            $child = $graph->model($related->table);

            if ($child === null) {
                continue;
            }

            $this->saveNode(
                graph: $graph,
                table: $related->table,
                legacy: $legacy,
                classes: $classes,
            );

            if ($graph->created($related->table) || $graph->linked($related->table)) {
                self::assignRaw($model, array_filter([
                    $related->parentKey => $child->getAttribute($related->relatedKey),
                    (string) $related->morphType => $related->morphAlias,
                ], fn (mixed $value, string $column) => $column !== '', ARRAY_FILTER_USE_BOTH));
            }
        }

        if ((! $model->exists || $model->isDirty()) && ! $graph->deferred($model)) {
            $this->run($legacy, $model, fn () => self::untimestamped($classes, fn () => $model->save()));
        }

        foreach ($graph->map->related as $related) {
            if ($related->parentTable !== $table || $related->belongsTo) {
                continue;
            }

            $child = $graph->model($related->table);

            if ($child === null) {
                continue;
            }

            if ($graph->created($related->table) && ! $child->exists) {
                $existing = $this->relation($related, $model)->getQuery()->lockForUpdate()->first();

                if ($existing !== null) {
                    self::assignRaw($existing, array_diff_key($child->getAttributes(), [$child->getKeyName() => true]));
                    $graph->link($related->table, $existing);
                    $child = $existing;
                }
            }

            if ($graph->created($related->table) || $graph->linked($related->table)) {
                self::assignRaw($child, [$related->relatedKey => $model->getAttribute($related->parentKey)]);
            }

            $this->saveNode(
                graph: $graph,
                table: $related->table,
                legacy: $legacy,
                classes: $classes,
            );
        }
    }

    private function find(Related $related, Model $parent, bool $lock): null|Model
    {
        if ($related->morphType !== null) {
            $attributes = $parent->getAttributes();
            $key = $attributes[$related->parentKey] ?? null;

            if (($attributes[$related->morphType] ?? null) !== $related->morphAlias || $key === null) {
                return null;
            }

            $query = $this->successor($related->model)->newQuery()->whereKey($key);

            return ($lock ? $query->lockForUpdate() : $query)->first();
        }

        $query = $this->relation($related, $parent)->getQuery();

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /**
     * @return Relation<Model, Model, mixed>
     */
    public function relation(Related $related, Model $parent): Relation
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

    private function stored(ResolvedMap $map, Attribute $attribute, string $name, mixed $value): mixed
    {
        if ($value instanceof Expression) {
            MassWriter::refuseRaw($map, $name, $value);
        }

        try {
            return $attribute->write($value);
        } catch (LogicException $exception) {
            throw CompatibilityWriteRefused::unstorable($map->model, $name, $exception->getMessage());
        }
    }

    /**
     * @param  class-string<WritesAttribute<covariant Model>>  $operation
     * @return WritesAttribute<Model>
     */
    private function operation(string $operation): WritesAttribute
    {
        $instance = Container::getInstance()->make($operation);

        return $instance instanceof WritesAttribute ? $instance : throw new LogicException("The operation {$operation} does not implement ".WritesAttribute::class.'.');
    }

    private function relatedOf(ResolvedMap $map, string $table): Related
    {
        foreach ($map->related as $related) {
            if ($related->table === $table) {
                return $related;
            }
        }

        throw new LogicException("Compatibility map '{$map->table}' reaches no table {$table} through related().");
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function assignRaw(Model $model, array $values): void
    {
        foreach ($values as $column => $value) {
            $values[$column] = $value instanceof BackedEnum ? $value->value : $value;
        }

        if ($values !== []) {
            $model->setRawAttributes(array_replace($model->getAttributes(), $values));
        }
    }

    /**
     * @param  class-string<Model>  $class
     */
    public function successor(string $class): Model
    {
        return (new $class)->setConnection($this->connection->getName());
    }

    public static function keyOf(Model $model): int|string
    {
        $key = $model->getRawOriginal($model->getKeyName()) ?? $model->getKey();

        return is_int($key) || is_string($key) ? $key : throw new LogicException(class_basename($model).' has no key to write by.');
    }
}
