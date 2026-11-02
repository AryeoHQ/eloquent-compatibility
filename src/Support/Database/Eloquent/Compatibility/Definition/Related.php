<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use BadMethodCallException;
use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use ReflectionProperty;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;

final readonly class Related
{
    /**
     * @param  class-string<Model>  $parent
     * @param  class-string<Model>  $model
     * @param  list<mixed>  $bindings
     * @param  array<string, mixed>  $attributes
     */
    private function __construct(
        public Closure $relation,
        public string $parent,
        public string $parentTable,
        public string $parentKey,
        public string $model,
        public string $table,
        public string $relatedKey,
        public bool $belongsTo,
        public null|string $conditions,
        public array $bindings,
        public null|string $orders,
        public null|string $deletedAt,
        public array $attributes,
        public Successor $successor,
    ) {}

    /**
     * @param  class-string<Model>  $parent
     * @param  Closure(Successor): mixed  $definition
     */
    public static function compile(string $map, string $parent, Closure $relation, Closure $definition): self
    {
        ResolvedMap::refuseCaptures($map, 'a related() relation closure on '.class_basename($parent), $relation);
        ResolvedMap::refuseCaptures($map, 'a related() definition closure on '.class_basename($parent), $definition);

        $compiled = self::offline($map, fn () => Relation::noConstraints(fn () => self::call($map, $parent, $relation)));
        $related = $compiled->getRelated();
        $name = 'the related() relation from '.class_basename($parent).' to '.class_basename($related);

        if ($compiled instanceof HasMany) {
            throw InvalidCompatibilityMap::because($map, "{$name} is a HasMany, and one legacy record reads one row of each related table");
        }

        if ((! $compiled instanceof HasOne && ! $compiled instanceof BelongsTo) || $compiled instanceof MorphTo) {
            throw InvalidCompatibilityMap::because($map, "{$name} is a ".class_basename($compiled).', and related() reads a HasOne or a BelongsTo');
        }

        if ($compiled instanceof HasOne && $compiled->isOneOfMany()) {
            throw InvalidCompatibilityMap::because($map, "{$name} is an \"of many\" relation, which joins; order a plain HasOne instead");
        }

        $query = $compiled->getQuery();
        $base = $query->getQuery();

        if ($base->joins !== null && $base->joins !== []) {
            throw InvalidCompatibilityMap::because($map, "{$name} joins another table");
        }

        if (($base->offset ?? 0) > 0 || ($base->groups ?? []) !== [] || ($base->havings ?? []) !== [] || ($base->unions ?? []) !== []) {
            throw InvalidCompatibilityMap::because($map, "{$name} applies an offset, a group or a union, and the view reads the first row of its conditions and order");
        }

        if ($base->bindings['order'] !== []) {
            throw InvalidCompatibilityMap::because($map, "{$name} orders by an expression with bindings");
        }

        $scopes = array_values(array_diff((clone $query)->withoutGlobalScopes()->removedScopes(), $query->removedScopes()));

        foreach ($scopes as $scope) {
            if ($scope !== SoftDeletingScope::class) {
                throw InvalidCompatibilityMap::because($map, "{$name} applies the global scope {$scope}, which would read the application's state once and keep it; only SoftDeletes may apply");
            }
        }

        $grammar = $base->getGrammar();
        $where = $grammar->compileWheres($base);
        $orders = [];

        foreach ($base->orders ?? [] as $order) {
            $orders[] = array_key_exists('column', $order) ? $grammar->wrap($order['column']).' '.$order['direction'] : (string) $order['sql'];
        }

        $successor = new Successor($map, $related::class);
        $definition($successor);

        if ($successor->atMostOneBecause() !== null) {
            self::refuseUniqueness($map, $name, $related->getTable(), $base->wheres);
        }

        [$parentKey, $relatedKey] = $compiled instanceof BelongsTo
            ? [$compiled->getForeignKeyName(), $compiled->getOwnerKeyName()]
            : [$compiled->getLocalKeyName(), $compiled->getForeignKeyName()];

        return new self(
            $relation,
            $parent,
            $compiled->getParent()->getTable(),
            $parentKey,
            $related::class,
            $related->getTable(),
            $relatedKey,
            $compiled instanceof BelongsTo,
            $where === '' ? null : Str::after($where, 'where '),
            SqlFragments::formatDates(array_values($base->bindings['where']), $grammar->getDateFormat()),
            $orders === [] ? null : implode(', ', $orders),
            in_array(SoftDeletingScope::class, $scopes, true) && method_exists($related, 'getDeletedAtColumn') ? (string) $related->getDeletedAtColumn() : null,
            $query->pendingAttributes,
            $successor,
        );
    }

    /**
     * @param  class-string<Model>  $parent
     * @return Relation<Model, Model, mixed>
     */
    private static function call(string $map, string $parent, Closure $relation): Relation
    {
        $model = new $parent;
        $model->exists = true;
        $model->setRawAttributes([]);
        (new ReflectionProperty(Model::class, 'casts'))->setValue($model, []);
        $violations = new ReflectionProperty(Model::class, 'missingAttributeViolationCallback');
        $violation = $violations->getValue();
        $prevents = Model::preventsAccessingMissingAttributes();
        Model::preventAccessingMissingAttributes();
        Model::handleMissingAttributeViolationUsing(fn (Model $model, string $key) => throw InvalidCompatibilityMap::because($map, 'a related() relation closure on '.class_basename($parent)." reads its attribute {$key}, and the view compiles one relation for every ".class_basename($parent).", so a condition cannot follow each row's value"));

        try {
            $compiled = $relation($model);
        } catch (BadMethodCallException $exception) {
            throw InvalidCompatibilityMap::because($map, 'a related() relation closure on '.class_basename($parent).' calls a method '.class_basename($parent).' does not have: '.$exception->getMessage());
        } finally {
            Model::preventAccessingMissingAttributes($prevents);
            Model::handleMissingAttributeViolationUsing(is_callable($violation) ? $violation : null);
        }

        if (! $compiled instanceof Relation) {
            throw InvalidCompatibilityMap::because($map, 'a related() relation closure on '.class_basename($parent).' returns '.get_debug_type($compiled).', which is not an Eloquent relation');
        }

        return $compiled;
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $build
     * @return TResult
     */
    private static function offline(string $map, Closure $build): mixed
    {
        $resolver = Model::getConnectionResolver();

        Model::setConnectionResolver(new readonly class($map) implements ConnectionResolverInterface
        {
            public function __construct(private string $map) {}

            public function connection(mixed $name = null): ConnectionInterface
            {
                $refuse = fn () => throw InvalidCompatibilityMap::because($this->map, 'a related() relation reads the database while Laravel builds it, and resolution opens no connection');

                return new PostgresConnection($refuse, '', '', ['driver' => 'pgsql', 'name' => is_string($name) ? $name : 'compatibility']);
            }

            public function getDefaultConnection(): string
            {
                return 'compatibility';
            }

            public function setDefaultConnection(mixed $name): void {}
        });

        try {
            return $build();
        } catch (QueryException $exception) {
            throw $exception->getPrevious() instanceof InvalidCompatibilityMap ? $exception->getPrevious() : $exception;
        } finally {
            if ($resolver === null) {
                Model::unsetConnectionResolver();
            } else {
                Model::setConnectionResolver($resolver);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $wheres
     */
    private static function refuseUniqueness(string $map, string $name, string $table, array $wheres): void
    {
        foreach ($wheres as $where) {
            $type = is_array($where) ? $where['type'] : null;
            $column = is_array($where) ? ($where['column'] ?? null) : null;

            if (! in_array($type, ['Basic', 'Null', 'NotNull', 'In', 'NotIn'], true) || ! is_string($column) || ($type === 'Basic' && $where['value'] instanceof Expression)) {
                throw InvalidCompatibilityMap::because($map, "atMostOne() on {$name} compiles its conditions into a unique index, and a ".(is_string($type) ? $type : 'raw').' condition is not a plain column predicate');
            }

            if (str_contains($column, '.') && ! str_starts_with($column, $table.'.')) {
                throw InvalidCompatibilityMap::because($map, "atMostOne() on {$name} compiles its conditions into a unique index on {$table}, and {$column} is not a column of {$table}");
            }
        }
    }
}
