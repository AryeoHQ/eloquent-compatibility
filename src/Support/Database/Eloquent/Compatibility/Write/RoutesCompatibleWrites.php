<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Support\Database\Eloquent\Compatibility\Contracts\QueryableCast;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityQueryRefused;

/**
 * Hands every write of an Eloquent builder to the write router, when its model belongs to a registered compatibility
 * map. An application's own builder for a mapped model uses this trait; CompatibleBuilder is the default.
 *
 * It also translates queries on converted attributes. An attribute whose cast implements QueryableCast takes filter
 * values, sort orders, aggregates and increments in its converted form, and the stored form reaches SQL. An attribute
 * no cast can translate gets a translation from filterTranslations(), written with Eloquent calls.
 *
 * @phpstan-require-extends Builder
 */
trait RoutesCompatibleWrites
{
    private null|Model $compatibilitySource = null;

    /**
     * Translations of filters on attributes whose cast cannot convert a filter value. Each receives a nested builder,
     * the operator and the value, and adds the conditions that mean the same on the stored columns.
     *
     * @return array<string, Closure(static, string, mixed): mixed>
     */
    protected function filterTranslations(): array
    {
        return [];
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
        if (is_array($column) && array_any(array_keys($column), fn (int|string $key) => is_string($key) && $this->compatibilityAttribute($key) !== null)) {
            return parent::where(function (Builder $query) use ($column): void {
                foreach ($column as $key => $item) {
                    is_int($key) && is_array($item) ? $query->where(...array_values($item)) : $query->where($key, '=', $item);
                }
            }, null, null, $boolean);
        }

        $attribute = is_string($column) ? $this->compatibilityAttribute($column) : null;

        if ($attribute === null) {
            return parent::where(...func_get_args());
        }

        [$value, $operator] = func_num_args() === 2 ? [$operator, '='] : [$value, $operator];

        if (! $this->compatibilityIsOperator($operator)) {
            [$value, $operator] = [$operator, '='];
        }

        $translation = $this->filterTranslations()[$attribute] ?? null;

        if ($translation !== null) {
            return parent::where(fn (Builder $query) => $translation($query, strtolower((string) $operator), $value), null, null, $boolean);
        }

        if ($value instanceof Expression) {
            return parent::where($column, $operator, $value, $boolean);
        }

        if (! in_array(strtolower((string) $operator), ['=', '!=', '<>'], true)) {
            $this->compatibilityRequire($attribute, 'preservesOrder', "where {$operator}");
        }

        return parent::where($column, $operator, $this->compatibilityStored($attribute, $value), $boolean);
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
        $attribute = is_string($column) ? $this->compatibilityAttribute($column) : null;

        if ($attribute !== null && is_iterable($values) && ! $values instanceof Builder) {
            $values = [...$values];

            if (array_key_exists($attribute, $this->filterTranslations())) {
                return parent::where(function (Builder $query) use ($column, $values): void {
                    foreach ($values as $value) {
                        $query->orWhere($column, '=', $value);
                    }
                }, null, null, $boolean.($not ? ' not' : ''));
            }

            $values = array_map(fn (mixed $value) => $this->compatibilityStored($attribute, $value), $values);
        }

        $this->query->whereIn($column, $values, $boolean, $not);

        return $this;
    }

    /**
     * @return $this
     */
    public function whereNotIn($column, $values, $boolean = 'and')
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    /**
     * @return $this
     */
    public function orWhereIn($column, $values)
    {
        return $this->whereIn($column, $values, 'or');
    }

    /**
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
        $attribute = is_string($column) ? $this->compatibilityAttribute($column) : null;

        if ($attribute !== null) {
            $this->compatibilityRequire($attribute, 'preservesOrder', 'whereBetween');
            $values = array_map(fn (mixed $value) => $value instanceof Expression ? $value : $this->compatibilityStored($attribute, $value), [...$values]);
        }

        $this->query->whereBetween($column, $values, $boolean, $not);

        return $this;
    }

    /**
     * @return $this
     */
    public function whereNotBetween($column, iterable $values, $boolean = 'and')
    {
        return $this->whereBetween($column, $values, $boolean, true);
    }

    /**
     * @return $this
     */
    public function orWhereBetween($column, iterable $values)
    {
        return $this->whereBetween($column, $values, 'or');
    }

    /**
     * @return $this
     */
    public function orWhereNotBetween($column, iterable $values)
    {
        return $this->whereBetween($column, $values, 'or', true);
    }

    /**
     * @param  Closure|Expression|string  $column
     * @param  string  $direction
     * @return $this
     */
    public function orderBy($column, $direction = 'asc')
    {
        $attribute = is_string($column) ? $this->compatibilityAttribute($column) : null;

        if ($attribute !== null) {
            $this->compatibilityRequire($attribute, 'preservesOrder', 'orderBy');
        }

        $this->query->orderBy($column, $direction);

        return $this;
    }

    /**
     * @return $this
     */
    public function orderByDesc($column)
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * @param  Expression|string  $column
     */
    public function min($column): mixed
    {
        return $this->compatibilityAggregate($column, 'min', 'preservesOrder');
    }

    /**
     * @param  Expression|string  $column
     */
    public function max($column): mixed
    {
        return $this->compatibilityAggregate($column, 'max', 'preservesOrder');
    }

    /**
     * @param  Expression|string  $column
     */
    public function sum($column): mixed
    {
        return $this->compatibilityAggregate($column, 'sum', 'isProportional');
    }

    /**
     * @param  Expression|string  $column
     */
    public function avg($column): mixed
    {
        return $this->compatibilityAggregate($column, 'avg', 'isProportional');
    }

    /**
     * @param  Expression|string  $column
     */
    public function average($column): mixed
    {
        return $this->avg($column);
    }

    /**
     * @internal HasCompatibility marks the builder of a model's own update or delete
     */
    public function compatibilityWriteOf(Model $model): void
    {
        $this->compatibilitySource = $model;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function insert(array $values): bool
    {
        $router = $this->compatibilityRouter();

        if ($router === null) {
            return $this->toBase()->insert($values);
        }

        if ($values !== []) {
            $router->insert($this, $this->compatibilityRows($values), false);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  null|string  $sequence
     */
    public function insertGetId(array $values, $sequence = null): int|string
    {
        $router = $this->compatibilityRouter();

        if ($router === null) {
            return $this->toBase()->insertGetId($values, $sequence);
        }

        return $router->insert($this, [$values], false)[0];
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function insertOrIgnore(array $values): int
    {
        $router = $this->compatibilityRouter();

        if ($router === null) {
            return $this->toBase()->insertOrIgnore($values);
        }

        return $values === [] ? 0 : count($router->insert($this, $this->compatibilityRows($values), true));
    }

    /**
     * @param  array<int, string>  $columns
     * @param  mixed  $query
     */
    public function insertUsing(array $columns, $query): int
    {
        $this->compatibilityRouter()?->refuseSetInsert($this, 'insertUsing');

        return $this->toBase()->insertUsing($columns, $query);
    }

    /**
     * @param  array<int, string>  $columns
     * @param  mixed  $query
     */
    public function insertOrIgnoreUsing(array $columns, $query): int
    {
        $this->compatibilityRouter()?->refuseSetInsert($this, 'insertOrIgnoreUsing');

        return $this->toBase()->insertOrIgnoreUsing($columns, $query);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  null|array<array-key, mixed>  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        $this->compatibilityRouter()?->refuseSetInsert($this, 'upsert');

        return parent::upsert($values, $uniqueBy, $update);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        $router = $this->compatibilityRouter();

        if ($router === null) {
            return parent::update($values);
        }

        return $router->update($this, $this->addUpdatedAtColumn($values), $this->compatibilitySource);
    }

    /**
     * @param  null|array<int, string>|string  $column
     */
    public function touch($column = null): false|int
    {
        $router = $this->compatibilityRouter();

        if ($router === null) {
            return parent::touch($column);
        }

        $time = $this->model->freshTimestamp();

        if ($column === null) {
            $column = $this->model->getUpdatedAtColumn();

            if (! $this->model->usesTimestamps() || $column === null) {
                return false;
            }
        }

        $columns = [];

        foreach (Arr::wrap($column) as $name) {
            $columns[(string) $name] = $this->model->fromDateTime($time);
        }

        return $router->update($this, $columns, $this->compatibilitySource);
    }

    /**
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = []): int
    {
        return $this->incrementEach([(string) $column => $amount], $extra);
    }

    /**
     * @param  string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = []): int
    {
        return $this->decrementEach([(string) $column => $amount], $extra);
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = []): int
    {
        foreach ($columns as $column => $amount) {
            $attribute = $this->compatibilityAttribute((string) $column);

            if ($attribute !== null) {
                $this->compatibilityRequire($attribute, 'isProportional', 'increment');
                $stored = $this->compatibilityStored($attribute, $amount);
                $columns[$column] = is_int($stored) || is_float($stored) ? $stored : (float) $stored;
            }
        }

        $router = $this->compatibilityRouter();

        if ($router === null) {
            return $this->toBase()->incrementEach($columns, $this->addUpdatedAtColumn($extra));
        }

        return $router->increment($this, $columns, $this->addUpdatedAtColumn($extra), $this->compatibilitySource);
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = []): int
    {
        return $this->incrementEach(array_map(fn (float|int $amount) => -$amount, $columns), $extra);
    }

    /**
     * @return mixed
     */
    public function delete()
    {
        if (isset($this->onDelete)) {
            return call_user_func($this->onDelete, $this);
        }

        $router = $this->compatibilityRouter();

        return $router === null ? $this->toBase()->delete() : $router->delete($this, $this->compatibilitySource);
    }

    /**
     * @return mixed
     */
    public function forceDelete()
    {
        $router = $this->compatibilityRouter();

        return $router === null ? $this->query->delete() : $router->delete($this, $this->compatibilitySource);
    }

    /**
     * The attribute a column names, when its filters translate: a QueryableCast attribute, or one with a filter translation.
     */
    private function compatibilityAttribute(string $column): null|string
    {
        $table = $this->model->getTable();
        $attribute = str_contains($column, '.') ? (str_starts_with($column, $table.'.') ? substr($column, strlen($table) + 1) : null) : $column;

        if ($attribute === null) {
            return null;
        }

        return array_key_exists($attribute, $this->filterTranslations()) || $this->compatibilityCast($attribute) !== null ? $attribute : null;
    }

    private function compatibilityCast(string $attribute): null|QueryableCast
    {
        $cast = $this->model->getCasts()[$attribute] ?? null;
        $class = is_string($cast) ? explode(':', $cast, 2)[0] : null;

        return $class !== null && is_subclass_of($class, QueryableCast::class) ? new $class : null;
    }

    private function compatibilityRequire(string $attribute, string $ability, string $operation): void
    {
        if (array_key_exists($attribute, $this->filterTranslations()) && $this->compatibilityCast($attribute) === null) {
            throw CompatibilityQueryRefused::untranslatable($this->model::class, $attribute, $operation, 'its filter translation covers filters only, not ordering, ranges or arithmetic.');
        }

        $cast = $this->compatibilityCast($attribute);

        if ($cast !== null && ! $cast->{$ability}()) {
            $because = $ability === 'preservesOrder'
                ? 'its cast does not preserve order, so the stored values sort differently.'
                : 'its cast is not proportional, so the stored values do not add up the same way.';

            throw CompatibilityQueryRefused::untranslatable($this->model::class, $attribute, $operation, $because);
        }
    }

    /**
     * The value as the cast stores it.
     */
    private function compatibilityStored(string $attribute, mixed $value): mixed
    {
        if ($value === null || $value instanceof Expression) {
            return $value;
        }

        return $this->model->newInstance()->forceFill([$attribute => $value])->getAttributes()[$attribute] ?? null;
    }

    /**
     * @param  Expression|string  $column
     */
    private function compatibilityAggregate(mixed $column, string $function, string $ability): mixed
    {
        $attribute = is_string($column) ? $this->compatibilityAttribute($column) : null;

        if ($attribute === null) {
            return $this->toBase()->{$function}($column);
        }

        $this->compatibilityRequire($attribute, $ability, "{$function}()");
        $stored = $this->toBase()->{$function}($column);

        return $stored === null ? null : $this->model->newInstance()->setRawAttributes([$attribute => $stored])->getAttribute($attribute);
    }

    private function compatibilityIsOperator(mixed $operator): bool
    {
        return is_string($operator) && in_array(strtolower($operator), [...$this->query->operators, ...$this->query->getGrammar()->getOperators()], true);
    }

    private function compatibilityRouter(): null|WriteRouter
    {
        $container = Container::getInstance();

        if (! $container->resolved(WriteRouter::class)) {
            return null;
        }

        $router = $container->make(WriteRouter::class);

        return $router->routes($this) ? $router : null;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<array<string, mixed>>
     */
    private function compatibilityRows(array $values): array
    {
        /** @var list<array<string, mixed>> */
        return is_array(reset($values)) ? array_values($values) : [$values];
    }
}
