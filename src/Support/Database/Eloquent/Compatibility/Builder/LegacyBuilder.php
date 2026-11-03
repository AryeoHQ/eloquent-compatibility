<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Builder;

use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression as RawExpression;
use Illuminate\Support\Arr;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Related;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityQueryRefused;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\ServedTable;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;
use Support\Database\Eloquent\Compatibility\Write\ExternalAttributes;
use Support\Database\Eloquent\Compatibility\Write\RecordWriter;
use WeakMap;

/**
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
final class LegacyBuilder extends Builder
{
    private const array COMPLEMENTS = ['=' => '!=', '!=' => '=', '<>' => '=', '<' => '>=', '>' => '<=', '<=' => '>', '>=' => '<', 'unknown' => 'unknown'];

    /** @var array<string, ResolvedMap> */
    private static array $maps = [];

    /** @var array<string, ColumnCatalog> */
    private static array $catalogs = [];

    /** @var null|WeakMap<Model, true> */
    private static null|WeakMap $inserts = null;

    /** @var null|WeakMap<Model, true> */
    private static null|WeakMap $deletes = null;

    /** @var null|WeakMap<Model, true> */
    private static null|WeakMap $restores = null;

    /** @var array<string, array<string, array{name: string, deterministic: bool, declared: bool}>> */
    private static array $collations = [];

    private int $negations = 0;

    private null|Model $writeOf = null;

    public static function forgetViews(): void
    {
        self::$catalogs = [];
        ServedTable::forget();
        self::$collations = [];
    }

    public static function insertOf(Model $model): void
    {
        $inserts = self::$inserts ??= new WeakMap;
        $inserts[$model] = true;
    }

    public static function takeInsertOf(Model $model): bool
    {
        $inserts = self::$inserts ??= new WeakMap;
        $inserting = $inserts->offsetExists($model);
        $inserts->offsetUnset($model);

        return $inserting;
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $delete
     * @return TResult
     */
    public static function deleting(Model $model, Closure $delete): mixed
    {
        $deletes = self::$deletes ??= new WeakMap;
        $deletes[$model] = true;

        try {
            return $delete();
        } finally {
            $deletes->offsetUnset($model);
        }
    }

    public static function restoreOf(Model $model): void
    {
        $restores = self::$restores ??= new WeakMap;
        $restores[$model] = true;
    }

    public static function forgetRestoreOf(Model $model): void
    {
        self::$restores?->offsetUnset($model);
    }

    public static function serves(Model $model): bool
    {
        return ServedTable::serves($model);
    }

    /**
     * @param  class-string<Model>  $model
     */
    public static function mapOf(string $model): ResolvedMap
    {
        return self::$maps[$model] ??= Compatibility::of($model)->resolve();
    }

    public static function refuseExternal(string $model, string $attribute, string $operation): CompatibilityQueryRefused
    {
        return CompatibilityQueryRefused::untranslatable($model, $attribute, $operation, 'it is stored outside the database, so no SQL reads it.');
    }

    public function writeOf(Model $model): void
    {
        $this->writeOf = $model;
    }

    /**
     * @param  array<array-key, mixed>  $items
     */
    public function hydrate(array $items)
    {
        $models = parent::hydrate($items);
        ExternalAttributes::gather($models->all(), $this->query->useWritePdo);

        return $models;
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     * @return TModel
     */
    public function newModelInstance($attributes = [])
    {
        $model = parent::newModelInstance($attributes);
        ExternalAttributes::readFrom($model, $this->query->useWritePdo);

        return $model;
    }

    /**
     * @return LazyCollection<int, TModel>
     */
    public function cursor()
    {
        $scoped = $this->applyScopes();

        return $scoped === $this ? parent::cursor() : $scoped->withoutGlobalScopes()->cursor();
    }

    /**
     * @param  array<int, Expression|string>|Expression|string  $columns
     * @return list<TModel>
     */
    public function getModels($columns = ['*'])
    {
        return array_values($this->hydrate($this->query->get($columns)->all())->all());
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function insert(array $values): bool
    {
        if (! self::takeInsertOf($this->model)) {
            return $this->toBase()->insert($values);
        }

        return ServedTable::write($this->model, fn (): bool => $this->natively(fn (): bool => $this->toBase()->insert($values)), function () use ($values): bool {
            $this->writer()->insert($this->model, $values);

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  null|string  $sequence
     */
    public function insertGetId(array $values, $sequence = null): int|string
    {
        if (! self::takeInsertOf($this->model)) {
            return $this->toBase()->insertGetId($values, $sequence);
        }

        return ServedTable::write($this->model, fn () => $this->natively(fn () => $this->toBase()->insertGetId($values, $sequence)), fn (): int|string => $this->writer()->insert($this->model, $values));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        $model = $this->writeOf;

        if ($model === null) {
            return parent::update($values);
        }

        $deleting = self::$deletes?->offsetExists($model) ?? false;
        $restoring = self::$restores?->offsetExists($model) ?? false;
        self::forgetRestoreOf($model);

        return $this->own(fn (): int => parent::update($values), fn (): int => $this->writer()->update($model, $values, $deleting, $restoring));
    }

    public function delete(): mixed
    {
        $model = $this->writeOf;

        return $model === null ? parent::delete() : $this->own(fn (): mixed => parent::delete(), fn (): int => $this->writer()->delete($model));
    }

    public function forceDelete(): mixed
    {
        $model = $this->writeOf;

        return $model === null ? parent::forceDelete() : $this->own(fn (): mixed => parent::forceDelete(), fn (): int => $this->writer()->delete($model));
    }

    /**
     * @param  Expression|string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = []): int
    {
        $model = $this->writeOf;

        return $model === null || ! is_string($column) ? parent::increment($column, $amount, $extra) : $this->own(fn (): int => parent::increment($column, $amount, $extra), fn (): int => $this->incrementOwn($model, [$column => $amount], $extra));
    }

    /**
     * @param  Expression|string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = []): int
    {
        $model = $this->writeOf;

        return $model === null || ! is_string($column) ? parent::decrement($column, $amount, $extra) : $this->own(fn (): int => parent::decrement($column, $amount, $extra), fn (): int => $this->incrementOwn($model, [$column => $amount * -1], $extra));
    }

    /**
     * @param  array<string, float|int|numeric-string>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = []): int
    {
        $model = $this->writeOf;

        return $model === null ? $this->toBase()->incrementEach($columns, $this->addUpdatedAtColumn($extra)) : $this->own(fn (): int => $this->toBase()->incrementEach($columns, $this->addUpdatedAtColumn($extra)), fn (): int => $this->incrementOwn($model, $columns, $extra));
    }

    /**
     * @param  array<string, float|int|numeric-string>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = []): int
    {
        $model = $this->writeOf;

        return $model === null ? $this->toBase()->decrementEach($columns, $this->addUpdatedAtColumn($extra)) : $this->own(fn (): int => $this->toBase()->decrementEach($columns, $this->addUpdatedAtColumn($extra)), fn (): int => $this->incrementOwn($model, array_map(fn (float|int|string $amount) => $amount * -1, $columns), $extra));
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
                    is_int($key) && is_array($item) ? $query->where(...array_values($item), boolean: $boolean) : $query->where((string) $key, '=', $item, $boolean);
                }
            }, null, null, $boolean);

            return $this;
        }

        $attribute = is_string($column) ? $this->converted($column) : null;
        $arguments = func_get_args();

        if ($attribute === null) {
            parent::where(...$arguments);

            return $this;
        }

        [$value, $operator] = func_num_args() === 2 ? [$operator, '='] : [$value, $operator];

        if (! is_string($operator) || ! in_array(strtolower($operator), [...$this->query->operators, ...$this->query->getGrammar()->getOperators()], true)) {
            [$value, $operator] = [$operator, '='];
        }

        $operator = strtolower($operator);

        return $this->translate($attribute, self::negates($boolean), $boolean, "where {$operator}", [[$operator, $value]], 'and', fn () => parent::where(...$arguments));
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

        return $this->translate($attribute, $not !== self::negates($boolean), $boolean, $operation, array_map(fn (mixed $value) => [$value === null ? 'unknown' : '=', $value], array_values($values)), 'or', fn () => $this->query->whereIn($column, $values, $boolean, $not));
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

        return $this->translate($attribute, $not !== self::negates($boolean), $boolean, $operation, [['>=', $low], ['<=', $high]], 'and', fn () => $this->query->whereBetween($column, $values, $boolean, $not));
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

            $this->translate($attribute, self::negates($boolean), $boolean, $not ? 'whereNotNull' : 'whereNull', [[$not ? '!=' : '=', null]], 'and', fn () => $this->query->whereNull($column, $boolean, $not));
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

        if ($attribute !== null && $this->map()->isExternal($attribute)) {
            $orders = $this->query->unions ? $this->query->unionOrders : $this->query->orders;

            throw self::refuseExternal($this->model::class, $attribute, 'orderBy '.end($orders)['direction']);
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

        if ($attribute !== null && $this->map()->isExternal($attribute) && $this->relation()['view']) {
            throw self::refuseExternal($this->model::class, $attribute, "{$function}()");
        }

        return $this->toBase()->{$function}($column);
    }

    /**
     * @param  list<array{string, mixed}>  $conditions
     * @param  Closure(): mixed  $onView
     * @return $this
     */
    private function translate(string $attribute, bool $not, string $boolean, string $operation, array $conditions, string $combine, Closure $onView): self
    {
        $map = $this->map();
        $definition = $this->definition($attribute);

        if ($map->isExternal($attribute)) {
            throw self::refuseExternal($this->model::class, $attribute, $operation);
        }

        foreach ($conditions as [, $value]) {
            if ($value instanceof Expression || $value instanceof Closure || $value instanceof Builder || $value instanceof QueryBuilder) {
                throw $this->refuse($attribute, $operation, 'a raw expression or a subquery does not pass through its conversions.');
            }
        }

        if ($map->branches !== []) {
            $onView();

            return $this;
        }

        $conditions = array_map(fn (array $condition) => [$condition[0], $this->sent($condition[1])], $conditions);

        foreach ($conditions as [$operator, $value]) {
            if ($value === null && in_array($operator, ['<', '>', '<=', '>='], true)) {
                $onView();

                return $this;
            }
        }

        $owner = $map->owners[$attribute]->table;
        $key = $this->model->getKeyName();
        $negated = $this->negations % 2 === 1;
        $negate = $not !== $negated;
        [$type, $served] = $this->served($attribute, $owner);
        $stored = $served === null ? $this->stored($attribute, $conditions, $combine, $negate, $type) : [$conditions, $combine, $negate];

        if ($stored === null || ! $this->relation()['readable']) {
            $onView();

            return $this;
        }

        [$filters, $combine, $negate] = $stored;
        $column = $served === null ? $owner.'.'.$definition->column() : new RawExpression($served);
        $successors = $this->query->newQuery()->select($map->primary->table.'.'.$map->identifiers[$key]->column());

        if ($owner === $map->primary->table) {
            $successors->from($owner);
        } else {
            $successors->fromRaw(SqlFragments::identifier($map->primary->table).' '.implode(' ', ViewCompiler::laterals($map, $owner)));
        }

        if ($map->membership !== null) {
            $successors->whereRaw($map->membership->of($map->primary->table));
        }

        $successors->where(fn (QueryBuilder $query) => self::apply($query, $column, $filters, $combine), null, null, $negate ? 'and not' : 'and');
        $this->query->whereIn($this->model->qualifyColumn($key), $successors, str_replace(' not', '', $boolean), $negated);

        return $this;
    }

    private function sent(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format($this->model->getConnection()->getQueryGrammar()->getDateFormat()),
            is_float($value) => (string) $value,
            default => $value,
        };
    }

    /**
     * @param  list<array{string, mixed}>  $conditions
     * @return null|array{list<array{string, mixed}>, string, bool}
     */
    private function stored(string $attribute, array $conditions, string $combine, bool $negate, null|string $type): null|array
    {
        if ($negate) {
            $complements = $this->filters($attribute, array_map(fn (array $condition) => [self::COMPLEMENTS[$condition[0]] ?? '', $condition[1]], $conditions), $type);

            if ($complements !== null) {
                return [$complements, $combine === 'and' ? 'or' : 'and', false];
            }
        }

        $filters = $this->filters($attribute, $conditions, $type);

        return $filters === null ? null : [$filters, $combine, $negate];
    }

    /**
     * @param  list<array{string, mixed}>  $conditions
     * @return null|list<array{string, mixed}>
     */
    private function filters(string $attribute, array $conditions, null|string $type): null|array
    {
        $filters = [];

        foreach ($conditions as [$operator, $value]) {
            $filter = match ($operator) {
                '' => null,
                'unknown' => ['unknown', null],
                default => $this->definition($attribute)->filter($operator, $value, $type),
            };

            if ($filter === null) {
                return null;
            }

            $filters[] = $filter;
        }

        return $filters;
    }

    /**
     * @param  list<array{string, mixed}>  $filters
     */
    private static function apply(QueryBuilder $query, Expression|string $column, array $filters, string $combine): void
    {
        if ($combine === 'or' && count($filters) > 1 && array_unique(array_column($filters, 0)) === ['=']) {
            $query->whereIn($column, array_column($filters, 1));

            return;
        }

        foreach ($filters as [$operator, $value]) {
            match ($operator) {
                'none' => $query->whereRaw('false', [], $combine),
                'all' => $query->whereRaw('true', [], $combine),
                'unknown' => $query->whereRaw('null', [], $combine),
                'in' => $query->whereIn($column, is_array($value) ? $value : [$value], $combine),
                default => $query->where($column, $operator, $value, $combine),
            };
        }
    }

    /**
     * @return array{null|string, null|string}
     */
    private function served(string $attribute, string $owner): array
    {
        $connection = $this->model->getConnection();
        $catalog = self::$catalogs[$connection->getName().'.'.$this->relation()['path']] ??= new ColumnCatalog($connection, throughSearchPath: true);
        $definition = $this->definition($attribute);
        $column = $definition->column();
        $stored = $column === null ? null : ($catalog->of($owner)[$column] ?? null);
        $type = $stored === null ? null : $stored->baseType ?? $stored->type;
        $served = $catalog->of($this->map()->table)[$attribute] ?? null;
        $collation = $this->collations($this->map()->table)[$attribute] ?? null;

        if ($served === null || ($definition->exact($type, $served->baseType ?? $served->type) && ($this->collations($owner)[(string) $column]['deterministic'] ?? true) && ($collation['deterministic'] ?? true))) {
            return [$type, null];
        }

        return [$type, ViewCompiler::cast($definition->read($owner, $type, $catalog->baseType(...)), $catalog->baseType($definition->type($type)), $served->type, $served->baseType, $collation['name'] ?? null)];
    }

    /**
     * @return array<string, array{name: string, deterministic: bool, declared: bool}>
     */
    private function collations(string $table): array
    {
        $connection = $this->model->getConnection();

        return self::$collations[$connection->getName().'.'.$this->relation()['path'].'.'.$table] ??= ViewCompiler::collations($connection, $table, throughSearchPath: true);
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
        $definition = $attribute === null ? null : $this->definition($attribute);

        return $definition !== null && ($this->map()->isExternal((string) $attribute) || $definition->conversions() !== []) && $this->relation()['view'] ? $attribute : null;
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

    /**
     * @param  array<string, float|int|numeric-string>  $amounts
     * @param  array<string, mixed>  $extra
     */
    private function incrementOwn(Model $model, array $amounts, array $extra): int
    {
        $named = [];
        $values = [];

        foreach ($amounts as $name => $amount) {
            $named[Str::afterLast((string) $name, '.')] = $amount;
        }

        foreach ($this->addUpdatedAtColumn($extra) as $name => $value) {
            $values[Str::afterLast((string) $name, '.')] = $value;
        }

        return $this->writer()->increment($model, $named, $values);
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $native
     * @param  Closure(): TResult  $translated
     * @return TResult
     */
    private function own(Closure $native, Closure $translated): mixed
    {
        return ServedTable::write($this->model, fn (): mixed => $this->natively($native), $translated);
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $write
     * @return TResult
     */
    private function natively(Closure $write): mixed
    {
        return $this->query instanceof LegacyQueryBuilder ? $this->query->natively($write) : $write();
    }

    private function writer(): RecordWriter
    {
        return new RecordWriter($this->map(), $this->model->getConnection());
    }

    /**
     * @return array{path: string, view: bool, readable: bool}
     */
    private function relation(): array
    {
        $map = $this->map();

        return ServedTable::reading($this->model, [$map->primary->table, ...array_map(fn (Related $related) => $related->table, array_values($map->related))]);
    }

    private function map(): ResolvedMap
    {
        return self::mapOf($this->model::class);
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
