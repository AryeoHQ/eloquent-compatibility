<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use BackedEnum;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
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
    public const int CHUNK = 1000;

    public function __construct(private ResolvedMap $map, private Connection $connection, private bool $locksRetained = true) {}

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

        $instance = self::resolve(
            class: $scope,
            interface: ScopesWrites::class,
            role: 'write scope',
        );
        $scoped = new ScopedWrite($write);
        $result = $instance->run($touched, $scoped(...));

        if ($scoped->failure !== null) {
            throw $scoped->failure;
        }

        if (! $scoped->ran) {
            throw CompatibilityWriteRefused::unscoped(
                model: $this->map->model,
                scope: $scope,
            );
        }

        return $result;
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
     * @param  array<array-key, array<class-string<Model>, list<int|string>>>  $touched
     * @return array<class-string<Model>, list<int|string>>
     */
    public static function merged(array $touched): array
    {
        $merged = [];

        foreach ($touched as $classes) {
            foreach ($classes as $class => $keys) {
                $merged[$class] = [...$merged[$class] ?? [], ...$keys];
            }
        }

        foreach ($merged as $class => $keys) {
            $keys = array_values(array_unique(
                array: $keys,
                flags: SORT_REGULAR,
            ));
            sort($keys);
            $merged[$class] = $keys;
        }

        ksort($merged);

        return $merged;
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
        $names = [];

        foreach ($map->attributes as $name => $attribute) {
            $owner = $map->owners[$name];
            $value = $values[$name] ?? null;

            if ($owner !== $map->primary && $value !== null && $attribute->readOnlyBecause() !== null && $attribute->invertible() && $attribute->column() === $owner->key) {
                $named[$owner->table] = $this->stored($map, $attribute, $name, $value);
                $names[$owner->table] = $name;
            }
        }

        $models = [];
        $linked = [];

        foreach ($map->related as $related) {
            $parent = $models[$related->parentTable] ?? null;

            if (array_key_exists($related->table, $named)) {
                $query = $this->successor($related->model)->newQueryWithoutScopes()->whereKey($named[$related->table]);
                $existing = ($lock ? $query->lockForUpdate() : $query)->first();

                if ($existing !== null) {
                    $this->refuseHeld(
                        map: $map,
                        related: $related,
                        existing: $existing,
                        name: $names[$related->table],
                    );
                    $linked[$related->table] = $existing;
                }

                $models[$related->table] = $existing;
            } elseif ($parent !== null) {
                $models[$related->table] = $this->find($related, $parent, $lock);
            }
        }

        $linking = new Staging(
            map: $map,
            root: $graph->root(),
            models: array_diff_key($models, $linked),
        );

        foreach ($linked as $table => $existing) {
            $linking->link(
                table: $table,
                model: $existing,
            );
        }

        return $linking;
    }

    private function refuseHeld(ResolvedMap $map, Related $related, Model $existing, string $name): void
    {
        if ($related->belongsTo || $related->morphType !== null) {
            return;
        }

        $holder = $existing->getAttributes()[$related->relatedKey] ?? null;

        if ($holder === null || ! $this->successor($related->parent)->newQueryWithoutScopes()->where(
            column: $related->parentKey,
            operator: '=',
            value: $holder,
        )->exists()) {
            return;
        }

        $parent = class_basename($related->parent);

        throw CompatibilityWriteRefused::unsupported(
            model: $map->model,
            operation: "a create that names the {$related->table} row ".self::keyOf($existing)." as its \${$name}",
            because: 'That '.class_basename($related->model)." belongs to {$parent} ".(is_scalar($holder) ? (string) $holder : '').", which still exists. A create links only a row whose {$parent} is gone, because the package never re-keys a related row.",
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

        $query = $this->rootQuery($keys);

        if ($lock) {
            $query->lockForUpdate();
        }

        $roots = [];

        foreach ($query->get() as $root) {
            $roots[self::keyOf($root)] = $root;
        }

        if ($lock) {
            $this->lockRetained(array_keys($roots));
        }

        return $roots;
    }

    /**
     * @param  list<int|string>  $keys
     */
    public function lock(array $keys): void
    {
        sort($keys);
        $primary = $this->map->primary;

        foreach (array_chunk(array: $keys, length: self::CHUNK) as $chunk) {
            $locked = $this->rootQuery($chunk)->lockForUpdate()->toBase()->pluck($primary->table.'.'.$primary->key)->all();
            $this->lockRetained(array_values(array_filter(
                array: $locked,
                callback: fn (mixed $key) => is_int($key) || is_string($key),
            )));
        }
    }

    /**
     * @param  list<int|string>  $keys
     * @return Builder<Model>
     */
    private function rootQuery(array $keys): Builder
    {
        $primary = $this->map->primary;
        $query = $this->successor($primary->model)->newModelQuery()->whereKey($keys)->orderBy($primary->table.'.'.$primary->key);

        foreach (ViewCompiler::hidden($this->map) as $condition) {
            $query->whereRaw('NOT COALESCE(('.$condition.'), false)');
        }

        return $query;
    }

    /**
     * @param  list<int|string>  $roots
     */
    private function lockRetained(array $roots): void
    {
        $retained = $this->map->retainedTable;

        if (! $this->locksRetained || $retained === null || $roots === []) {
            return;
        }

        $successorKey = $retained.'.'.$this->map->successorKey;
        $this->connection->query()->fromSub(
            query: $this->connection->table($retained)->select($successorKey)->whereIn(
                column: $successorKey,
                values: $roots,
            )->orderBy($successorKey)->lockForUpdate(),
            as: 'locked',
        )->count();
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
            $levels = [];

            foreach ($roots as $index => [$variant]) {
                $related = $variant->related[$level] ?? null;

                if ($related === null) {
                    continue;
                }

                $parent = $tables[$index][$related->parentTable] ?? null;
                $levels[spl_object_id($related)] ??= [$related, []];

                if ($parent !== null && $parent->exists) {
                    $levels[spl_object_id($related)][1][$index] = $parent;
                }

                $tables[$index][$related->table] = null;
                $models[$index][$related->table] = null;
            }

            foreach ($levels as [$related, $parents]) {
                foreach ($this->findEach(
                    related: $related,
                    parents: $parents,
                    lock: $lock,
                ) as $index => $model) {
                    $tables[$index][$related->table] = $model;
                    $models[$index][$related->table] = $model;
                }
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
     * @param  array<array-key, Model>  $parents
     * @return array<array-key, Model>
     */
    private function findEach(Related $related, array $parents, bool $lock): array
    {
        $values = [];

        foreach ($parents as $index => $parent) {
            $attributes = $parent->getAttributes();
            $value = $attributes[$related->parentKey] ?? null;

            if ($value !== null && ($related->morphType === null || ($attributes[$related->morphType] ?? null) === $related->morphAlias)) {
                $values[$index] = $value;
            }
        }

        if ($values === []) {
            return [];
        }

        if ($related->morphType !== null) {
            $query = $this->successor($related->model)->newQuery()->whereKey(array_values(array_unique(
                array: $values,
                flags: SORT_REGULAR,
            )));
        } else {
            $relation = Relation::noConstraints(fn () => $this->relation(
                related: $related,
                parent: $parents[array_key_first($values)],
            ));
            $relation->addEagerConstraints(array_values(array_intersect_key($parents, $values)));
            $query = $relation->getQuery();
        }

        $query->orderBy($query->getModel()->getQualifiedKeyName());
        $rows = [];

        foreach (($lock ? $query->lockForUpdate() : $query)->get() as $row) {
            $value = $row->getAttributes()[$related->relatedKey] ?? null;

            if (is_int($value) || is_string($value)) {
                $rows[(string) $value] ??= $row;
            }
        }

        $found = [];
        $held = [];

        foreach ($values as $index => $value) {
            $row = is_int($value) || is_string($value) ? $rows[(string) $value] ?? null : null;

            if ($row === null) {
                continue;
            }

            $found[$index] = array_key_exists(
                key: spl_object_id($row),
                array: $held,
            ) ? clone $row : $row;
            $held[spl_object_id($row)] = true;
        }

        return $found;
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
                    $this->readOnly(
                        graph: $graph,
                        attribute: $attribute,
                        name: $name,
                        value: $value,
                        change: new Change(
                            record: $record,
                            original: $original,
                            attribute: $name,
                            origin: $origin,
                        ),
                        creating: false,
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
                self::hook(
                    run: fn () => self::resolve(
                        class: $operation,
                        interface: WritesAttribute::class,
                        role: 'operation',
                    )->write($graph->successors(), $value, $change),
                    origin: $origin,
                    model: $map->model,
                    key: $creating ? null : self::keyOrNull($original),
                    attribute: $name,
                    class: $operation,
                );

                continue;
            }

            $this->assignInverse(
                graph: $graph,
                attribute: $attribute,
                name: $name,
                value: $value,
            );
        }

        $this->add(
            graph: $graph,
            amounts: $amounts,
            record: $record,
            original: $original,
            origin: $origin,
        );
    }

    public static function refuseIncrement(ResolvedMap $map, Attribute $attribute, string $name, null|int|string $key): void
    {
        $operation = $attribute->operation();
        $because = $attribute->readOnlyBecause();

        if ($because !== null && ! $attribute->ignoresWrites()) {
            throw CompatibilityWriteRefused::readOnly(
                model: $map->model,
                attribute: $name,
                key: $key,
                because: $because,
            );
        }

        $reason = match (true) {
            $because !== null => null,
            $operation !== null => 'It is writtenBy() '.class_basename($operation).', and an increment runs no operation, so the amount reaches no write.',
            ! $attribute->invertible() => 'It reads no plain stored column, so an amount added to it has no one stored column.',
            array_filter($attribute->conversions(), fn (object $conversion) => ! $conversion instanceof DividedBy && ! $conversion instanceof MultipliedBy) !== [] => 'Its conversions are not linear, so an amount added to it has no one stored amount.',
            default => null,
        };

        if ($reason !== null) {
            throw CompatibilityWriteRefused::unsupported(
                model: $map->model,
                operation: "an increment of \${$name}",
                because: $reason,
            );
        }
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

        $instance = self::resolve(
            class: $initializer,
            interface: InitializesSuccessors::class,
            role: 'initializer',
        );

        self::hook(
            run: fn () => $instance->initialize($graph->successors(), new Creation(
                record: $record,
                origin: $origin,
            )),
            origin: $origin,
            model: $this->map->model,
            key: null,
            attribute: null,
            class: $initializer,
        );
    }

    /**
     * @param  Closure(): mixed  $run
     * @param  class-string  $class
     */
    public static function hook(Closure $run, Origin $origin, string $model, null|int|string $key, null|string $attribute, string $class): void
    {
        try {
            $run();
        } catch (Refusal $refusal) {
            if ($origin !== Origin::Live) {
                throw $refusal;
            }

            throw new Refusal(
                message: ($key === null ? "A new {$model} record" : "{$model} record {$key}").' cannot take '.($attribute === null ? 'this write' : "this write of \${$attribute}").', because '.class_basename($class).' refused it: '.$refusal->getMessage(),
                previous: $refusal,
            );
        }
    }

    public static function keyOrNull(Model $model): null|int|string
    {
        $key = $model->getRawOriginal($model->getKeyName()) ?? $model->getKey();

        return is_int($key) || is_string($key) ? $key : null;
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
            $this->run($legacy, $model, fn () => Model::withoutTimestampsOn($classes, fn () => $model->delete()));
        }
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
                    write: fn () => Model::withoutTimestampsOn($classes, $action),
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
            throw CompatibilityWriteRefused::bySuccessorListener(
                model: $legacy::class,
                successor: $successor,
                key: self::keyOrNull($legacy),
            );
        }
    }

    /**
     * @param  Staging<Model>  $graph
     */
    private function readOnly(Staging $graph, Attribute $attribute, string $name, mixed $value, Change $change, bool $creating): void
    {
        $stored = $attribute->invertible();

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

        if ($model !== null && $graph->linked($owner->table)) {
            return $column === $owner->key;
        }

        if ($model !== null && ! $graph->created($owner->table)) {
            return false;
        }

        if ($stored === null) {
            return $model !== null;
        }

        self::assignRaw(
            model: $graph->relatedOrNew($graph->map->relatedAt($owner->table)->model),
            values: [$column => $stored],
        );

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

        $related = $map->relatedAt($owner->table)->model;
        $model = $graph->related($related);

        if ($model === null && $stored === null) {
            return;
        }

        $model ??= $graph->relatedOrNew($related);
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
            if ($map->owners[$name] === $map->primary && $attribute->isWritable() && array_key_exists($name, $values)) {
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
            $graph->afterSave(function () use ($root, $map, $record): void {
                if ($root->restore() === false) {
                    throw CompatibilityWriteRefused::bySuccessorListener(
                        model: $map->model,
                        successor: $root,
                        key: self::keyOrNull($record),
                    );
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

        $graph->afterSave(function () use ($root, $stamp, $map, $record): void {
            if ($root->delete() === false) {
                throw CompatibilityWriteRefused::bySuccessorListener(
                    model: $map->model,
                    successor: $root,
                    key: self::keyOrNull($record),
                );
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
    private function add(Staging $graph, array $amounts, Model $record, Model $original, Origin $origin): void
    {
        $map = $graph->map;
        $catalog = new ColumnCatalog($this->connection, throughSearchPath: true);

        foreach ($amounts as $name => $amount) {
            $attribute = $map->attributes[$name] ?? null;

            if ($attribute === null) {
                continue;
            }

            self::refuseIncrement(
                map: $map,
                attribute: $attribute,
                name: $name,
                key: $original->getAttributes() === [] ? null : self::keyOf($original),
            );

            if ($attribute->ignoresWrites()) {
                Event::dispatch(new WriteIgnored(
                    change: new Change(
                        record: $record,
                        original: $original,
                        attribute: $name,
                        origin: $origin,
                    ),
                    value: $amount,
                ));

                continue;
            }

            $column = (string) $attribute->column();
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
            $this->run($legacy, $model, fn () => Model::withoutTimestampsOn($classes, fn () => $model->save()));
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
     * @template THook of object
     *
     * @param  class-string  $class
     * @param  class-string<THook>  $interface
     * @return THook
     */
    public static function resolve(string $class, string $interface, string $role): object
    {
        $instance = Container::getInstance()->make($class);

        return $instance instanceof $interface ? $instance : throw new LogicException("The {$role} {$class} does not implement {$interface}.");
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
