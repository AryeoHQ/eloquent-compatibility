<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Closure;
use Illuminate\Database\Eloquent\Model;
use ReflectionFunction;
use Support\Database\Eloquent\Compatibility\Compatibility;
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

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, Identifier>  $identifiers
     * @param  list<Hide>  $hides
     * @param  array<string, Guard>  $guards
     * @param  array<class-string, string>  $withoutForwarding
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
    ) {
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
            $identifier->resolve($primary);
        }

        [$related, $successors] = $this->walk($primary);
        [$attributes, $owners] = $this->collect($successors, $timestamps ? $this->timestamps($legacy, $primary) : []);
        $this->refuseSharedColumns($primary, $attributes, $owners);

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

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return array_values(array_unique(array_filter([$this->table, $this->retainedTable, $this->primary->table, ...array_map(fn (Related $related) => $related->table, $this->related)], is_string(...))));
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
    private function refuseSharedColumns(Successor $primary, array $attributes, array $owners): void
    {
        $columns = [];

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

    private function invalid(string $problem): InvalidCompatibilityMap
    {
        return InvalidCompatibilityMap::because($this->table, $problem);
    }
}
