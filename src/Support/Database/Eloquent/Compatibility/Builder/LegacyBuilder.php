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
use Illuminate\Support\Str;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\Related;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityQueryRefused;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
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

    /** @var array<string, true> */
    private static array $tables = [];

    private int $negations = 0;

    private null|Model $writeOf = null;

    /** @var null|array{schema: string, view: bool, readable: bool} */
    private null|array $relation = null;

    public static function forgetViews(): void
    {
        self::$catalogs = [];
        self::$tables = [];
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

    public static function serves(Model $model): bool
    {
        return $model->getConnection()->scalar("select relkind::text from pg_class where oid = to_regclass(quote_ident(current_schema()) || '.' || ?)", [SqlFragments::identifier($model->getTable())], false) === 'v';
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
        $model = $this->ownInsert();

        if ($model === null) {
            return $this->toBase()->insert($values);
        }

        $this->writer()->insert($model, $values);

        return true;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  null|string  $sequence
     */
    public function insertGetId(array $values, $sequence = null): int|string
    {
        $model = $this->ownInsert();

        return $model === null ? $this->toBase()->insertGetId($values, $sequence) : $this->writer()->insert($model, $values);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        $model = $this->ownWrite();

        return $model === null ? parent::update($values) : $this->writer()->update($model, $values);
    }

    public function delete(): mixed
    {
        $model = $this->ownWrite();

        return $model === null ? parent::delete() : $this->writer()->delete($model);
    }

    public function forceDelete(): mixed
    {
        $model = $this->ownWrite();

        return $model === null ? parent::forceDelete() : $this->writer()->delete($model);
    }

    /**
     * @param  Expression|string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = []): int
    {
        $model = $this->ownWrite();

        return $model === null || ! is_string($column) ? parent::increment($column, $amount, $extra) : $this->incrementOwn($model, [$column => $amount], $extra);
    }

    /**
     * @param  Expression|string  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = []): int
    {
        $model = $this->ownWrite();

        return $model === null || ! is_string($column) ? parent::decrement($column, $amount, $extra) : $this->incrementOwn($model, [$column => $amount * -1], $extra);
    }

    /**
     * @param  array<string, float|int|numeric-string>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = []): int
    {
        $model = $this->ownWrite();

        return $model === null ? $this->toBase()->incrementEach($columns, $this->addUpdatedAtColumn($extra)) : $this->incrementOwn($model, $columns, $extra);
    }

    /**
     * @param  array<string, float|int|numeric-string>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = []): int
    {
        $model = $this->ownWrite();

        return $model === null ? $this->toBase()->decrementEach($columns, $this->addUpdatedAtColumn($extra)) : $this->incrementOwn($model, array_map(fn (float|int|string $amount) => $amount * -1, $columns), $extra);
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
                $nested->relation = $this->relation;
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
        $catalog = self::$catalogs[$connection->getName().'.'.$this->relation()['schema']] ??= new ColumnCatalog($connection);
        $definition = $this->definition($attribute);
        $column = $definition->column();
        $stored = $column === null ? null : ($catalog->of($owner)[$column] ?? null);
        $type = $stored === null ? null : $stored->baseType ?? $stored->type;
        $served = $catalog->of($this->map()->table)[$attribute] ?? null;

        if ($served === null || $definition->exact($type, $served->baseType ?? $served->type)) {
            return [$type, null];
        }

        return [$type, ViewCompiler::cast($definition->read($owner, $type), $definition->type($type), $served->type, $served->baseType)];
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

    private function ownInsert(): null|Model
    {
        return self::takeInsertOf($this->model) && $this->servesTheView() ? $this->model : null;
    }

    /**
     * @param  array<string, float|int|numeric-string>  $amounts
     * @param  array<string, mixed>  $extra
     */
    private function incrementOwn(Model $model, array $amounts, array $extra): int
    {
        $values = [];

        foreach ($this->addUpdatedAtColumn($extra) as $name => $value) {
            $values[Str::afterLast((string) $name, '.')] = $value;
        }

        return $this->writer()->increment($model, $amounts, $values);
    }

    private function ownWrite(): null|Model
    {
        return $this->writeOf !== null && $this->servesTheView() ? $this->writeOf : null;
    }

    private function writer(): RecordWriter
    {
        return new RecordWriter($this->map(), $this->model->getConnection());
    }

    private function servesTheView(): bool
    {
        return self::serves($this->model);
    }

    /**
     * @return array{schema: string, view: bool, readable: bool}
     */
    private function relation(): array
    {
        $connection = $this->model->getConnection();
        $table = $connection->getName().'.'.$this->model->getTable();

        if ($this->relation !== null || array_key_exists($table, self::$tables)) {
            return $this->relation ?? ['schema' => '', 'view' => false, 'readable' => true];
        }

        $map = $this->map();
        $row = (array) $connection->selectOne(
            "select current_schema() as schema,
                    coalesce((select relkind = 'v' from pg_class where oid = to_regclass(quote_ident(current_schema()) || '.' || ?)), false) as view,
                    coalesce((select bool_and(coalesce(has_table_privilege(successor_table, 'SELECT') and not row_security_active(successor_table), false)) from jsonb_array_elements_text(cast(? as jsonb)) as successor cross join lateral to_regclass(quote_ident(current_schema()) || '.' || quote_ident(successor)) as successor_table), false) as readable",
            [SqlFragments::identifier($this->model->getTable()), json_encode([$map->primary->table, ...array_map(fn (Related $related) => $related->table, $map->related)], JSON_THROW_ON_ERROR)],
        );

        $this->relation = ['schema' => (string) ($row['schema'] ?? ''), 'view' => ($row['view'] ?? null) === true, 'readable' => ($row['readable'] ?? null) === true];

        if (! $this->relation['view']) {
            self::$tables[$table] = true;
        }

        return $this->relation;
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
