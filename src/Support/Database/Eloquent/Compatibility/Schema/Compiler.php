<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\Assignment;
use Support\Database\Eloquent\Compatibility\Mapping\Attribute;
use Support\Database\Eloquent\Compatibility\Mapping\DependentCurrentModel;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Mapping\UnrepresentableState;
use Support\Database\Eloquent\Compatibility\Mapping\UnrepresentableStateKind;

/**
 * Compiles a compatibility map to its read-only compatibility view, after checking the map against the catalog.
 * The view joins the primary current model's table to the retained table, so PostgreSQL cannot write through it:
 * every write goes through the write router instead.
 */
final class Compiler
{
    private readonly ColumnCatalog $columnCatalog;

    public function __construct(ConnectionInterface $connection)
    {
        $this->columnCatalog = new ColumnCatalog($connection);
    }

    /**
     * @param  CompatibilityMap|ResolvedMap|class-string<Model>  $map  a map, its resolved form, or a previous model that defines one
     */
    public function compile(CompatibilityMap|ResolvedMap|string $map): CompiledSql
    {
        $resolvedMap = $map instanceof ResolvedMap ? $map : ResolvedMap::of(Compatibility::map($map));
        $this->validateColumns($resolvedMap);
        $this->validateFills($resolvedMap);

        return new CompiledSql(['CREATE VIEW '.SqlFragments::identifier($resolvedMap->previousModel->view).' AS '.$this->select($resolvedMap)]);
    }

    /**
     * The query the compatibility view runs, after the same checks as compile().
     *
     * @param  CompatibilityMap|ResolvedMap|class-string<Model>  $map
     */
    public function viewQuery(CompatibilityMap|ResolvedMap|string $map): string
    {
        $resolvedMap = $map instanceof ResolvedMap ? $map : ResolvedMap::of(Compatibility::map($map));
        $this->validateColumns($resolvedMap);

        return $this->select($resolvedMap);
    }

    private function select(ResolvedMap $resolvedMap): string
    {
        $previous = $resolvedMap->previousModel;
        $primary = $resolvedMap->primaryAlias();
        $columns = array_map(
            fn (Attribute $attribute) => $this->read($resolvedMap, $attribute).' AS '.SqlFragments::identifier($attribute->column),
            $resolvedMap->attributes,
        );
        $hidden = array_map(
            fn (UnrepresentableState $state) => 'NOT COALESCE(('.$state->checkCondition.'), false)',
            $resolvedMap->unrepresentableStates(UnrepresentableStateKind::Hide),
        );

        return "SELECT\n  ".implode(",\n  ", $columns)
            ."\nFROM ".SqlFragments::identifier($resolvedMap->table($primary)).' '.$primary
            ."\nJOIN ".SqlFragments::identifier((string) $previous->table).' '.$previous->alias
            .' ON '.$previous->alias.'.'.SqlFragments::identifier((string) $previous->primaryForeignKey).' = '.$primary.'.'.SqlFragments::identifier($resolvedMap->keyColumn($primary))
            .($hidden === [] ? '' : "\nWHERE ".implode("\n  AND ", $hidden));
    }

    private function read(ResolvedMap $resolvedMap, Attribute $attribute): string
    {
        if ($attribute->retained) {
            return (string) $attribute->readAs;
        }

        $dependent = $resolvedMap->dependent((string) $attribute->alias);
        $primary = $resolvedMap->primaryAlias();
        $expression = $dependent === null
            ? '('.$attribute->readAs.')'
            : '(SELECT '.$attribute->readAs.' '.$resolvedMap->locate($dependent, $primary.'.'.SqlFragments::identifier($resolvedMap->keyColumn($primary)), $resolvedMap->previousAlias()).')';

        return $attribute->type === null ? $expression : 'CAST('.$expression.' AS '.$attribute->type.')';
    }

    private function validateColumns(ResolvedMap $resolvedMap): void
    {
        $previous = $resolvedMap->previousModel;
        $table = (string) $previous->table;
        $columns = $this->columnCatalog->of($table);

        if ($columns === []) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, "the retained table {$table} does not exist");
        }

        foreach (array_unique(array_map($resolvedMap->table(...), array_values($resolvedMap->currentModels()))) as $currentTable) {
            if ($this->columnCatalog->of($currentTable) === []) {
                throw InvalidCompatibilityMap::because($resolvedMap->name, "the current table {$currentTable} does not exist");
            }
        }

        $designations = array_map(fn (DependentCurrentModel $dependent) => (string) $dependent->designationColumn, $resolvedMap->designated());

        foreach ([(string) $previous->keyColumn, (string) $previous->primaryForeignKey, ...$designations] as $column) {
            if (! array_key_exists($column, $columns)) {
                throw InvalidCompatibilityMap::because($resolvedMap->name, "{$table} has no column {$column}");
            }
        }

        foreach ($resolvedMap->attributes as $attribute) {
            if ($attribute->retained && ! array_key_exists($attribute->column, $columns)) {
                throw InvalidCompatibilityMap::because($resolvedMap->name, "retained attribute {$attribute->column} is not a column of {$table}");
            }

            foreach ($attribute->writesTo as $targetColumn) {
                if ($targetColumn->alias === $previous->alias && $targetColumn->column === $previous->primaryForeignKey) {
                    throw InvalidCompatibilityMap::because($resolvedMap->name, "attribute {$attribute->column} writes the primary foreign key {$targetColumn->column}; declare it read-only");
                }

                if ($targetColumn->alias === $previous->alias && in_array($targetColumn->column, $designations, true)) {
                    throw InvalidCompatibilityMap::because($resolvedMap->name, "attribute {$attribute->column} writes the designation column {$targetColumn->column}; the compatibility map maintains it");
                }

                $targetTable = $resolvedMap->table($targetColumn->alias);
                $target = $this->columnCatalog->of($targetTable)[$targetColumn->column] ?? null;

                if ($target === null) {
                    throw InvalidCompatibilityMap::because($resolvedMap->name, "{$targetTable} has no column {$targetColumn->column}");
                }

                if ($target->alwaysGenerated) {
                    throw InvalidCompatibilityMap::because($resolvedMap->name, "attribute {$attribute->column} writes {$targetTable}.{$targetColumn->column}, which the database always generates; declare it read-only");
                }
            }
        }

        foreach ($resolvedMap->dependentCurrentModels as $dependent) {
            $dependentTable = $resolvedMap->table((string) $dependent->alias);

            if ($this->columnCatalog->type($dependentTable, $resolvedMap->primaryForeignKey($dependent)) === null) {
                throw InvalidCompatibilityMap::because($resolvedMap->name, $dependentTable.' has no column '.$resolvedMap->primaryForeignKey($dependent));
            }
        }

        $fills = [$table => array_keys($previous->fillWhenCreatedFromCurrent)];

        foreach ([$resolvedMap->primaryCurrentModel, ...$resolvedMap->dependentCurrentModels] as $current) {
            $fills[$resolvedMap->table((string) $current->alias)] = [...$fills[$resolvedMap->table((string) $current->alias)] ?? [], ...array_keys($current->fillWhenCreatedFromPrevious)];
        }

        foreach ($fills as $filledTable => $filledColumns) {
            foreach ($filledColumns as $column) {
                if ($this->columnCatalog->type($filledTable, $column) === null) {
                    throw InvalidCompatibilityMap::because($resolvedMap->name, "{$filledTable} has no column {$column}");
                }
            }
        }
    }

    private function validateFills(ResolvedMap $resolvedMap): void
    {
        $previous = $resolvedMap->previousModel;
        $previousAlias = $resolvedMap->previousAlias();
        $primary = $resolvedMap->primaryCurrentModel;
        $primaryTable = $resolvedMap->table((string) $primary->alias);
        $throughPrevious = "an insert through {$previous->model} leaves it empty; ";
        $targets = fn (string $alias) => array_map(fn (Assignment $assignment) => $assignment->targetColumn->column, $resolvedMap->insertAssignmentsTo($alias));
        $retained = array_map(fn (Attribute $attribute) => $attribute->column, array_filter($resolvedMap->attributes, fn (Attribute $attribute) => $attribute->retained));

        if ($previous->fillingWhenCreatedFromCurrent === null) {
            $this->refuseEmpty($resolvedMap, (string) $previous->table, [(string) $previous->primaryForeignKey, ...array_keys($previous->fillWhenCreatedFromCurrent)], "a {$primaryTable} row created through its own model leaves it empty; fill it with fillWhenCreatedFromCurrent()");
        }
        $this->refuseEmpty($resolvedMap, (string) $previous->table, [(string) $previous->primaryForeignKey, ...$retained, ...$targets($previousAlias)], $throughPrevious.'declare it as a retained attribute');
        // A creation hook sets attributes only when it runs, so a table with one is left to the database's own check.
        if ($primary->creating === []) {
            $this->refuseEmpty($resolvedMap, $primaryTable, [...$targets((string) $primary->alias), ...array_keys($primary->fillWhenCreatedFromPrevious)], $throughPrevious.'map an attribute to it or fill it with fillWhenCreatedFromPrevious()');
        }

        foreach ($resolvedMap->dependentCurrentModels as $dependent) {
            $alias = (string) $dependent->alias;

            if ($dependent->creating === [] && ($resolvedMap->insertAssignmentsTo($alias) !== [] || $dependent->fillWhenCreatedFromPrevious !== [])) {
                $this->refuseEmpty($resolvedMap, $resolvedMap->table($alias), [$resolvedMap->primaryForeignKey($dependent), ...$targets($alias), ...array_keys($dependent->fillWhenCreatedFromPrevious)], $throughPrevious.'map an attribute to it or fill it with fillWhenCreatedFromPrevious()');
            }
        }
    }

    /**
     * @param  list<string>  $filled
     */
    private function refuseEmpty(ResolvedMap $resolvedMap, string $table, array $filled, string $problem): void
    {
        foreach ($this->columnCatalog->of($table) as $name => $column) {
            if ($column->needsAValue() && ! in_array($name, $filled, true)) {
                throw InvalidCompatibilityMap::because($resolvedMap->name, "{$table}.{$name} is NOT NULL without a default, and {$problem}");
            }
        }
    }
}
