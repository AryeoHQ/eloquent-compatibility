<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Builder;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Schema\ServedTable;
use Support\Database\Eloquent\Compatibility\Write\ExternalAttributes;
use Support\Database\Eloquent\Compatibility\Write\MassWriter;
use Support\Database\Eloquent\Compatibility\Write\RecordWriter;

final class LegacyQueryBuilder extends QueryBuilder
{
    private bool $native = false;

    public function __construct(ConnectionInterface $connection, null|Grammar $grammar = null, null|Processor $processor = null, private readonly null|Model $legacy = null)
    {
        parent::__construct($connection, $grammar, $processor);
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $write
     * @return TResult
     */
    public function natively(Closure $write): mixed
    {
        $this->native = true;

        try {
            return $write();
        } finally {
            $this->native = false;
        }
    }

    public function toSql(): string
    {
        $this->applyBeforeQueryCallbacks();
        $this->refuseExternal();

        return parent::toSql();
    }

    public function exists(): bool
    {
        $this->applyBeforeQueryCallbacks();
        $this->refuseExternal();

        return parent::exists();
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function insert(array $values): bool
    {
        if ($values === []) {
            return parent::insert($values);
        }

        return $this->route(fn (): bool => parent::insert($values), function (Model $legacy) use ($values): bool {
            $legacy->getConnection()->transaction(function () use ($legacy, $values): void {
                foreach (self::rows($values) as $row) {
                    $this->writer($legacy)->insert($legacy->newInstance(), $row);
                }
            });

            return true;
        });
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  null|string  $sequence
     */
    public function insertGetId(array $values, $sequence = null): int|string // @phpstan-ignore method.childReturnType
    {
        return $this->route(fn () => parent::insertGetId($values, $sequence), function (Model $legacy) use ($values, $sequence): int|string {
            $returning = $sequence === null || $sequence === '' ? $legacy->getKeyName() : $sequence;

            return $legacy->getConnection()->transaction(function () use ($legacy, $values, $returning): int|string {
                $key = $this->writer($legacy)->insert($legacy->newInstance(), $values);
                $id = $returning === $legacy->getKeyName() ? $key : $this->newQuery()->from($legacy->getTable())->where($legacy->getKeyName(), $key)->value($returning);

                return is_numeric($id) ? (int) $id : (is_string($id) ? $id : throw new LogicException("{$legacy->getTable()}.{$returning} read back no value for the new ".class_basename($legacy).'.'));
            });
        });
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function insertOrIgnore(array $values): int
    {
        if ($values === []) {
            return parent::insertOrIgnore($values);
        }

        return $this->route(fn (): int => parent::insertOrIgnore($values), fn (Model $legacy): int => $legacy->getConnection()->transaction(function () use ($legacy, $values): int {
            $inserted = 0;

            foreach (self::rows($values) as $row) {
                $inserted += $this->insertUnlessTaken($legacy, $legacy->newInstance(), $row) === null ? 0 : 1;
            }

            return $inserted;
        }));
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, string>  $returning
     * @param  null|string|array<array-key, string>  $uniqueBy
     * @return Collection<int, \stdClass>
     */
    public function insertOrIgnoreReturning(array $values, array $returning = ['*'], array|string|null $uniqueBy = null): Collection
    {
        if ($values === []) {
            return parent::insertOrIgnoreReturning($values, $returning, $uniqueBy);
        }

        return $this->route(fn (): Collection => parent::insertOrIgnoreReturning($values, $returning, $uniqueBy), function (Model $legacy) use ($values, $returning, $uniqueBy): Collection {
            if ($uniqueBy !== null) {
                throw $this->unsupported('insertOrIgnoreReturning() with unique columns', 'Its unique columns name legacy columns, and the unique indexes live on the successor tables.');
            }

            $own = LegacyBuilder::takeInsertOf($legacy);

            return $legacy->getConnection()->transaction(function () use ($legacy, $values, $returning, $own): Collection {
                $keys = [];

                foreach (self::rows($values) as $row) {
                    $key = $this->insertUnlessTaken($legacy, $own ? $legacy : $legacy->newInstance(), $row);

                    if ($key !== null) {
                        $keys[] = $key;
                    }
                }

                /** @var Collection<int, \stdClass> $rows */
                $rows = $keys === [] ? new Collection : $this->newQuery()->from($legacy->getTable())->whereIn($legacy->getKeyName(), $keys)->get($returning);

                return $rows;
            });
        });
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function update(array $values): int
    {
        return $this->route(fn (): int => parent::update($values), fn (Model $legacy): int => $this->massWriter($legacy)->update($this->keys($legacy), $values, alias: $this->alias($legacy)));
    }

    /**
     * @param  array<array-key, mixed>  $columns
     * @param  array<array-key, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = []): int
    {
        return $this->route(fn (): int => parent::incrementEach($columns, $extra), fn (Model $legacy): int => $this->massWriter($legacy)->update($this->keys($legacy), $extra, self::amounts($columns, 1), $this->alias($legacy)));
    }

    /**
     * @param  array<array-key, mixed>  $columns
     * @param  array<array-key, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = []): int
    {
        return $this->route(fn (): int => parent::decrementEach($columns, $extra), fn (Model $legacy): int => $this->massWriter($legacy)->update($this->keys($legacy), $extra, self::amounts($columns, -1), $this->alias($legacy)));
    }

    /**
     * @param  mixed  $id
     */
    public function delete($id = null): int
    {
        return $this->route(fn (): int => parent::delete($id), function (Model $legacy) use ($id): int {
            if ($id !== null) {
                $this->where($this->alias($legacy).'.id', '=', $id);
            }

            return $this->massWriter($legacy)->delete($this->keys($legacy));
        });
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  string|array<array-key, string>  $uniqueBy
     * @param  null|array<array-key, mixed>  $update
     */
    public function upsert(array $values, array|string $uniqueBy, null|array $update = null): int
    {
        return $this->route(fn (): int => parent::upsert($values, $uniqueBy, $update), fn (): never => throw $this->unsupported('upsert()', 'Its unique columns name legacy columns, and the unique indexes live on the successor tables.'));
    }

    /**
     * @param  array<array-key, mixed>  $columns
     * @param  mixed  $query
     */
    public function insertUsing(array $columns, $query): int
    {
        return $this->route(fn (): int => parent::insertUsing($columns, $query), fn (): never => throw $this->unsupported('insertUsing()', 'A subquery yields its rows inside the database, and each legacy row must be split across the successor tables.'));
    }

    /**
     * @param  array<array-key, mixed>  $columns
     * @param  mixed  $query
     */
    public function insertOrIgnoreUsing(array $columns, $query): int
    {
        return $this->route(fn (): int => parent::insertOrIgnoreUsing($columns, $query), fn (): never => throw $this->unsupported('insertOrIgnoreUsing()', 'A subquery yields its rows inside the database, and each legacy row must be split across the successor tables.'));
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function updateFrom(array $values): int
    {
        return $this->route(fn (): int => parent::updateFrom($values), fn (): never => throw $this->unsupported('updateFrom()', 'Its joined tables are read by the legacy table name, which is a view.'));
    }

    public function truncate(): void
    {
        $this->route(fn () => parent::truncate(), fn (): never => throw $this->unsupported('truncate()', 'It would empty the successor tables, which serve more than this legacy model.'));
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<array<string, mixed>>
     */
    private static function rows(array $values): array
    {
        $rows = [];

        foreach (is_array(reset($values)) ? $values : [$values] as $row) {
            $rows[] = array_combine(array_map(fn (int|string $name) => (string) $name, array_keys((array) $row)), array_values((array) $row));
        }

        return $rows;
    }

    /**
     * @param  array<array-key, mixed>  $columns
     * @return array<string, float|int>
     */
    private static function amounts(array $columns, int $sign): array
    {
        $amounts = [];

        foreach ($columns as $column => $amount) {
            if (! is_numeric($amount)) {
                throw new InvalidArgumentException("Non-numeric value passed as increment amount for column: '{$column}'.");
            }

            if (! is_string($column)) {
                throw new InvalidArgumentException('Non-associative array passed to incrementEach method.');
            }

            $amounts[$column] = ($amount + 0) * $sign;
        }

        return $amounts;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insertUnlessTaken(Model $legacy, Model $inserting, array $row): null|int|string
    {
        try {
            return $this->writer($legacy)->insert($inserting, $row);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function keys(Model $legacy): QueryBuilder
    {
        $limited = $this->limit !== null || $this->offset !== null;
        $keys = $this->cloneWithout($limited ? ['columns', 'aggregate', 'lock'] : ['columns', 'aggregate', 'lock', 'orders'])
            ->cloneWithoutBindings($limited ? ['select'] : ['select', 'order']);

        return $keys->select($this->alias($legacy).'.'.$legacy->getKeyName());
    }

    private function refuseExternal(): void
    {
        $legacy = $this->legacy;
        $alias = $legacy === null ? null : $this->alias($legacy);

        if ($legacy === null || $alias === null) {
            return;
        }

        $externals = ExternalAttributes::of($legacy::class);
        $reading = $externals === [] ? null : $this->reading($alias, $externals);

        if ($reading !== null && LegacyBuilder::serves($legacy)) {
            throw LegacyBuilder::refuseExternal($legacy::class, ...$reading);
        }
    }

    /**
     * @param  list<string>  $externals
     * @return null|array{string, string}
     */
    private function reading(string $table, array $externals): null|array
    {
        $clauses = [
            ...($this->aggregate === null ? [] : [[$this->aggregate['function'].'()', $this->aggregate['columns'] ?? []]]),
            ['select', $this->columns ?? []],
            ['groupBy', $this->groups ?? []],
            ['having', self::havingColumns($this->havings ?? [])],
            ...array_map(fn (array $order) => ['orderBy '.($order['direction'] ?? 'asc'), [$order['column'] ?? null]], [...($this->orders ?? []), ...($this->unionOrders ?? [])]),
            ['where', self::whereColumns($this->wheres)],
        ];

        foreach ($clauses as [$operation, $columns]) {
            foreach ($columns as $column) {
                $attribute = is_string($column) ? self::attributeOf($table, $column) : null;

                if ($attribute !== null && in_array($attribute, $externals, true)) {
                    return [$attribute, $operation];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $wheres
     * @return list<mixed>
     */
    private static function whereColumns(array $wheres): array
    {
        $columns = [];

        foreach ($wheres as $where) {
            if (! is_array($where)) {
                continue;
            }

            if (($where['type'] ?? null) === 'Nested' && ($where['query'] ?? null) instanceof QueryBuilder) {
                $columns = [...$columns, ...self::whereColumns($where['query']->wheres)];
            }

            foreach (['column', 'first', 'second'] as $key) {
                $columns[] = $where[$key] ?? null;
            }

            if (is_array($where['columns'] ?? null)) {
                $columns = [...$columns, ...array_values($where['columns'])];
            }

            if (($where['type'] ?? null) === 'betweenColumns' && is_array($where['values'] ?? null)) {
                $columns = [...$columns, ...array_values($where['values'])];
            }
        }

        return $columns;
    }

    /**
     * @param  array<array-key, mixed>  $havings
     * @return list<mixed>
     */
    private static function havingColumns(array $havings): array
    {
        $columns = [];

        foreach ($havings as $having) {
            if (! is_array($having)) {
                continue;
            }

            if (($having['type'] ?? null) === 'Nested' && ($having['query'] ?? null) instanceof QueryBuilder) {
                $columns = [...$columns, ...self::havingColumns($having['query']->havings ?? [])];
            }

            $columns[] = $having['column'] ?? null;
        }

        return $columns;
    }

    private static function attributeOf(string $table, string $column): null|string
    {
        $column = trim((string) preg_replace('/\s+as\s+.*$/is', '', $column));

        if (! str_contains($column, '.')) {
            return $column;
        }

        return str_starts_with($column, $table.'.') ? substr($column, strlen($table) + 1) : null;
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $native
     * @param  Closure(Model): TResult  $translated
     * @return TResult
     */
    private function route(Closure $native, Closure $translated): mixed
    {
        $legacy = $this->legacy;

        if ($legacy === null || $this->native || $this->alias($legacy) === null) {
            return $native();
        }

        return ServedTable::write($legacy, $native, fn (): mixed => $translated($legacy));
    }

    private function alias(Model $legacy): null|string
    {
        $segments = is_string($this->from) ? preg_split('/\s+as\s+/i', trim($this->from)) : false;

        return is_array($segments) && count($segments) <= 2 && $segments[0] === $legacy->getTable() ? end($segments) : null;
    }

    private function writer(Model $legacy): RecordWriter
    {
        return new RecordWriter(LegacyBuilder::mapOf($legacy::class), $legacy->getConnection());
    }

    private function massWriter(Model $legacy): MassWriter
    {
        return new MassWriter(LegacyBuilder::mapOf($legacy::class), $legacy->getConnection());
    }

    private function unsupported(string $operation, string $because): CompatibilityWriteRefused
    {
        return CompatibilityWriteRefused::unsupported($this->legacy === null ? self::class : $this->legacy::class, $operation, $because);
    }
}
