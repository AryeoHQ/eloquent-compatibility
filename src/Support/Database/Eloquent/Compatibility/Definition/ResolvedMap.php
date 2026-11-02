<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Closure;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use ReflectionFunction;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\Fixed;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;

final readonly class ResolvedMap
{
    public Successor $primary;

    public null|string $retainedKey;

    /** @var list<Related> */
    public array $related;

    /** @var array<string, Attribute> */
    public array $attributes;

    /** @var array<string, Successor> */
    public array $owners;

    public null|Membership $membership;

    public null|Closure $selector;

    /** @var array<class-string<Model>, ResolvedMap> */
    public array $branches;

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, Identifier>  $identifiers
     * @param  list<Hide>  $hides
     * @param  array<string, Guard>  $guards
     * @param  array<class-string, string>  $withoutForwarding
     * @param  array<class-string<Model>, Branch>  $branches
     */
    public function __construct(
        public string $model,
        public string $table,
        public array $identifiers,
        public null|string $retainedTable,
        null|string $retainedKey,
        bool $timestamps,
        null|Successor $primary,
        public array $hides,
        public array $guards,
        public array $withoutForwarding,
        null|string $discriminator = null,
        null|Closure $selector = null,
        array $branches = [],
        public bool $branch = false,
    ) {
        if ($selector !== null || $branches !== []) {
            $this->resolveBranches($retainedKey, $timestamps, $discriminator, $selector, $branches);

            return;
        }

        $this->selector = null;
        $this->branches = [];

        if ($primary === null) {
            throw $this->invalid('it says no servedFrom(), so it names no successor');
        }

        if ($primary->atMostOneBecause() !== null) {
            throw $this->invalid('atMostOne() refuses a second related row, and servedFrom() reads the successor '.class_basename($primary->model).' by its own row');
        }

        $legacy = new $model;
        $key = $legacy->getKeyName();

        if (! array_key_exists($key, $identifiers)) {
            throw $this->invalid("it declares no identifiedBy('{$key}'), so it serves no {$key} for ".class_basename($model));
        }

        foreach ($identifiers as $identifier) {
            $identifier->resolve($primary, $discriminator);
        }

        [$related, $successors] = $this->walk($primary);
        [$attributes, $owners] = $this->collect($successors, $timestamps ? $this->timestamps($legacy, $primary) : []);
        $this->refuseSharedColumns($primary, $attributes, $owners, $discriminator);
        $this->membership = $this->resolveMembership($legacy, $primary, $discriminator);

        if ($retainedTable !== null && ($retainedTable === $table || in_array($retainedTable, array_map(fn (Successor $successor) => $successor->table, $successors), true))) {
            throw $this->invalid("retainedIn() names the table {$retainedTable}, which the map already reads as another table");
        }

        $this->primary = $primary;
        $this->retainedKey = $retainedTable === null ? null : ($retainedKey ?? (new $primary->model)->getForeignKey());
        $this->related = $related;
        $this->attributes = $attributes;
        $this->owners = $owners;
    }

    public static function refuseCaptures(string $map, string $role, Closure $closure): void
    {
        $captured = array_keys(array_filter(
            (new ReflectionFunction($closure))->getClosureUsedVariables(),
            fn (mixed $value) => ! $value instanceof Compatibility && ! $value instanceof Successor && ! $value instanceof Attribute && ! $value instanceof Identifier,
        ));

        if ($captured !== []) {
            throw InvalidCompatibilityMap::because($map, "{$role} captures \$".implode(', $', $captured).', and a map reads nothing but its own arguments');
        }
    }

    public function branchFor(Model $legacy): self
    {
        $selector = $this->selector ?? throw new LogicException("Compatibility map '{$this->table}' serves one successor, so it has no branch to choose.");
        $successor = $selector($legacy);

        if (is_string($successor) && array_key_exists($successor, $this->branches)) {
            return $this->branches[$successor];
        }

        throw $this->invalid('the servedFrom() selector returns '.(is_string($successor) ? $successor : get_debug_type($successor)).', and as() declares only '.implode(' and ', array_keys($this->branches)));
    }

    public function isExternal(string $name): bool
    {
        foreach ($this->branches === [] ? [$this] : $this->branches as $served) {
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
     * @param  list<string>  $columns
     * @return list<string>
     */
    public function legacyOnly(array $columns): array
    {
        return array_values(array_filter($columns, fn (string $column) => ! array_key_exists($column, $this->identifiers) && ! array_key_exists($column, $this->attributes)));
    }

    /**
     * @param  array<class-string<Model>, Branch>  $branches
     */
    private function resolveBranches(null|string $retainedKey, bool $timestamps, null|string $discriminator, null|Closure $selector, array $branches): void
    {
        if ($selector === null) {
            throw $this->invalid('as() declares a successor for the servedFrom() selector, and the map says no servedFrom() selector');
        }

        if ($branches === []) {
            throw $this->invalid('servedFrom() takes a selector, and no as() declares a successor for it to choose');
        }

        $names = implode(' and ', array_map(class_basename(...), array_keys($branches)));

        if ($discriminator === null) {
            throw $this->invalid("servedFrom() chooses between {$names}, and they share one morph identity on one identifier sequence only through discriminatedBy()");
        }

        $resolved = [];
        $tables = [];

        foreach ($branches as $successor => $branch) {
            $table = $branch->successor->table;

            if (array_key_exists($table, $tables)) {
                throw $this->invalid('as('.class_basename($successor).") reads the table {$table}, which as(".class_basename($tables[$table]).') already reads');
            }

            $tables[$table] = $successor;
            $resolved[$successor] = new self(
                $this->model,
                $this->table,
                $this->identifiers,
                $this->retainedTable,
                $retainedKey ?? (new $this->model)->getKeyName(),
                $timestamps,
                $branch->successor,
                $this->hides,
                $this->guards,
                $this->withoutForwarding,
                $discriminator,
                branch: true,
            );
        }

        $this->refuseUnreadable($resolved);
        $first = reset($resolved);
        $this->primary = $first->primary;
        $this->retainedKey = $first->retainedKey;
        $this->related = $first->related;
        $this->attributes = $first->attributes;
        $this->owners = $first->owners;
        $this->membership = $first->membership;
        $this->selector = $selector;
        $this->branches = $resolved;
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
                    throw $this->invalid("attribute '{$name}' is readable in the ".class_basename($declared).' branch and not in the '.class_basename($successor).' branch; give it from(), external() or fixed() there');
                }
            }
        }

        foreach (array_keys($declaredIn) as $name) {
            $external = array_filter($branches, fn (ResolvedMap $branch) => $branch->attributes[$name]->isExternal());

            foreach ($external === [] ? [] : $branches as $successor => $branch) {
                $attribute = $branch->attributes[$name];

                if (! $attribute->isExternal() && ! ($attribute->column() === null && ($attribute->conversions()[0] ?? null) instanceof Fixed)) {
                    throw $this->invalid("attribute '{$name}' is external() in the ".class_basename((string) array_key_first($external)).' branch and reads SQL in the '.class_basename($successor).' branch, and the view serves an external attribute from no branch; declare it external() or fixed() there');
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
                throw $this->invalid("when() on {$name} decides membership, and only the servedFrom() successor carries the discriminator");
            }

            $tables[$successor->table] = $name;
            $successors[] = $successor;

            foreach ($successor->relations() as $relation) {
                $related[] = $relation;
                $pending[] = [$relation->successor, 'the relation from '.class_basename($relation->parent).' to '.class_basename($relation->model)];
            }
        }

        return [$related, $successors];
    }

    /**
     * @param  list<Successor>  $successors
     * @param  array<string, Attribute>  $timestamps
     * @return array{array<string, Attribute>, array<string, Successor>}
     */
    private function collect(array $successors, array $timestamps): array
    {
        $attributes = [];
        $owners = [];
        $declared = [];

        foreach ($successors as $successor) {
            foreach ($successor->attributes() as $name => $attribute) {
                $declared[] = [$name, $attribute, $successor];
            }
        }

        foreach ($timestamps as $name => $attribute) {
            $declared[] = [$name, $attribute, $successors[0]];
        }

        foreach ($declared as [$name, $attribute, $successor]) {
            if (array_key_exists($name, $this->identifiers)) {
                throw $this->invalid("attribute '{$name}' is also an identifier");
            }

            if (array_key_exists($name, $attributes)) {
                throw $this->invalid("attribute '{$name}' is declared twice");
            }

            $attributes[$name] = $attribute->resolve();
            $owners[$name] = $successor;
        }

        return [$attributes, $owners];
    }

    /**
     * @return array<string, Attribute>
     */
    private function timestamps(Model $legacy, Successor $primary): array
    {
        $successor = new $primary->model;

        if (! $legacy->usesTimestamps()) {
            throw $this->invalid('timestamps() maps the timestamps of '.class_basename($legacy).', which uses none');
        }

        if (! $successor->usesTimestamps()) {
            throw $this->invalid('timestamps() reads the timestamps of '.class_basename($successor).', which uses none');
        }

        $columns = [[$legacy->getCreatedAtColumn(), $successor->getCreatedAtColumn()], [$legacy->getUpdatedAtColumn(), $successor->getUpdatedAtColumn()]];

        if (method_exists($legacy, 'getDeletedAtColumn') && method_exists($successor, 'getDeletedAtColumn')) {
            $columns[] = [(string) $legacy->getDeletedAtColumn(), (string) $successor->getDeletedAtColumn()];
        }

        $attributes = [];

        foreach ($columns as [$legacyColumn, $successorColumn]) {
            if ($legacyColumn === null) {
                continue;
            }

            if ($successorColumn === null) {
                throw $this->invalid("timestamps() maps '{$legacyColumn}', and ".class_basename($successor).' keeps no such timestamp');
            }

            $attributes[$legacyColumn] = (new Attribute($this->table, $legacyColumn))->from($successorColumn);
        }

        return $attributes;
    }

    /**
     * @param  array<string, Attribute>  $attributes
     * @param  array<string, Successor>  $owners
     */
    private function refuseSharedColumns(Successor $primary, array $attributes, array $owners, null|string $discriminator): void
    {
        $columns = $discriminator === null ? [] : [$primary->table.'.'.$discriminator => 'discriminatedBy()'];

        foreach ($this->identifiers as $name => $identifier) {
            $column = $primary->table.'.'.$identifier->column();

            if (array_key_exists($column, $columns)) {
                throw $this->invalid("identifier '{$name}' reads {$column}, which {$columns[$column]} already reads");
            }

            $columns[$column] = "identifier '{$name}'";
        }

        foreach ($attributes as $name => $attribute) {
            if (! $attribute->isWritable()) {
                continue;
            }

            $column = $owners[$name]->table.'.'.$attribute->column();

            if (array_key_exists($column, $columns)) {
                throw $this->invalid("attribute '{$name}' writes {$column}, which {$columns[$column]} already owns; make one of them readOnly()");
            }

            $columns[$column] = "attribute '{$name}'";
        }
    }

    private function resolveMembership(Model $legacy, Successor $primary, null|string $discriminator): null|Membership
    {
        if ($discriminator === null) {
            return $primary->membershipScope() === null ? null : throw $this->invalid('when() on '.class_basename($primary->model).' states which rows may carry the morph alias, and the map declares no discriminatedBy()');
        }

        $alias = $legacy->getMorphClass();

        if ($alias === $legacy::class) {
            throw $this->invalid('discriminatedBy() stores the morph alias of '.class_basename($legacy).', which has none; register one with Relation::morphMap(), because a class name changes when the class moves');
        }

        return new Membership($this->table, $primary->model, $primary->table, $discriminator, $alias, $primary->membershipScope());
    }

    private function invalid(string $problem): InvalidCompatibilityMap
    {
        return InvalidCompatibilityMap::because($this->table, $problem);
    }
}
