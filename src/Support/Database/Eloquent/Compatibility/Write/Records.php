<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Database\Connection;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Mapping\DependentCurrentModel;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Mapping\UnrepresentableState;
use Support\Database\Eloquent\Compatibility\Mapping\UnrepresentableStateKind;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;

/**
 * The SQL the writers run on one connection: locks, reads through the compatibility view, designations and
 * refused-state checks. None of it writes a previous model's or a current model's values.
 */
final readonly class Records
{
    public function __construct(
        public Connection $connection,
        public ColumnCatalog $columns,
    ) {}

    /**
     * Locks rows of one table in key order, so two writers that lock one table never wait on each other in a cycle.
     *
     * @param  list<int|string>  $keys
     */
    public function lock(string $table, string $keyColumn, array $keys, string $mode = 'for update'): void
    {
        $keys = array_values(array_unique(array_filter($keys, fn (mixed $key) => $key !== null)));

        if ($keys === []) {
            return;
        }

        sort($keys);
        $this->connection->table($table)->whereIn($keyColumn, $keys)->orderBy($keyColumn)->lock($mode)->pluck($keyColumn);
    }

    public function lockPrimaries(ResolvedMap $resolvedMap, array $primaryKeys, string $mode = 'for update'): void
    {
        $primary = $resolvedMap->primaryAlias();
        $this->lock($resolvedMap->table($primary), $resolvedMap->keyColumn($primary), $primaryKeys, $mode);
    }

    public function primaryKeyOf(ResolvedMap $resolvedMap, int|string $previousKey): null|int|string
    {
        $previous = $resolvedMap->previousModel;
        $key = $this->connection->table((string) $previous->table)->where((string) $previous->keyColumn, $previousKey)->value((string) $previous->primaryForeignKey);

        return is_int($key) || is_string($key) ? $key : null;
    }

    /**
     * @return null|array<string, mixed>
     */
    public function retainedRow(ResolvedMap $resolvedMap, int|string $previousKey, bool $lock = false): null|array
    {
        $previous = $resolvedMap->previousModel;
        $query = $this->connection->table((string) $previous->table)->where((string) $previous->keyColumn, $previousKey);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * The record as the previous model reads it, or null when the view does not show it.
     *
     * @return null|array<string, mixed>
     */
    public function viewRow(ResolvedMap $resolvedMap, int|string $previousKey): null|array
    {
        $previous = $resolvedMap->previousModel;
        $row = $this->connection->table($previous->view)->where((string) $previous->keyColumn, $previousKey)->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * @param  list<int|string>  $primaryKeys
     * @return list<int|string>
     */
    public function previousKeys(ResolvedMap $resolvedMap, array $primaryKeys, bool $lock = false): array
    {
        $previous = $resolvedMap->previousModel;
        $primaryKeys = array_values(array_unique(array_filter($primaryKeys, fn (mixed $key) => $key !== null)));

        if ($primaryKeys === []) {
            return [];
        }

        $query = $this->connection->table((string) $previous->table)
            ->whereIn((string) $previous->primaryForeignKey, $primaryKeys)
            ->orderBy((string) $previous->keyColumn);

        return $this->keys(($lock ? $query->lockForUpdate() : $query)->pluck((string) $previous->keyColumn)->all());
    }

    /**
     * The dependent row the previous model reads for a record: its designated row, or else its first readable row.
     */
    public function locate(ResolvedMap $resolvedMap, DependentCurrentModel $dependent, int|string $primaryKey, mixed $designation, bool $lock): null|int|string
    {
        $alias = (string) $dependent->alias;
        $key = $alias.'.'.SqlFragments::identifier($resolvedMap->keyColumn($alias));
        $bindings = [$primaryKey];
        $sql = 'select '.$key.' as key from '.SqlFragments::identifier($resolvedMap->table($alias)).' '.$alias
            .' where '.$alias.'.'.SqlFragments::identifier($resolvedMap->primaryForeignKey($dependent)).' = ?';

        if ($dependent->designationColumn !== null) {
            if ($designation === null) {
                return null;
            }

            $sql .= ' and '.$key.' = ?';
            $bindings[] = $designation;
        }

        $sql .= ' and ('.$dependent->readableWhen.') order by '.$key.' limit 1'.($lock ? ' for update of '.$alias : '');
        $row = $this->connection->selectOne($sql, $bindings);
        $located = $row === null ? null : $row->key;

        return is_int($located) || is_string($located) ? $located : null;
    }

    public function exists(string $table, string $keyColumn, mixed $key): bool
    {
        return $key !== null && $this->connection->table($table)->where($keyColumn, $key)->exists();
    }

    /**
     * @param  list<int|string>  $keys
     * @return array<int|string, mixed> each key's value of $column
     */
    public function column(string $table, string $keyColumn, array $keys, string $column): array
    {
        if ($keys === []) {
            return [];
        }

        $values = [];

        foreach ($this->connection->table($table)->whereIn($keyColumn, $keys)->get([$keyColumn.' as key', $column.' as value']) as $row) {
            $values[$row->key] = $row->value;
        }

        return $values;
    }

    /**
     * Names this dependent row in the record's designation, when the row is readable and the designation is empty.
     */
    public function designate(ResolvedMap $resolvedMap, DependentCurrentModel $dependent, int|string $dependentKey): void
    {
        $alias = (string) $dependent->alias;
        $previous = $resolvedMap->previousModel;
        $row = $this->connection->selectOne(
            'select '.$alias.'.'.SqlFragments::identifier($resolvedMap->primaryForeignKey($dependent)).' as primary_key from '.SqlFragments::identifier($resolvedMap->table($alias)).' '.$alias
            .' where '.$alias.'.'.SqlFragments::identifier($resolvedMap->keyColumn($alias)).' = ? and coalesce(('.$dependent->readableWhen.'), false)',
            [$dependentKey],
        );

        if ($row === null || $row->primary_key === null) {
            return;
        }

        $this->connection->table((string) $previous->table)
            ->where((string) $previous->primaryForeignKey, $row->primary_key)
            ->whereNull((string) $dependent->designationColumn)
            ->update([(string) $dependent->designationColumn => $dependentKey]);
    }

    /**
     * Serializes the writers of one record for a refused state checked on a dependent's table or the retained
     * table: they lock the primary row for key share, then take one advisory lock per state and record.
     */
    public function serialize(ResolvedMap $resolvedMap, UnrepresentableState $state, mixed $primaryKey): void
    {
        if ($primaryKey === null || $state->checkedOn === $resolvedMap->primaryAlias()) {
            return;
        }

        $this->lockPrimaries($resolvedMap, [$primaryKey], 'for key share');
        $this->connection->select(
            'select pg_catalog.pg_advisory_xact_lock(pg_catalog.hashtextextended(?, 0))',
            ['compatibility:'.$resolvedMap->name.':'.$state->name.':'.$primaryKey],
        );
    }

    /**
     * @return list<UnrepresentableState>
     */
    public function refusedStatesOn(ResolvedMap $resolvedMap, string $alias): array
    {
        return array_values(array_filter(
            $resolvedMap->unrepresentableStates(UnrepresentableStateKind::Refuse),
            fn (UnrepresentableState $state) => $state->checkedOn === $alias,
        ));
    }

    /**
     * Refuses the write when any of these rows of $alias is in a refused state.
     *
     * @param  list<int|string>  $keys
     */
    public function refuseStates(ResolvedMap $resolvedMap, string $alias, array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $table = $resolvedMap->table($alias);
        $key = $alias.'.'.SqlFragments::identifier($resolvedMap->keyColumn($alias));

        foreach ($this->refusedStatesOn($resolvedMap, $alias) as $state) {
            $row = $this->connection->selectOne(
                'select '.$key.' as key from '.SqlFragments::identifier($table).' '.$alias
                .' where '.$key.' in ('.implode(', ', array_fill(0, count($keys), '?')).') and coalesce(('.$state->checkCondition.'), false) order by 1 limit 1',
                $keys,
            );

            if ($row !== null) {
                throw CompatibilityWriteRefused::refusedState($resolvedMap->name, $state->description, $table, $row->key, $state->because, $state->instead);
            }
        }
    }

    /**
     * The columns of a table that reference the table's own key, such as a parent_id.
     *
     * @return list<string>
     */
    public function selfReferences(string $table): array
    {
        return array_map(fn (object $row) => (string) $row->name, $this->connection->select(
            "select referencing.attname as name
             from pg_constraint reference
             join pg_attribute referencing on referencing.attrelid = reference.conrelid and referencing.attnum = reference.conkey[1]
             where reference.contype = 'f' and reference.conrelid = reference.confrelid and pg_catalog.array_length(reference.conkey, 1) = 1
               and reference.conrelid = to_regclass(quote_ident(current_schema()) || '.' || ?)
             order by 1",
            [SqlFragments::identifier($table)],
        ));
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<int|string>
     */
    public function keys(array $values): array
    {
        return array_values(array_filter($values, fn (mixed $key) => is_int($key) || is_string($key)));
    }
}
