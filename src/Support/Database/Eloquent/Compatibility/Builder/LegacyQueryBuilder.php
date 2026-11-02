<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Builder;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Write\MassWriter;
use Support\Database\Eloquent\Compatibility\Write\RecordWriter;

final class LegacyQueryBuilder extends QueryBuilder
{
    public function __construct(ConnectionInterface $connection, null|Grammar $grammar = null, null|Processor $processor = null, private readonly null|Model $legacy = null)
    {
        parent::__construct($connection, $grammar, $processor);
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function insert(array $values): bool
    {
        $legacy = $this->served();

        if ($legacy === null || $values === []) {
            return parent::insert($values);
        }

        $legacy->getConnection()->transaction(function () use ($legacy, $values): void {
            foreach (self::rows($values) as $row) {
                $this->writer($legacy)->insert($legacy->newInstance(), $row);
            }
        });

        return true;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  null|string  $sequence
     */
    public function insertGetId(array $values, $sequence = null): int|string // @phpstan-ignore method.childReturnType
    {
        $legacy = $this->served();

        return $legacy === null ? parent::insertGetId($values, $sequence) : $this->writer($legacy)->insert($legacy->newInstance(), $values);
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function insertOrIgnore(array $values): int
    {
        $legacy = $this->served();

        if ($legacy === null || $values === []) {
            return parent::insertOrIgnore($values);
        }

        return $legacy->getConnection()->transaction(function () use ($legacy, $values): int {
            $inserted = 0;

            foreach (self::rows($values) as $row) {
                $inserted += $this->insertUnlessTaken($legacy, $legacy->newInstance(), $row) === null ? 0 : 1;
            }

            return $inserted;
        });
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, string>  $returning
     * @param  null|string|array<array-key, string>  $uniqueBy
     * @return Collection<int, \stdClass>
     */
    public function insertOrIgnoreReturning(array $values, array $returning = ['*'], array|string|null $uniqueBy = null): Collection
    {
        $legacy = $this->served();

        if ($legacy === null || $values === []) {
            return parent::insertOrIgnoreReturning($values, $returning, $uniqueBy);
        }

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
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function update(array $values): int
    {
        $legacy = $this->served();

        return $legacy === null ? parent::update($values) : $this->massWriter($legacy)->update($this->keys($legacy), $values);
    }

    /**
     * @param  array<array-key, mixed>  $columns
     * @param  array<array-key, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = []): int
    {
        $legacy = $this->served();

        return $legacy === null ? parent::incrementEach($columns, $extra) : $this->massWriter($legacy)->update($this->keys($legacy), $extra, self::amounts($columns, 1));
    }

    /**
     * @param  array<array-key, mixed>  $columns
     * @param  array<array-key, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = []): int
    {
        $legacy = $this->served();

        return $legacy === null ? parent::decrementEach($columns, $extra) : $this->massWriter($legacy)->update($this->keys($legacy), $extra, self::amounts($columns, -1));
    }

    /**
     * @param  mixed  $id
     */
    public function delete($id = null): int
    {
        $legacy = $this->served();

        if ($legacy === null) {
            return parent::delete($id);
        }

        if ($id !== null) {
            $this->where($this->from.'.id', '=', $id);
        }

        return $this->massWriter($legacy)->delete($this->keys($legacy));
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  string|array<array-key, string>  $uniqueBy
     * @param  null|array<array-key, mixed>  $update
     */
    public function upsert(array $values, array|string $uniqueBy, null|array $update = null): int
    {
        return $this->served() === null ? parent::upsert($values, $uniqueBy, $update) : throw $this->unsupported('upsert()', 'Its unique columns name legacy columns, and the unique indexes live on the successor tables.');
    }

    /**
     * @param  array<array-key, mixed>  $columns
     * @param  mixed  $query
     */
    public function insertUsing(array $columns, $query): int
    {
        return $this->served() === null ? parent::insertUsing($columns, $query) : throw $this->unsupported('insertUsing()', 'A subquery yields its rows inside the database, and each legacy row must be split across the successor tables.');
    }

    /**
     * @param  array<array-key, mixed>  $columns
     * @param  mixed  $query
     */
    public function insertOrIgnoreUsing(array $columns, $query): int
    {
        return $this->served() === null ? parent::insertOrIgnoreUsing($columns, $query) : throw $this->unsupported('insertOrIgnoreUsing()', 'A subquery yields its rows inside the database, and each legacy row must be split across the successor tables.');
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    public function updateFrom(array $values): int
    {
        return $this->served() === null ? parent::updateFrom($values) : throw $this->unsupported('updateFrom()', 'Its joined tables are read by the legacy table name, which is a view.');
    }

    public function truncate(): void
    {
        if ($this->served() !== null) {
            throw $this->unsupported('truncate()', 'It would empty the successor tables, which serve more than this legacy model.');
        }

        parent::truncate();
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

        return $keys->select($legacy->qualifyColumn($legacy->getKeyName()));
    }

    private function served(): null|Model
    {
        return $this->legacy !== null && $this->from === $this->legacy->getTable() && LegacyBuilder::serves($this->legacy) ? $this->legacy : null;
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
