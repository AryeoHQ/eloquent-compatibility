<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Closure;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use ReflectionFunction;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\InitializesLegacy;
use Support\Database\Eloquent\Compatibility\Contracts\InitializesSuccessors;
use Support\Database\Eloquent\Compatibility\Contracts\ManagesDependents;
use Support\Database\Eloquent\Compatibility\Contracts\ScopesWrites;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\Fixed;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;

final readonly class ResolvedMap
{
    public Successor $primary;

    public string $successorKey;

    /** @var list<Related> */
    public array $related;

    /** @var array<string, Attribute> */
    public array $attributes;

    /** @var array<string, Successor> */
    public array $owners;

    public null|RelatedMorph $morph;

    /** @var array<class-string<Model>, ResolvedMap> */
    public array $morphs;

    public null|Related $target;

    /**
     * @param  class-string<Model>  $model
     * @param  list<Hide>  $hides
     * @param  array<string, Guard>  $guards
     * @param  array<class-string, string>  $withoutForwarding
     * @param  null|class-string<ManagesDependents>  $dependents
     * @param  null|class-string<InitializesSuccessors>  $successorsInitializer
     * @param  null|class-string<InitializesLegacy>  $legacyInitializer
     * @param  null|class-string<ScopesWrites>  $scope
     * @param  null|class-string<Model>  $variant
     */
    public function __construct(
        public string $model,
        public string $table,
        public null|string $retainedTable,
        null|string $successorKey,
        null|Successor $primary,
        public array $hides,
        public array $guards,
        public array $withoutForwarding,
        public null|string $dependents = null,
        public null|string $successorsInitializer = null,
        public null|string $legacyInitializer = null,
        public null|string $scope = null,
        null|string $variant = null,
    ) {
        if ($primary === null || $successorKey === null) {
            throw $this->invalid('it says no servedFrom(), so it names no successor');
        }

        if ($primary->atMostOneBecause() !== null) {
            throw $this->invalid('atMostOne() refuses a second related row, and servedFrom() reads the successor '.class_basename($primary->model).' by its own row');
        }

        $morph = $primary->morph();

        if ($morph !== null && $variant === null) {
            $this->resolveMorphs(
                primary: $primary,
                morph: $morph,
                successorKey: $successorKey,
            );

            return;
        }

        $this->morph = $morph;
        $this->morphs = [];
        $this->target = $variant === null || $morph === null ? null : $morph->targets[$variant];

        [$related, $successors] = $this->walk($primary);
        [$attributes, $owners] = $this->collect(
            successors: $successors,
            successorKey: $successorKey,
            related: $related,
        );
        $this->refuseSharedColumns(
            primary: $primary,
            attributes: $attributes,
            owners: $owners,
        );

        if ($retainedTable !== null && ($retainedTable === $table || in_array($retainedTable, array_map(fn (Successor $successor) => $successor->table, $successors), true))) {
            throw $this->invalid("retainedIn() names the table {$retainedTable}, which the map already reads as another table");
        }

        $this->primary = $primary;
        $this->successorKey = $successorKey;
        $this->related = $related;
        $this->attributes = $attributes;
        $this->owners = $owners;
    }

    public static function refuseCaptures(string $map, string $role, Closure $closure): void
    {
        $captured = array_keys(array_filter(
            (new ReflectionFunction($closure))->getClosureUsedVariables(),
            fn (mixed $value) => ! $value instanceof Compatibility && ! $value instanceof Successor && ! $value instanceof Attribute,
        ));

        if ($captured !== []) {
            throw InvalidCompatibilityMap::because($map, "{$role} captures \$".implode(', $', $captured).', and a map reads nothing but its own arguments');
        }
    }

    public function morphFor(Model $legacy): self
    {
        $morph = $this->morph ?? throw new LogicException("Compatibility map '{$this->table}' declares no relatedMorph(), so it has no target to choose.");
        $variants = $this->morphs === [] ? throw new LogicException("Compatibility map '{$this->table}' is one target of its relatedMorph(), so it has no other target to choose.") : $this->morphs;

        $target = ($morph->choose)($legacy);

        if (is_string($target) && array_key_exists($target, $variants)) {
            return $variants[$target];
        }

        throw $this->invalid('the relatedMorph() choose: closure returns '.(is_string($target) ? $target : get_debug_type($target)).', and targets: declares only '.implode(
            separator: ' and ',
            array: array_keys($variants),
        ));
    }

    public function morphHolding(mixed $alias): null|self
    {
        foreach ($this->morphs as $variant) {
            if ($variant->target?->morphAlias === $alias) {
                return $variant;
            }
        }

        return null;
    }

    public function isMorphed(string $name): bool
    {
        $owner = $this->owners[$name] ?? null;

        return $owner !== null && array_key_exists($owner->model, $this->morph->targets ?? []);
    }

    public function isExternal(string $name): bool
    {
        foreach ($this->morphs === [] ? [$this] : $this->morphs as $served) {
            if (($served->attributes[$name] ?? null)?->isExternal() === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function externals(): array
    {
        return array_values(array_filter(array_keys($this->attributes), $this->isExternal(...)));
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return array_values(array_unique(array_filter([
            $this->table,
            $this->retainedTable,
            $this->primary->table,
            ...array_map(fn (Related $related) => $related->table, $this->related),
        ], is_string(...))));
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    public function legacyOnly(array $columns): array
    {
        return array_values(array_filter(
            array: $columns,
            callback: fn (string $column) => ! array_key_exists(
                key: $column,
                array: $this->attributes,
            ),
        ));
    }

    private function resolveMorphs(Successor $primary, RelatedMorph $morph, string $successorKey): void
    {
        $variants = [];
        $tables = [];

        foreach ($morph->targets as $target => $related) {
            if (array_key_exists($related->table, $tables)) {
                throw $this->invalid('relatedMorph() reaches the table '.$related->table.' through '.class_basename($target).' and through '.class_basename($tables[$related->table]).'; give each targets: class a table of its own');
            }

            $tables[$related->table] = $target;
            $variants[$target] = new self(
                model: $this->model,
                table: $this->table,
                retainedTable: $this->retainedTable,
                successorKey: $successorKey,
                primary: $primary,
                hides: $this->hides,
                guards: $this->guards,
                withoutForwarding: $this->withoutForwarding,
                dependents: $this->dependents,
                successorsInitializer: $this->successorsInitializer,
                legacyInitializer: $this->legacyInitializer,
                scope: $this->scope,
                variant: $target,
            );
        }

        $this->refuseUnreadable($variants);
        $first = reset($variants);

        if ($first === false) {
            throw new LogicException("Compatibility map '{$this->table}' resolves no relatedMorph() target.");
        }

        $this->primary = $first->primary;
        $this->successorKey = $first->successorKey;
        $this->related = [...array_values(array_filter($first->related, fn (Related $related) => $related->morphType === null)), ...array_values($morph->targets)];
        $this->attributes = $first->attributes;
        $this->owners = $first->owners;
        $this->morph = $morph;
        $this->morphs = $variants;
        $this->target = null;
    }

    /**
     * @param  array<class-string<Model>, ResolvedMap>  $branches
     */
    private function refuseUnreadable(array $branches): void
    {
        $declaredIn = [];

        foreach ($branches as $successor => $branch) {
            foreach (array_keys($branch->attributes) as $name) {
                $declaredIn[$name] ??= $successor;
            }
        }

        foreach ($branches as $successor => $branch) {
            foreach ($declaredIn as $name => $declared) {
                if (! array_key_exists($name, $branch->attributes)) {
                    throw $this->invalid("attribute '{$name}' is readable in the ".class_basename($declared).' target and not in the '.class_basename($successor).' target; give it from(), computedAs() or fixed() there');
                }
            }
        }

        foreach (array_keys($declaredIn) as $name) {
            $external = array_filter($branches, fn (ResolvedMap $branch) => $branch->attributes[$name]->isExternal());

            foreach ($external === [] ? [] : $branches as $successor => $branch) {
                $attribute = $branch->attributes[$name];

                if (! $attribute->isExternal() && ! ($attribute->column() === null && ($attribute->conversions()[0] ?? null) instanceof Fixed)) {
                    throw $this->invalid("attribute '{$name}' is computedAs() in the ".class_basename((string) array_key_first($external)).' target and reads SQL in the '.class_basename($successor).' target, and the view serves a computed attribute from no target; declare it computedAs() or fixed() there');
                }
            }
        }
    }

    /**
     * @return array{list<Related>, list<Successor>}
     */
    private function walk(Successor $primary): array
    {
        $tables = [$this->table => 'the legacy model '.class_basename($this->model)];
        $related = [];
        $successors = [];
        $pending = [[$primary, 'the successor '.class_basename($primary->model)]];

        while ($pending !== []) {
            [$successor, $name] = array_shift($pending);

            if (array_key_exists($successor->table, $tables)) {
                throw $this->invalid("{$name} reaches the table {$successor->table}, which {$tables[$successor->table]} already reaches; map each table through one relation");
            }

            if ($successor !== $primary && $successor->membershipScope() !== null) {
                throw $this->invalid("when() on {$name} decides membership, and only the servedFrom() root carries when()");
            }

            if ($successor !== $primary && $successor->morph() !== null) {
                throw $this->invalid("relatedMorph() on {$name} follows a MorphTo of a successor related() reaches, and only the servedFrom() successor ".class_basename($primary->model).' carries a relatedMorph(); declare it there');
            }

            $tables[$successor->table] = $name;
            $successors[] = $successor;

            foreach ($successor->relations() as $relation) {
                $related[] = $relation;
                $pending[] = [$relation->successor, 'the relation from '.class_basename($relation->parent).' to '.class_basename($relation->model)];
            }

            if ($successor === $primary && $this->target !== null) {
                $related[] = $this->target;
                $pending[] = [$this->target->successor, 'the relatedMorph() target '.class_basename($this->target->model)];
            }
        }

        return [$related, $successors];
    }

    /**
     * @param  list<Successor>  $successors
     * @param  list<Related>  $related
     * @return array{array<string, Attribute>, array<string, Successor>}
     */
    private function collect(array $successors, string $successorKey, array $related): array
    {
        $attributes = [];
        $owners = [];
        $key = (new $this->model)->getKeyName();
        $tables = [];

        foreach ($related as $relation) {
            if (array_key_exists(
                key: $relation->model,
                array: $tables,
            )) {
                throw $this->invalid('it relates '.class_basename($relation->model).' twice under the root '.class_basename($successors[0]->model).', and an operation names each related successor by its class');
            }

            $tables[$relation->model] = $relation->table;
        }

        foreach ($successors as $successor) {
            foreach ($successor->attributes() as $name => $attribute) {
                if ($name === $key) {
                    throw $this->invalid("attribute '{$name}' maps the key of ".class_basename($this->model).', and legacy identity stays on the legacy table');
                }

                if ($name === $successorKey) {
                    throw $this->invalid("attribute '{$name}' maps the successor key that servedFrom() names, and the retained table holds it");
                }

                if (array_key_exists(
                    key: $name,
                    array: $attributes,
                )) {
                    throw $this->invalid("attribute '{$name}' is declared twice");
                }

                $attributes[$name] = $attribute->resolve($tables);
                $owners[$name] = $successor;
            }
        }

        return [$attributes, $owners];
    }

    /**
     * @param  array<string, Attribute>  $attributes
     * @param  array<string, Successor>  $owners
     */
    private function refuseSharedColumns(Successor $primary, array $attributes, array $owners): void
    {
        $columns = [];

        if ($this->morph !== null) {
            $columns[$primary->table.'.'.$this->morph->typeColumn] = 'relatedMorph()';
            $columns[$primary->table.'.'.$this->morph->idColumn] = 'relatedMorph()';
        }

        foreach ($attributes as $name => $attribute) {
            if (! $attribute->invertible() || $attribute->operation() !== null || $attribute->readOnlyBecause() !== null) {
                continue;
            }

            $column = $owners[$name]->table.'.'.$attribute->column();

            if (array_key_exists($column, $columns)) {
                throw $this->invalid("attribute '{$name}' writes {$column}, which {$columns[$column]} already owns; make one of them readOnly()");
            }

            $columns[$column] = "attribute '{$name}'";
        }
    }

    private function invalid(string $problem): InvalidCompatibilityMap
    {
        return InvalidCompatibilityMap::because($this->table, $problem);
    }
}
