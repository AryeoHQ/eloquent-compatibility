<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use ReflectionProperty;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;

final readonly class RelatedMorph
{
    /** @var non-empty-array<class-string<Model>, Related> */
    public array $targets;

    /**
     * @param  class-string<Model>  $parent
     * @param  non-empty-array<class-string<Model>, array{Successor, null|string}>  $declared
     */
    private function __construct(
        public Closure $relation,
        public string $parent,
        public string $parentTable,
        public string $typeColumn,
        public string $idColumn,
        public null|Closure $choose,
        array $declared,
    ) {
        $targets = [];

        foreach ($declared as $model => [$successor, $deletedAt]) {
            $targets[$model] = Related::morphTarget($this, $model, $successor, $deletedAt);
        }

        $this->targets = $targets;
    }

    /**
     * @param  class-string<Model>  $parent
     * @param  Closure(Successors): mixed  $successors
     */
    public static function compile(string $map, string $parent, Closure $relation, Closure $successors, null|Closure $choose): self
    {
        ResolvedMap::refuseCaptures($map, 'a relatedMorph() relation closure on '.class_basename($parent), $relation);

        $compiled = Related::relationOf($map, 'relatedMorph()', $parent, $relation);

        if (! $compiled instanceof MorphTo) {
            throw InvalidCompatibilityMap::because($map, 'the relatedMorph() relation from '.class_basename($parent).' to '.class_basename($compiled->getRelated()).' is a '.class_basename($compiled).', and relatedMorph() reads a MorphTo'.($compiled instanceof HasOne || $compiled instanceof BelongsTo ? '; declare it with related()' : ' only'));
        }

        if (self::constrains($compiled)) {
            throw InvalidCompatibilityMap::because($map, 'the relatedMorph() relation from '.class_basename($parent).' through '.$compiled->getRelationName().'() constrains the targets it reads, and the view and the writers join each target on its morph alias and key alone; drop the constraint, because relatedMorph() serves every target row a '.class_basename($parent).' names');
        }

        if (! in_array((new ReflectionProperty($compiled, 'withDefault'))->getValue($compiled), [null, false], true)) {
            throw InvalidCompatibilityMap::because($map, 'the relatedMorph() relation from '.class_basename($parent).' through '.$compiled->getRelationName().'() returns a default model when no target row exists, and the view and the writers serve NULL for each attribute of a missing target; drop withDefault()');
        }

        $declared = [];

        foreach (Successors::declare($map, 'relatedMorph()', $choose, $successors, 'drop choose:, and relatedMorph() reads %s for every record') as $model => $branch) {
            $key = (new $model)->getKeyName();

            if (! in_array($compiled->getOwnerKeyName(), [null, $key], true)) {
                throw InvalidCompatibilityMap::because($map, 'the relatedMorph() relation from '.class_basename($parent).' through '.$compiled->getRelationName().'() matches each target on the owner key '.$compiled->getOwnerKeyName().', and '.class_basename($model)." keys its rows by {$key}; drop the owner key, because the view and the writers join each target on its key");
            }

            $declared[$model] = [$branch->successor, self::refuseTarget($map, $parent, $compiled->getParent()->getTable(), $branch->successor)];
        }

        return new self($relation, $parent, $compiled->getParent()->getTable(), $compiled->getMorphType(), $compiled->getForeignKeyName(), $choose, $declared);
    }

    /**
     * @param  MorphTo<Model, Model>  $relation
     */
    private static function constrains(MorphTo $relation): bool
    {
        $base = $relation->getQuery()->getQuery();

        return $base->wheres !== []
            || ($base->orders ?? []) !== []
            || $base->limit !== null
            || $base->offset !== null
            || ($base->joins ?? []) !== []
            || ($base->groups ?? []) !== []
            || ($base->havings ?? []) !== []
            || ($base->unions ?? []) !== []
            || (new ReflectionProperty($relation, 'macroBuffer'))->getValue($relation) !== []
            || (new ReflectionProperty($relation, 'morphableConstraints'))->getValue($relation) !== [];
    }

    private static function refuseTarget(string $map, string $parent, string $parentTable, Successor $target): null|string
    {
        $name = class_basename($target->model).', which relatedMorph() reaches,';

        if ($target->membershipScope() !== null) {
            throw InvalidCompatibilityMap::because($map, "when() on {$name} decides membership through a CHECK on {$parentTable}, and a CHECK cannot read {$target->table}; declare when() on ".class_basename($parent).', whose discriminator column keys membership');
        }

        if ($target->atMostOneBecause() !== null) {
            throw InvalidCompatibilityMap::because($map, "atMostOne() on {$name} refuses a second row, and a MorphTo reads one {$target->table} row by its key; drop atMostOne()");
        }

        if ($target->relations() !== [] || $target->morph() !== null) {
            throw InvalidCompatibilityMap::because($map, "{$name} declares related() or relatedMorph() of its own, and a morph target serves the columns of its own table; declare the relation on ".class_basename($parent));
        }

        foreach ($target->attributes() as $attribute => $definition) {
            if ($definition->runsTransitions()) {
                throw InvalidCompatibilityMap::because($map, "attribute '{$attribute}' on {$name} is declared transitions(), and a change of target creates its row anew; declare transitions() on an attribute of ".class_basename($parent));
            }
        }

        $scopes = array_keys((new $target->model)->getGlobalScopes());

        foreach ($scopes as $scope) {
            if ($scope !== SoftDeletingScope::class) {
                throw InvalidCompatibilityMap::because($map, "{$name} applies the global scope {$scope}, which would read the application's state once and keep it; only SoftDeletes may apply");
            }
        }

        $model = new $target->model;

        return in_array(SoftDeletingScope::class, $scopes, true) && method_exists($model, 'getDeletedAtColumn') ? (string) $model->getDeletedAtColumn() : null;
    }
}
