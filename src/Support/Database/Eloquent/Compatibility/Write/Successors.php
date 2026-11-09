<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Closure;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Support\Database\Eloquent\Compatibility\Definition\Related;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;

/**
 * @template TRoot of Model
 */
final class Successors
{
    /** @var array<string, null|Model> */
    private array $models;

    /** @var array<string, true> */
    private array $created = [];

    /** @var array<string, true> */
    private array $linked = [];

    /** @var array<string, Model> */
    private array $deletions = [];

    /** @var array<int, true> */
    private array $timestamped = [];

    /** @var array<int, true> */
    private array $deferred = [];

    /** @var list<Closure(): mixed> */
    private array $actions = [];

    /**
     * @param  TRoot  $root
     * @param  array<string, null|Model>  $models
     */
    public function __construct(
        public readonly ResolvedMap $map,
        private readonly Model $root,
        array $models,
        private readonly null|Model $forwarding = null,
    ) {
        $this->models = $models;
    }

    /**
     * @return TRoot
     */
    public function root(): Model
    {
        if ($this->forwarding !== null && $this->forwarding !== $this->root) {
            throw $this->forwardedOnly('root()', $this->root::class);
        }

        return $this->root;
    }

    /**
     * @template TRelated of Model
     *
     * @param  class-string<TRelated>  $class
     * @return null|TRelated
     */
    public function related(string $class): null|Model
    {
        $related = $this->relation($class);
        $model = $this->models[$related->table] ?? null;

        if ($this->forwarding !== null && $model !== $this->forwarding) {
            throw $this->forwardedOnly('related()', $class);
        }

        if ($model !== null && ! $model instanceof $class) {
            throw new LogicException("The related successor {$related->table} holds a ".$model::class.", not a {$class}.");
        }

        return $model;
    }

    /**
     * @template TRelated of Model
     *
     * @param  class-string<TRelated>  $class
     * @return TRelated
     */
    public function relatedOrNew(string $class): Model
    {
        $existing = $this->related($class);

        if ($existing !== null) {
            return $existing;
        }

        $related = $this->relation($class);
        $model = (new $class)->setConnection($this->root->getConnectionName());
        $model->forceFill($related->attributes);
        $this->models[$related->table] = $model;
        $this->created[$related->table] = true;
        unset($this->deletions[$related->table]);

        return $model;
    }

    /**
     * @param  class-string<Model>  $class
     */
    public function deleteRelated(string $class): void
    {
        $related = $this->relation($class);

        if ($this->forwarding !== null) {
            throw $this->forwardedOnly('deleteRelated()', $class);
        }

        $model = $this->models[$related->table] ?? null;

        if ($model === null) {
            return;
        }

        if (array_key_exists(
            key: $related->table,
            array: $this->created,
        )) {
            unset($this->created[$related->table]);
            $this->models[$related->table] = null;

            return;
        }

        $this->deletions[$related->table] = $model;
    }

    /**
     * @param  Closure(): mixed  $action
     */
    public function afterSave(Closure $action): void
    {
        if ($this->forwarding !== null) {
            throw $this->forwardedOnly('afterSave()', $this->root::class);
        }

        $this->actions[] = $action;
    }

    /**
     * @return array<string, null|Model>
     */
    public function models(): array
    {
        return $this->models;
    }

    public function model(string $table): null|Model
    {
        return $table === $this->map->primary->table ? $this->root : $this->models[$table] ?? null;
    }

    public function link(string $table, Model $model): void
    {
        $this->models[$table] = $model;
        $this->linked[$table] = true;
        unset($this->created[$table]);
    }

    public function linked(string $table): bool
    {
        return array_key_exists(
            key: $table,
            array: $this->linked,
        );
    }

    public function keepTimestamp(Model $model): void
    {
        $this->timestamped[spl_object_id($model)] = true;
    }

    public function defer(Model $model): void
    {
        $this->deferred[spl_object_id($model)] = true;
    }

    public function deferred(Model $model): bool
    {
        return array_key_exists(
            key: spl_object_id($model),
            array: $this->deferred,
        );
    }

    public function timestamped(Model $model): bool
    {
        return array_key_exists(
            key: spl_object_id($model),
            array: $this->timestamped,
        );
    }

    public function created(string $table): bool
    {
        return array_key_exists(
            key: $table,
            array: $this->created,
        );
    }

    /**
     * @return array<string, Model>
     */
    public function deletions(): array
    {
        $deletions = $this->deletions;
        $this->deletions = [];

        foreach (array_keys($deletions) as $table) {
            $this->models[$table] = null;
        }

        return $deletions;
    }

    /**
     * @return list<Closure(): mixed>
     */
    public function actions(): array
    {
        $actions = $this->actions;
        $this->actions = [];

        return $actions;
    }

    /**
     * @return list<Model>
     */
    public function held(): array
    {
        return array_values(array_filter([$this->root, ...array_values($this->models)], fn (null|Model $model) => $model !== null));
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function relation(string $class): Related
    {
        foreach ($this->map->related as $related) {
            if ($related->model === $class) {
                return $related;
            }
        }

        throw CompatibilityWriteRefused::unsupported(
            model: $this->map->model,
            operation: 'an operation that names '.class_basename($class),
            because: 'The map relates no '.class_basename($class).' under its root '.class_basename($this->map->primary->model).', so the unit of work holds no such successor.',
        );
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function forwardedOnly(string $method, string $class): CompatibilityWriteRefused
    {
        return CompatibilityWriteRefused::unsupported(
            model: $this->map->model,
            operation: "an operation that calls {$method} for ".class_basename($class).' from a forwarded legacy event',
            because: 'A forwarded event belongs to the save of '.($this->forwarding === null ? 'a successor' : class_basename($this->forwarding)).', so the operation reaches that successor alone and the package saves nothing else.',
        );
    }
}
