<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use ArrayAccess;
use BadMethodCallException;
use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Concerns\HasAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionProperty;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use TypeError;

/**
 * @internal
 */
final readonly class Related
{
    /**
     * @param  class-string<Model>  $parent
     * @param  class-string<Model>  $model
     * @param  list<mixed>  $bindings
     * @param  array<string, mixed>  $attributes
     * @param  null|list<string>  $conditionColumns
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
        public null|string $morphType = null,
        public null|string $morphAlias = null,
        public null|array $conditionColumns = [],
    ) {}

    /**
     * @param  class-string<Model>  $parent
     * @param  Closure(Successor): mixed  $definition
     */
    public static function compile(string $map, string $parent, Closure $relation, Closure $definition): self
    {
        ResolvedMap::refuseCaptures($map, 'a related() relation closure on '.class_basename($parent), $relation);
        ResolvedMap::refuseCaptures($map, 'a related() definition closure on '.class_basename($parent), $definition);

        $compiled = self::relationOf($map, 'related()', $parent, $relation);

        if ($compiled instanceof MorphTo) {
            throw InvalidCompatibilityMap::because($map, 'the related() relation from '.class_basename($parent).' through '.$compiled->getRelationName().'() is a MorphTo, whose related model varies per record, and related() reads a HasOne or a BelongsTo; declare it with relatedMorph()');
        }

        $related = $compiled->getRelated();
        $name = 'the related() relation from '.class_basename($parent).' to '.class_basename($related);

        if ($compiled instanceof HasMany) {
            throw InvalidCompatibilityMap::because($map, "{$name} is a HasMany, and one legacy record reads one row of each related table");
        }

        if (! $compiled instanceof HasOne && ! $compiled instanceof BelongsTo) {
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

        if (array_filter($base->wheres, fn (mixed $where) => is_array($where) && str_starts_with((string) ($where['boolean'] ?? 'and'), 'or')) !== []) {
            throw InvalidCompatibilityMap::because($map, "{$name} joins a condition with or outside a group, and Eloquent then reads rows of every ".class_basename($parent).'; group the conditions in a nested where()');
        }

        if (! in_array((new ReflectionProperty($compiled, 'withDefault'))->getValue($compiled), [null, false], true)) {
            throw InvalidCompatibilityMap::because($map, "{$name} returns a default model when no row exists, and the view serves NULL for each attribute of a missing row");
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
            SqlFragments::asSent(array_values($base->bindings['where']), $grammar->getDateFormat()),
            $orders === [] ? null : implode(', ', $orders),
            in_array(SoftDeletingScope::class, $scopes, true) && method_exists($related, 'getDeletedAtColumn') ? (string) $related->getDeletedAtColumn() : null,
            $query->pendingAttributes,
            $successor,
            conditionColumns: self::ownColumns(
                table: $related->getTable(),
                wheres: $base->wheres,
            ),
        );
    }

    /**
     * @param  class-string<Model>  $model
     */
    public static function morphTarget(RelatedMorph $morph, string $model, Successor $successor, null|string $deletedAt): self
    {
        $target = new $model;

        return new self(
            $morph->relation,
            $morph->parent,
            $morph->parentTable,
            $morph->idColumn,
            $model,
            $target->getTable(),
            $target->getKeyName(),
            true,
            null,
            [],
            null,
            $deletedAt,
            [],
            $successor,
            $morph->typeColumn,
            $target->getMorphClass(),
        );
    }

    /**
     * @param  class-string<Model>  $parent
     * @return Relation<Model, Model, mixed>
     */
    public static function relationOf(string $map, string $method, string $parent, Closure $relation): Relation
    {
        return self::offline($map, $method, fn () => Relation::noConstraints(fn () => self::call($map, $method, $parent, $relation)));
    }

    /**
     * @param  class-string<Model>  $parent
     * @return Relation<Model, Model, mixed>
     */
    private static function call(string $map, string $method, string $parent, Closure $relation): Relation
    {
        $reads = fn (string $what) => InvalidCompatibilityMap::because($map, "a {$method} relation closure on ".class_basename($parent)." reads its {$what}, and the view compiles one relation for every ".class_basename($parent).", so a condition cannot follow each row's value");
        $model = new $parent;
        $model->exists = true;
        $model->setRawAttributes([]);
        (new ReflectionProperty(Model::class, 'casts'))->setValue($model, []);
        (new ReflectionProperty(Model::class, 'original'))->setValue($model, new readonly class($reads) implements ArrayAccess
        {
            /**
             * @param  Closure(string): InvalidCompatibilityMap  $reads
             */
            public function __construct(private Closure $reads) {}

            public function offsetExists(mixed $offset): bool
            {
                throw ($this->reads)('attribute '.(is_scalar($offset) ? (string) $offset : get_debug_type($offset)));
            }

            public function offsetGet(mixed $offset): mixed
            {
                throw ($this->reads)('attribute '.(is_scalar($offset) ? (string) $offset : get_debug_type($offset)));
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
                throw ($this->reads)('original attributes');
            }

            public function offsetUnset(mixed $offset): void
            {
                throw ($this->reads)('original attributes');
            }
        });
        $violations = new ReflectionProperty(Model::class, 'missingAttributeViolationCallback');
        $violation = $violations->getValue();
        $prevents = Model::preventsAccessingMissingAttributes();
        Model::preventAccessingMissingAttributes();
        Model::handleMissingAttributeViolationUsing(fn (Model $model, string $key) => throw $reads("attribute {$key}"));

        try {
            $compiled = $relation($model);
        } catch (BadMethodCallException $exception) {
            throw InvalidCompatibilityMap::because($map, "a {$method} relation closure on ".class_basename($parent).' calls a method '.class_basename($parent).' does not have: '.$exception->getMessage());
        } catch (TypeError $exception) {
            throw $exception->getFile() === (new ReflectionClass(HasAttributes::class))->getFileName() ? $reads('original attributes') : $exception;
        } finally {
            Model::preventAccessingMissingAttributes($prevents);
            Model::handleMissingAttributeViolationUsing(is_callable($violation) ? $violation : null);
        }

        if (! $compiled instanceof Relation) {
            throw InvalidCompatibilityMap::because($map, "a {$method} relation closure on ".class_basename($parent).' returns '.get_debug_type($compiled).', which is not an Eloquent relation');
        }

        return $compiled;
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $build
     * @return TResult
     */
    private static function offline(string $map, string $method, Closure $build): mixed
    {
        $resolver = Model::getConnectionResolver();

        Model::setConnectionResolver(new readonly class($map, $method) implements ConnectionResolverInterface
        {
            public function __construct(private string $map, private string $method) {}

            public function connection(mixed $name = null): ConnectionInterface
            {
                $refuse = fn () => throw InvalidCompatibilityMap::because($this->map, "a {$this->method} relation reads the database while Laravel builds it, and resolution opens no connection");

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
            $column = self::plainColumn($where);

            if ($column === null) {
                throw InvalidCompatibilityMap::because($map, "atMostOne() on {$name} compiles its conditions into a unique index, and a ".(is_string($type) ? $type : 'raw').' condition is not a plain column predicate');
            }

            if (str_contains($column, '.') && ! str_starts_with($column, $table.'.')) {
                throw InvalidCompatibilityMap::because($map, "atMostOne() on {$name} compiles its conditions into a unique index on {$table}, and {$column} is not a column of {$table}");
            }
        }
    }

    private static function plainColumn(mixed $where): null|string
    {
        $type = is_array($where) ? $where['type'] : null;
        $column = is_array($where) ? ($where['column'] ?? null) : null;

        return in_array(
            needle: $type,
            haystack: ['Basic', 'Null', 'NotNull', 'In', 'NotIn'],
            strict: true,
        ) && is_string($column) && ! ($type === 'Basic' && $where['value'] instanceof Expression) ? $column : null;
    }

    /**
     * @param  array<int, mixed>  $wheres
     * @return null|list<string>
     */
    private static function ownColumns(string $table, array $wheres): null|array
    {
        $columns = [];

        foreach ($wheres as $where) {
            if (is_array($where) && $where['type'] === 'Nested' && $where['query'] instanceof Builder) {
                $nested = self::ownColumns(
                    table: $table,
                    wheres: $where['query']->wheres,
                );

                if ($nested === null) {
                    return null;
                }

                $columns = [...$columns, ...$nested];

                continue;
            }

            $column = self::plainColumn($where);

            if ($column === null || (str_contains(
                haystack: $column,
                needle: '.',
            ) && ! str_starts_with(
                haystack: $column,
                needle: $table.'.',
            ))) {
                return null;
            }

            $columns[] = Str::after(
                subject: $column,
                search: $table.'.',
            );
        }

        return array_values(array_unique($columns));
    }
}
