<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Builder;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Arr;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityQueryRefused;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;

/**
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
final class LegacyBuilder extends Builder
{
    /** @var array<string, ResolvedMap> */
    private static array $maps = [];

    /** @var array<string, bool> */
    private static array $views = [];

    private int $negations = 0;

    public static function forgetViews(): void
    {
        self::$views = [];
    }

    /**
     * @param  Closure|Expression|array<array-key, mixed>|string  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     * @param  string  $boolean
     * @return $this
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if ($column instanceof Closure && $operator === null) {
            $nested = $this->model->newQueryWithoutRelationships();

            if ($nested instanceof self) {
                $nested->negations = $this->negations + (self::negates($boolean) ? 1 : 0);
            }

            $column($nested);
            $this->eagerLoad = array_merge($this->eagerLoad, $nested->getEagerLoads());
            $this->withoutGlobalScopes($nested->removedScopes());
            $this->query->addNestedWhereQuery($nested->getQuery(), $boolean);

            return $this;
        }

        if (is_array($column) && $this->touchesConversions($column)) {
            $this->where(function (self $query) use ($column, $boolean): void {
                foreach ($column as $key => $item) {
                    is_int($key) && is_array($item) ? $query->where(...array_values($item)) : $query->where((string) $key, '=', $item, $boolean);
                }
            }, null, null, $boolean);

            return $this;
        }

        $attribute = is_string($column) ? $this->converted($column) : null;

        if ($attribute === null) {
            parent::where(...func_get_args());

            return $this;
        }

        [$value, $operator] = func_num_args() === 2 ? [$operator, '='] : [$value, $operator];

        if (! is_string($operator) || ! in_array(strtolower($operator), [...$this->query->operators, ...$this->query->getGrammar()->getOperators()], true)) {
            [$value, $operator] = [$operator, '='];
        }

        $operator = strtolower($operator);
        $filter = $this->filter($attribute, $operator, $value, "where {$operator}");

        return $this->translate($attribute, self::negates($boolean), $boolean, fn (QueryBuilder $stored, string $column) => $stored->where($column, ...$filter));
    }

    /**
     * @param  Expression|string  $column
     * @param  mixed  $values
     * @param  string  $boolean
     * @param  bool  $not
     * @return $this
     */
    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        $attribute = is_string($column) ? $this->converted($column) : null;
        $values = $values instanceof Arrayable ? $values->toArray() : $values;

        if ($attribute === null || $values === []) {
            $this->query->whereIn($column, $values, $boolean, $not);

            return $this;
        }

        $operation = $not ? 'whereNotIn' : 'whereIn';

        if (! is_array($values)) {
            throw $this->refuse($attribute, $operation, 'a subquery does not pass through its conversions.');
        }

        $filters = array_map(fn (mixed $value) => $this->filter($attribute, '=', $value, $operation), array_values($values));

        return $this->translate($attribute, $not !== self::negates($boolean), $boolean, function (QueryBuilder $stored, string $column) use ($filters): void {
            if (array_unique(array_column($filters, 0)) === ['=']) {
                $stored->whereIn($column, array_column($filters, 1));

                return;
            }

            foreach ($filters as $filter) {
                $stored->orWhere($column, ...$filter);
            }
        });
    }

    /**
     * @param  Expression|string  $column
     * @param  mixed  $values
     * @param  string  $boolean
     * @return $this
     */
    public function whereNotIn($column, $values, $boolean = 'and')
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    /**
     * @param  Expression|string  $column
     * @param  mixed  $values
     * @return $this
     */
    public function orWhereIn($column, $values)
    {
        return $this->whereIn($column, $values, 'or');
    }

    /**
     * @param  Expression|string  $column
     * @param  mixed  $values
     * @return $this
     */
    public function orWhereNotIn($column, $values)
    {
        return $this->whereIn($column, $values, 'or', true);
    }

    /**
     * @param  Expression|string  $column
     * @param  iterable<mixed>  $values
     * @param  string  $boolean
     * @param  bool  $not
     * @return $this
     */
    public function whereBetween($column, iterable $values, $boolean = 'and', $not = false)
    {
        $attribute = is_string($column) ? $this->converted($column) : null;

        if ($attribute === null) {
            $this->query->whereBetween($column, $values, $boolean, $not);

            return $this;
        }

        $operation = $not ? 'whereNotBetween' : 'whereBetween';

        if (! is_array($values)) {
            throw $this->refuse($attribute, $operation, 'its bounds must be an array of two values.');
        }

        [$low, $high] = array_pad(array_slice(Arr::flatten($values), 0, 2), 2, null);
        $low = $this->filter($attribute, '>=', $low, $operation);
        $high = $this->filter($attribute, '<=', $high, $operation);

        return $this->translate($attribute, $not !== self::negates($boolean), $boolean, fn (QueryBuilder $stored, string $column) => $stored->where($column, ...$low)->where($column, ...$high));
    }

    /**
     * @param  Expression|string  $column
     * @param  iterable<mixed>  $values
     * @param  string  $boolean
     * @return $this
     */
    public function whereNotBetween($column, iterable $values, $boolean = 'and')
    {
        return $this->whereBetween($column, $values, $boolean, true);
    }

    /**
     * @param  Expression|string  $column
     * @param  iterable<mixed>  $values
     * @return $this
     */
    public function orWhereBetween($column, iterable $values)
    {
        return $this->whereBetween($column, $values, 'or');
    }

    /**
     * @param  Expression|string  $column
     * @param  iterable<mixed>  $values
     * @return $this
     */
    public function orWhereNotBetween($column, iterable $values)
    {
        return $this->whereBetween($column, $values, 'or', true);
    }

    /**
     * @param  string|array<array-key, Expression|string>|Expression  $columns
     * @param  string  $boolean
     * @param  bool  $not
     * @return $this
     */
    public function whereNull($columns, $boolean = 'and', $not = false)
    {
        foreach (Arr::wrap($columns) as $column) {
            $attribute = is_string($column) ? $this->converted($column) : null;

            if ($attribute === null) {
                $this->query->whereNull($column, $boolean, $not);

                continue;
            }

            $filter = $this->filter($attribute, $not ? '!=' : '=', null, $not ? 'whereNotNull' : 'whereNull');
            $this->translate($attribute, self::negates($boolean), $boolean, fn (QueryBuilder $stored, string $column) => $stored->where($column, ...$filter));
        }

        return $this;
    }

    /**
     * @param  string|array<array-key, Expression|string>|Expression  $columns
     * @param  string  $boolean
     * @return $this
     */
    public function whereNotNull($columns, $boolean = 'and')
    {
        return $this->whereNull($columns, $boolean, true);
    }

    /**
     * @param  string|array<array-key, Expression|string>|Expression  $columns
     * @return $this
     */
    public function orWhereNull($columns)
    {
        return $this->whereNull($columns, 'or');
    }

    /**
     * @param  string|array<array-key, Expression|string>|Expression  $columns
     * @return $this
     */
    public function orWhereNotNull($columns)
    {
        return $this->whereNull($columns, 'or', true);
    }

    /**
     * @param  Closure|QueryBuilder|Builder<Model>|Expression|string  $column
     * @param  mixed  $direction
     * @return $this
     */
    public function orderBy($column, $direction = 'asc')
    {
        $this->query->orderBy($column, $direction);
        $attribute = is_string($column) ? $this->converted($column) : null;

        if ($attribute !== null) {
            $orders = $this->query->unions ? $this->query->unionOrders : $this->query->orders;
            $normalized = (string) end($orders)['direction'];

            if ($this->definition($attribute)->isExternal()) {
                throw $this->refuse($attribute, "orderBy {$normalized}", 'it is stored outside the database, so no SQL reads it.');
            }

            if ($this->definition($attribute)->order($normalized) === null) {
                throw $this->refuse($attribute, "orderBy {$normalized}", 'its conversions do not keep the order of the stored values.');
            }
        }

        return $this;
    }

    /**
     * @param  Closure|QueryBuilder|Builder<Model>|Expression|string  $column
     * @return $this
     */
    public function orderByDesc($column)
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * @param  Expression|string|null  $column
     * @return $this
     */
    public function latest($column = null)
    {
        return $this->orderBy($column ?? $this->createdAt(), 'desc');
    }

    /**
     * @param  Expression|string|null  $column
     * @return $this
     */
    public function oldest($column = null)
    {
        return $this->orderBy($column ?? $this->createdAt(), 'asc');
    }

    /**
     * @param  Expression|string  $column
     */
    public function min($column): mixed
    {
        return $this->aggregateOver($column, 'min');
    }

    /**
     * @param  Expression|string  $column
     */
    public function max($column): mixed
    {
        return $this->aggregateOver($column, 'max');
    }

    /**
     * @param  Expression|string  $column
     */
    public function sum($column): mixed
    {
        return $this->aggregateOver($column, 'sum');
    }

    /**
     * @param  Expression|string  $column
     */
    public function avg($column): mixed
    {
        return $this->aggregateOver($column, 'avg');
    }

    /**
     * @param  Expression|string  $column
     */
    public function average($column): mixed
    {
        return $this->aggregateOver($column, 'avg');
    }

    private function createdAt(): string
    {
        $column = $this->model->getCreatedAtColumn() ?? 'created_at';

        return ($this->query->joins ?? []) !== [] && ($this->query->unions ?? []) === [] ? $this->qualifyColumn($column) : $column;
    }

    /**
     * @param  Expression|string  $column
     */
    private function aggregateOver(mixed $column, string $function): mixed
    {
        $attribute = is_string($column) ? $this->attributeOf($column) : null;

        if ($attribute !== null && $this->servesTheView() && $this->definition($attribute)->isExternal()) {
            throw $this->refuse($attribute, "{$function}()", 'it is stored outside the database, so no SQL reads it.');
        }

        return $this->toBase()->{$function}($column);
    }

    /**
     * @param  Closure(QueryBuilder, string): mixed  $predicate
     * @return $this
     */
    private function translate(string $attribute, bool $not, string $boolean, Closure $predicate): self
    {
        $map = $this->map();
        $owner = $map->owners[$attribute]->table;
        $key = $this->model->getKeyName();
        $negated = $this->negations % 2 === 1;
        $stored = $this->query->newQuery()->select($map->primary->table.'.'.$map->identifiers[$key]->column());

        if ($owner === $map->primary->table) {
            $stored->from($owner);
        } else {
            $stored->fromRaw(SqlFragments::identifier($map->primary->table).' '.implode(' ', ViewCompiler::laterals($map, $owner)));
        }

        $stored->where(fn (QueryBuilder $query) => $predicate($query, $owner.'.'.$this->definition($attribute)->column()), null, null, $not === $negated ? 'and' : 'and not');
        $this->query->whereIn($this->model->qualifyColumn($key), $stored, str_replace(' not', '', $boolean), $negated);

        return $this;
    }

    /**
     * @return array{string, mixed}
     */
    private function filter(string $attribute, string $operator, mixed $value, string $operation): array
    {
        $definition = $this->definition($attribute);

        if ($definition->isExternal()) {
            throw $this->refuse($attribute, $operation, 'it is stored outside the database, so no SQL reads it.');
        }

        if ($value instanceof Expression || $value instanceof Closure || $value instanceof Builder || $value instanceof QueryBuilder) {
            throw $this->refuse($attribute, $operation, 'a raw expression or a subquery does not pass through its conversions.');
        }

        return $definition->filter($operator, $value) ?? throw $this->refuse($attribute, $operation, 'its conversions give no stored value to compare with '.var_export($value instanceof BackedEnum ? $value->value : $value, true).'.');
    }

    /**
     * @param  array<array-key, mixed>  $column
     */
    private function touchesConversions(array $column): bool
    {
        foreach ($column as $key => $item) {
            $name = is_int($key) ? (is_array($item) ? reset($item) : null) : $key;

            if (is_string($name) && $this->converted($name) !== null) {
                return true;
            }
        }

        return false;
    }

    private function converted(string $column): null|string
    {
        $attribute = $this->attributeOf($column);

        if ($attribute === null || ! $this->servesTheView()) {
            return null;
        }

        $definition = $this->definition($attribute);

        return $definition->isExternal() || $definition->conversions() !== [] ? $attribute : null;
    }

    private function attributeOf(string $column): null|string
    {
        $table = $this->model->getTable();
        $attribute = str_contains($column, '.') ? (str_starts_with($column, $table.'.') ? substr($column, strlen($table) + 1) : null) : $column;

        return $attribute !== null && array_key_exists($attribute, $this->map()->attributes) ? $attribute : null;
    }

    private function definition(string $attribute): Attribute
    {
        return $this->map()->attributes[$attribute];
    }

    private function servesTheView(): bool
    {
        $connection = $this->model->getConnection();
        $relation = $connection->getName().'.'.$this->model->getTable();

        return self::$views[$relation] ??= $connection->scalar("select relkind::text from pg_class where oid = to_regclass(quote_ident(current_schema()) || '.' || ?)", [SqlFragments::identifier($this->model->getTable())]) === 'v';
    }

    private function map(): ResolvedMap
    {
        return self::$maps[$this->model::class] ??= Compatibility::of($this->model::class)->resolve();
    }

    private function refuse(string $attribute, string $operation, string $because): CompatibilityQueryRefused
    {
        return CompatibilityQueryRefused::untranslatable($this->model::class, $attribute, $operation, $because);
    }

    private static function negates(string $boolean): bool
    {
        return str_ends_with($boolean, ' not');
    }
}
