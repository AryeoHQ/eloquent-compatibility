<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Contracts\DefinesCompatibility;
use Support\Database\Eloquent\Compatibility\Contracts\EventMirror;
use Support\Database\Eloquent\Compatibility\Definition\DefinedMap;
use Support\Database\Eloquent\Compatibility\Definition\RelationCompiler;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\Attribute;
use Support\Database\Eloquent\Compatibility\Mapping\DependentCurrentModel;
use Support\Database\Eloquent\Compatibility\Mapping\ExcludedListener;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Support\Database\Eloquent\Compatibility\Mapping\PreviousModel;
use Support\Database\Eloquent\Compatibility\Mapping\PrimaryCurrentModel;
use Support\Database\Eloquent\Compatibility\Mapping\UnrepresentableState;

/**
 * Declares a compatibility map from the previous model's side, in terms of models and their relations.
 *
 * A previous model implements DefinesCompatibility and returns this builder from its compatibility() method. Each
 * attribute is a path from the retained model, as Eloquent's with() writes one: `mls_number` stays on the retained
 * model, `job.name` reads the primary current model through a BelongsTo, and `job.rootPlace.street` reads a dependent
 * current model through a HasOne of the primary. The relations decide which tables join and which dependent row the
 * previous model reads; the catalog decides each column's type; casts on the previous model convert values.
 *
 * The builder compiles, through toMap(), into the same parts a hand-written CompatibilityMap returns, and reads no
 * database to do it.
 */
final class Compatibility
{
    private null|string $name = null;

    private null|string $view = null;

    /** @var null|class-string<Model> */
    private null|string $identity = null;

    /** @var array<string, string> attribute => path */
    private array $paths = [];

    private null|string $timestampsOn = null;

    /** @var array<string, string> attribute => reason */
    private array $readOnly = [];

    /** @var array<string, list<Closure(Model, Model): mixed>> path => hooks */
    private array $creating = [];

    /** @var array<string, Closure(Model): bool> path => condition */
    private array $createWhen = [];

    /** @var array<string, string> path => designation column */
    private array $designations = [];

    /** @var null|array{string, Closure(Model, Model): mixed} */
    private null|array $oneForEach = null;

    /** @var list<array{Closure(Builder<Model>): mixed, string}> */
    private array $hidden = [];

    /** @var list<array{string, Closure(Model, Model): bool, string, string, string}> */
    private array $guards = [];

    /** @var list<array{string, class-string<Model>, Closure(Model): bool, string, string, null|Closure(Builder<Model>): mixed, string}> */
    private array $refusals = [];

    /** @var list<UnrepresentableState> */
    private array $degraded = [];

    /** @var list<ExcludedListener> */
    private array $excludedListeners = [];

    /** @var null|array{previous: PreviousModel, primary: PrimaryCurrentModel, dependents: list<DependentCurrentModel>, attributes: list<Attribute>, states: list<UnrepresentableState>} */
    private null|array $compiled = null;

    /**
     * @param  class-string<Model>  $model  the previous model
     */
    public function __construct(private readonly string $model) {}

    /**
     * The compatibility map a previous model defines through DefinesCompatibility.
     *
     * @param  class-string<Model>  $model
     */
    public static function of(string $model): self
    {
        if (! is_subclass_of($model, DefinesCompatibility::class)) {
            throw InvalidCompatibilityMap::because($model, 'the model does not implement '.DefinesCompatibility::class.', so it defines no compatibility map');
        }

        return $model::compatibility(new self($model));
    }

    /**
     * A compatibility map, or the one a previous model class defines.
     *
     * @param  CompatibilityMap|class-string<Model>  $map
     */
    public static function map(CompatibilityMap|string $map): CompatibilityMap
    {
        return $map instanceof CompatibilityMap ? $map : self::of($map)->toMap();
    }

    /**
     * An event mirror, or the one a previous model class defines.
     *
     * @param  EventMirror|class-string<Model>  $mirror
     */
    public static function mirror(EventMirror|string $mirror): EventMirror
    {
        return $mirror instanceof EventMirror ? $mirror : self::of($mirror)->toMap();
    }

    /**
     * Names the map. Without it, the map takes the view's name.
     */
    public function named(string $name): self
    {
        $this->name = $name;

        return $this->changed();
    }

    /**
     * Names the compatibility view. Without it, the view takes the previous model's table.
     */
    public function servedAs(string $view): self
    {
        $this->view = $view;

        return $this->changed();
    }

    /**
     * Names the model over the retained table, the previous model's table after its rename. Its key is the previous
     * model's key.
     *
     * @param  class-string<Model>  $model
     */
    public function identifiedBy(string $model): self
    {
        $this->identity = $model;

        return $this->changed();
    }

    /**
     * Maps each attribute of the previous model to a path from the retained model: `column`, `relation.column`, or
     * `relation.relation.column`. The retained model's key and the primary relation's foreign key are added, read-only,
     * when the list leaves them out.
     *
     * @param  array<string, string>  $paths
     */
    public function attributes(array $paths): self
    {
        $this->paths = [...$this->paths, ...$paths];

        return $this->changed();
    }

    /**
     * Stores the previous model's created-at, updated-at and, when it soft-deletes, deleted-at in the primary current model's own columns.
     */
    public function timestampsOn(string $relation): self
    {
        $this->timestampsOn = $relation;

        return $this->changed();
    }

    public function readOnly(string $attribute, string $because): self
    {
        $this->readOnly[$attribute] = $because;

        return $this->changed();
    }

    /**
     * Runs before a write through the previous model creates the row a relation path reaches. The hook receives that
     * unsaved model and the previous model as written, and may set attributes on the unsaved model.
     *
     * @param  Closure(Model, Model): mixed  $hook
     */
    public function creating(string $path, Closure $hook): self
    {
        $this->creating[$path][] = $hook;

        return $this->changed();
    }

    /**
     * Decides whether a write through the previous model creates a missing dependent row. Without it, a write creates
     * the row when it stores a value in it.
     *
     * @param  Closure(Model): bool  $condition  receives the previous model as written
     */
    public function createWhen(string $path, Closure $condition): self
    {
        $this->createWhen[$path] = $condition;

        return $this->changed();
    }

    /**
     * Pins the dependent row a path reads with a retained column, which the map fills with the first readable row.
     */
    public function designatedBy(string $path, string $column): self
    {
        $this->designations[$path] = $column;

        return $this->changed();
    }

    /**
     * Fills the retained row the map creates for each primary current row created through its own model. The hook
     * receives the unsaved retained model and the primary current model as stored.
     *
     * @param  Closure(Model, Model): mixed  $fill
     */
    public function oneForEach(string $relation, Closure $fill): self
    {
        $this->oneForEach = [$relation, $fill];

        return $this->changed();
    }

    /**
     * Hides the retained rows a query scope over the retained model selects, from the view and from the drift check.
     *
     * @param  Closure(Builder<Model>): mixed  $scope
     */
    public function hide(Closure $scope, string $because = 'The previous model never showed these records.'): self
    {
        $this->hidden[] = [$scope, $because];

        return $this->changed();
    }

    /**
     * Refuses an update through the previous model, its own write or a query builder write, when $when returns true.
     *
     * @param  Closure(Model, Model): bool  $when  receives the previous model after and before the write
     */
    public function guard(string $name, Closure $when, string $because, string $instead, null|string $description = null): self
    {
        $this->guards[] = [$name, $when, $because, $instead, $description ?? str_replace('_', ' ', $name)];

        return $this->changed();
    }

    /**
     * Refuses every write through a model that leaves a row of $on in the state $when describes. The write router
     * checks the written rows after the write, inside its transaction. The drift check walks the rows $candidates
     * selects, or every row.
     *
     * @param  class-string<Model>  $on
     * @param  Closure(Model): bool  $when
     * @param  null|Closure(Builder<Model>): mixed  $candidates
     */
    public function refuse(string $name, string $on, Closure $when, string $because, string $instead, null|Closure $candidates = null, null|string $description = null): self
    {
        $this->refusals[] = [$name, $on, $when, $because, $instead, $candidates, $description ?? str_replace('_', ' ', $name)];

        return $this->changed();
    }

    /**
     * Documents a state one side holds and the other cannot express, which the map lets through.
     */
    public function degrade(string $name, string $description, string $rule): self
    {
        $this->degraded[] = UnrepresentableState::degrade($name, $description, $rule);

        return $this->changed();
    }

    /**
     * Keeps a listener from running for the previous model's events that a write of a current model replays.
     */
    public function excludeFromMirroredEvents(string $listener, string $because, null|ModelEvent $event = null): self
    {
        $excluded = ExcludedListener::of($listener, $because);
        $this->excludedListeners[] = $event === null ? $excluded : $excluded->forEvent($event);

        return $this;
    }

    /**
     * Compiles the declaration into a compatibility map, which is also its own event mirror.
     */
    public function toMap(): DefinedMap
    {
        $compiled = $this->compile();

        return new DefinedMap($this->mapName(), $compiled['previous'], $compiled['primary'], $compiled['dependents'], $compiled['attributes'], $compiled['states'], $this->excludedListeners);
    }

    /**
     * @return array{previous: PreviousModel, primary: PrimaryCurrentModel, dependents: list<DependentCurrentModel>, attributes: list<Attribute>, states: list<UnrepresentableState>}
     */
    private function compile(): array
    {
        if ($this->compiled !== null) {
            return $this->compiled;
        }

        $compiler = new RelationCompiler($this->mapName());

        if ($this->identity === null) {
            throw $this->invalid('it says no identifiedBy(), so it names no model over the retained table');
        }

        $previousModel = new ($this->model);
        $identity = new ($this->identity);
        $previousAlias = $identity->getTable();
        $keyColumn = $identity->getKeyName();

        $primaryRelation = null;
        $dependentRelations = [];
        $columns = [];

        foreach ($this->paths as $attribute => $path) {
            $segments = explode('.', $path);

            if (count($segments) > 3) {
                throw $this->invalid("attribute '{$attribute}' reads {$path}, which is deeper than a dependent of the primary current model");
            }

            if (count($segments) > 1) {
                if ($primaryRelation !== null && $primaryRelation !== $segments[0]) {
                    throw $this->invalid("attributes read through both {$primaryRelation} and {$segments[0]}; every path starts with the one relation to the primary current model");
                }

                $primaryRelation = $segments[0];
            }

            if (count($segments) === 3) {
                $dependentRelations[$segments[1]] = true;
            }

            $columns[$attribute] = $segments;
        }

        if ($primaryRelation === null) {
            throw $this->invalid('no attribute reads through a relation, so the map names no primary current model');
        }

        $primaryHop = $compiler->belongsTo($identity, $primaryRelation);
        $primaryModel = new ($primaryHop['model']);
        $primaryAlias = $primaryModel->getTable();
        $previousForeignKey = $primaryHop['foreignKey'];
        $aliases = [$previousAlias => 'the retained model', $primaryAlias => $primaryRelation];
        $dependents = [];
        $dependentAliases = [];

        foreach (array_keys($dependentRelations) as $relation) {
            $hop = $compiler->hasOne($primaryModel, $relation);
            $alias = (new ($hop['model']))->getTable();

            if (array_key_exists($alias, $aliases)) {
                throw $this->invalid("{$primaryRelation}.{$relation} reaches the table {$alias}, which {$aliases[$alias]} already reaches; map each table through one relation");
            }

            $aliases[$alias] = "{$primaryRelation}.{$relation}";
            $dependentAliases[$relation] = $alias;
            $path = "{$primaryRelation}.{$relation}";
            $dependent = DependentCurrentModel::for($hop['model'])
                ->aliasedAs($alias)
                ->primaryForeignKey($hop['foreignKey'])
                ->readableWhen($hop['readableWhen'])
                ->fillWhenCreatedFromPrevious(array_map(fn (mixed $value) => fn () => $value, $hop['attributes']));

            if ($hop['orderedBy'] !== null) {
                $dependent = $dependent->orderedBy($hop['orderedBy']);
            }

            foreach ($this->creating[$path] ?? [] as $hook) {
                $dependent = $dependent->onCreate(fn (Model $created, array $attributes) => $hook($created, $this->hydrate($attributes)));
            }

            if (array_key_exists($path, $this->createWhen)) {
                $condition = $this->createWhen[$path];
                $dependent = $dependent->createdWhen(fn (array $attributes) => (bool) $condition($this->hydrate($attributes)));
            }

            if (array_key_exists($path, $this->designations)) {
                $dependent = $dependent->designatedBy($this->designations[$path]);
            }

            $dependents[] = $dependent;
        }

        foreach ([...array_keys($this->creating), ...array_keys($this->createWhen), ...array_keys($this->designations)] as $path) {
            if ($path !== $primaryRelation && ! array_key_exists($path, array_flip(array_map(fn (string $relation) => "{$primaryRelation}.{$relation}", array_keys($dependentAliases))))) {
                throw $this->invalid("{$path} is not a relation path any attribute reads");
            }
        }

        $primary = PrimaryCurrentModel::for($primaryHop['model'])->aliasedAs($primaryAlias);

        foreach ($this->creating[$primaryRelation] ?? [] as $hook) {
            $primary = $primary->onCreate(fn (Model $created, array $attributes) => $hook($created, $this->hydrate($attributes)));
        }

        $attributes = [];

        foreach ($columns as $attribute => $segments) {
            $column = $segments[count($segments) - 1];
            $alias = match (count($segments)) {
                1 => $previousAlias,
                2 => $primaryAlias,
                default => $dependentAliases[$segments[1]],
            };
            $attributes[$attribute] = $alias === $previousAlias && $column === $attribute
                ? Attribute::retained($attribute)
                : Attribute::mapped($attribute)->storedAs($alias, $column);
        }

        foreach ($this->timestamps($previousModel, $primaryModel) as $attribute => $column) {
            $attributes[$attribute] ??= Attribute::mapped($attribute)->storedAs($primaryAlias, $column);
        }

        $class = class_basename($this->model);
        $inferred = [
            $keyColumn => "{$class} keeps the key other records hold.",
            $previousForeignKey => "{$class} stays tied to its ".class_basename($primaryHop['model']).'.',
        ];

        foreach ($inferred as $column => $because) {
            $attributes[$column] ??= Attribute::retained($column);
        }

        foreach ([...$inferred, ...$this->readOnly] as $attribute => $because) {
            if (! array_key_exists($attribute, $attributes)) {
                throw $this->invalid("readOnly() names '{$attribute}', which is not an attribute");
            }

            $attributes[$attribute] = $attributes[$attribute]->readOnlyBecause($because);
        }

        $previous = PreviousModel::servedAsView($this->viewName())
            ->retainedInTable($previousAlias)
            ->retainedAs($this->identity)
            ->forModel($this->model)
            ->keyedBy($keyColumn)
            ->aliasedAs($previousAlias)
            ->primaryForeignKey($previousForeignKey);

        if ($this->oneForEach !== null) {
            [$relation, $fill] = $this->oneForEach;

            if ($relation !== $primaryRelation) {
                throw $this->invalid("oneForEach() names {$relation}, which is not the relation to the primary current model, {$primaryRelation}");
            }

            $previous = $previous->fillWhenCreatedFromCurrentUsing(function (Model $current) use ($identity, $fill): array {
                $retained = $identity->newInstance();
                $fill($retained, $current);

                return $retained->getAttributes();
            });
        }

        $states = [...$this->degraded];

        foreach ($this->hidden as $index => [$scope, $because]) {
            $states[] = UnrepresentableState::hide('hidden_'.($index + 1), 'a hidden record', $because, $compiler->scope($identity, $previousAlias, $scope));
        }

        foreach ($this->guards as [$name, $when, $because, $instead, $description]) {
            $states[] = UnrepresentableState::refuse($name, $description, $because, $instead)
                ->checkedOnPreviousUpdate(fn (array $after, array $before) => (bool) $when($this->hydrate($after), $this->hydrate($before)));
        }

        $tablesByModel = [$this->identity => $previousAlias, $primaryHop['model'] => $primaryAlias];

        foreach ($dependents as $dependent) {
            $tablesByModel[$dependent->model] = (string) $dependent->alias;
        }

        foreach ($this->refusals as [$name, $on, $when, $because, $instead, $candidates, $description]) {
            $alias = $tablesByModel[ltrim($on, '\\')] ?? throw $this->invalid("refused state '{$name}' checks {$on}, which is neither the retained model nor a current model of the map");
            $states[] = UnrepresentableState::refuse($name, $description, $because, $instead)->checkedBy($alias, $when, $candidates);
        }

        return $this->compiled = [
            'previous' => $previous,
            'primary' => $primary,
            'dependents' => $dependents,
            'attributes' => array_values($attributes),
            'states' => $states,
        ];
    }

    /**
     * @return array<string, string> previous attribute => primary column
     */
    private function timestamps(Model $previous, Model $primary): array
    {
        if ($this->timestampsOn === null) {
            return [];
        }

        $timestamps = [];

        if ($previous->usesTimestamps()) {
            $timestamps[(string) $previous->getCreatedAtColumn()] = (string) $primary->getCreatedAtColumn();
            $timestamps[(string) $previous->getUpdatedAtColumn()] = (string) $primary->getUpdatedAtColumn();
        }

        if (method_exists($previous, 'getDeletedAtColumn')) {
            $timestamps[(string) $previous->getDeletedAtColumn()] = method_exists($primary, 'getDeletedAtColumn') ? (string) $primary->getDeletedAtColumn() : (string) $previous->getDeletedAtColumn();
        }

        return $timestamps;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function hydrate(array $attributes): Model
    {
        return (new ($this->model))->newFromBuilder($attributes);
    }

    private function mapName(): string
    {
        return $this->name ?? $this->viewName();
    }

    private function viewName(): string
    {
        return $this->view ?? (new ($this->model))->getTable();
    }

    private function changed(): self
    {
        $this->compiled = null;

        return $this;
    }

    private function invalid(string $problem): InvalidCompatibilityMap
    {
        return InvalidCompatibilityMap::because($this->mapName(), $problem);
    }
}
