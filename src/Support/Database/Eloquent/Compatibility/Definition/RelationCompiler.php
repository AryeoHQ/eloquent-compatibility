<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use BadMethodCallException;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;
use ReflectionProperty;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;

/**
 * Reads what a compatibility map needs from Eloquent relations and query scopes, and compiles their conditions to
 * the SQL the compatibility view and the writers run. It reads no database: bindings are inlined as literals.
 */
final readonly class RelationCompiler
{
    public function __construct(private string $map) {}

    /**
     * The hop from the retained model to the primary current model.
     *
     * @return array{model: class-string<Model>, foreignKey: string}
     */
    public function belongsTo(Model $parent, string $relation): array
    {
        $compiled = $this->relation($parent, $relation);

        if (! $compiled instanceof BelongsTo) {
            throw $this->invalid($parent::class."::{$relation}() must return a BelongsTo relation, because a retained row links one primary current row");
        }

        $related = $compiled->getRelated();

        if ($compiled->getOwnerKeyName() !== $related->getKeyName()) {
            throw $this->invalid($parent::class."::{$relation}() must link the key of ".$related::class.", not {$compiled->getOwnerKeyName()}");
        }

        $this->refuseScopes($related, $parent::class."::{$relation}()");

        return ['model' => $related::class, 'foreignKey' => $compiled->getForeignKeyName()];
    }

    /**
     * The hop from the primary current model to one dependent current row.
     *
     * @return array{model: class-string<Model>, foreignKey: string, readableWhen: string, orderedBy: null|string, attributes: array<string, mixed>}
     */
    public function hasOne(Model $parent, string $relation): array
    {
        $compiled = $this->relation($parent, $relation);
        $name = $parent::class."::{$relation}()";

        if (! $compiled instanceof HasOne) {
            throw $this->invalid("{$name} must return a HasOne relation, because each attribute of a dependent reads one row");
        }

        if ($compiled->getLocalKeyName() !== $parent->getKeyName()) {
            throw $this->invalid("{$name} must link the key of ".$parent::class.", not {$compiled->getLocalKeyName()}");
        }

        $related = $compiled->getRelated();
        $this->refuseScopes($related, $name);
        $query = $compiled->getQuery();
        $base = $query->toBase();

        if ($base->joins !== null && $base->joins !== []) {
            throw $this->invalid("{$name} joins another table, so the compatibility view cannot read it; a latestOfMany() or oldestOfMany() relation joins, so order it with orderBy() instead");
        }

        $foreignKey = $compiled->getForeignKeyName();
        $attributes = (new ReflectionProperty(Builder::class, 'pendingAttributes'))->getValue($query);
        unset($attributes[$foreignKey]);

        return [
            'model' => $related::class,
            'foreignKey' => $foreignKey,
            'readableWhen' => $this->conditions($base),
            'orderedBy' => $this->orders($base),
            'attributes' => $attributes,
        ];
    }

    /**
     * A query scope over a model, compiled to the SQL condition `<alias>.<key> IN (<the scope's keys>)`, so its
     * columns resolve inside its own subquery whatever else the view joins.
     *
     * @param  Closure(Builder<Model>): mixed  $scope
     */
    public function scope(Model $model, string $alias, Closure $scope): string
    {
        $query = $model->newQueryWithoutScopes();
        $scope($query);
        $base = $query->toBase();
        $base->columns = null;
        $base->select($model->getQualifiedKeyName());

        return $alias.'.'.SqlFragments::identifier($model->getKeyName()).' IN ('.SqlFragments::inline($base->toSql(), $base->getBindings(), $base->getGrammar()).')';
    }

    private function relation(Model $parent, string $relation): Relation
    {
        try {
            $compiled = Relation::noConstraints(fn () => $parent->{$relation}());
        } catch (BadMethodCallException) {
            throw $this->invalid($parent::class." has no relation {$relation}()");
        }

        if (! $compiled instanceof Relation) {
            throw $this->invalid($parent::class."::{$relation}() returns no Eloquent relation");
        }

        return $compiled;
    }

    /**
     * A global scope reads the application's state when it applies, such as a tenant's id. Compiled into the view,
     * it would freeze that state for every reader, so only SoftDeletes may take part.
     */
    private function refuseScopes(Model $related, string $relation): void
    {
        foreach ($related->getGlobalScopes() as $identifier => $scope) {
            if (! $scope instanceof SoftDeletingScope) {
                throw $this->invalid("{$relation} reaches ".$related::class.", whose global scope {$identifier} would be compiled into the compatibility view with the state it reads at install time; remove the scope from the model, or map a model without it");
            }
        }
    }

    private function conditions(QueryBuilder $base): string
    {
        if ($base->wheres === []) {
            return 'true';
        }

        $where = clone $base;
        $where->orders = null;
        $where->limit = null;
        $where->offset = null;
        $where->bindings['order'] = [];

        return SqlFragments::inline(Str::after($where->toSql(), ' where '), $where->getBindings(), $where->getGrammar());
    }

    private function orders(QueryBuilder $base): null|string
    {
        $orders = [];

        foreach ($base->orders ?? [] as $order) {
            $orders[] = isset($order['column'])
                ? $base->getGrammar()->wrap($order['column']).' '.$order['direction']
                : (string) ($order['sql'] ?? '');
        }

        $orders = array_values(array_filter($orders, fn (string $order) => $order !== ''));

        return $orders === [] ? null : implode(', ', $orders);
    }

    private function invalid(string $problem): InvalidCompatibilityMap
    {
        return InvalidCompatibilityMap::because($this->map, $problem);
    }
}
