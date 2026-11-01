<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;

/**
 * Keeps the previous models in step with a write to a current model's table. It replaces the triggers the
 * package once installed on the current tables:
 *
 * - an insert of a primary current row creates its retained row, filled by fillWhenCreatedFromCurrent();
 * - a hard delete of a primary current row deletes its retained rows first;
 * - an insert or update of a designated dependent row that the previous model can read fills an empty designation;
 * - a write that leaves a row in a refused state is refused, whoever writes it;
 * - a model's own write raises the previous model's events for every record it changed.
 *
 * The first three skip the compatibility map whose previous model is writing this row, which does that work itself.
 */
final readonly class CurrentWriter
{
    /**
     * @param  list<MapMember>  $members  every current-model member over this table
     */
    public function __construct(
        private WriteRouter $router,
        private Records $records,
        private Model $model,
        private array $members,
        private null|Model $source,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<int|string> the key of each inserted row
     */
    public function insert(Builder $builder, array $rows, bool $ignoreConflicts): array
    {
        $keyName = $this->model->getKeyName();
        $keys = [];

        foreach ($rows as $row) {
            $this->serialize([$row]);
            $before = $this->snapshots($this->primaryKeysOf([$row]));
            $insert = fn () => $builder->toBase()->clone()->insertGetId($row, $keyName);

            if ($ignoreConflicts) {
                try {
                    $key = $this->records->connection->transaction($insert);
                } catch (UniqueConstraintViolationException) {
                    continue;
                }
            } else {
                $key = $insert();
            }

            $key = is_int($key) || is_string($key) ? $key : (string) $key;
            $keys[] = $key;
            $created = $this->createRetained($key);
            $this->designate([$key]);
            $this->refuseStates([$key]);
            $this->replay($before, $created);
        }

        return $keys;
    }

    /**
     * @param  Closure(QueryBuilder): int  $write  the native write, run on a query of the rows it may change
     * @param  array<string, mixed>  $values  the values it writes, when it names them
     */
    public function update(Builder $builder, Closure $write, array $values): int
    {
        if (! $this->needsKeys(false)) {
            return $write($builder->toBase()->clone());
        }

        $rows = $this->rowsOf($builder);

        if ($rows === []) {
            return 0;
        }

        $primaries = $this->primaryKeysOf([...$rows, $values]);
        $this->lockPrimaries($primaries);
        $keys = $this->lockRows($builder, $rows);

        if ($keys === []) {
            return 0;
        }

        $this->serialize([...$rows, $values]);
        $before = $this->snapshots($primaries);
        $count = $write($this->byKeys($keys));
        $this->designate($keys);
        $this->refuseStates($keys);
        $this->replay($before);

        return $count;
    }

    public function delete(Builder $builder): int
    {
        if (! $this->needsKeys(true)) {
            return (int) $builder->toBase()->clone()->delete();
        }

        $rows = $this->rowsOf($builder);

        if ($rows === []) {
            return 0;
        }

        $primaries = $this->primaryKeysOf($rows);
        $this->lockPrimaries($primaries);
        $keys = $this->lockRows($builder, $rows);

        if ($keys === []) {
            return 0;
        }

        $before = $this->snapshots($primaries);

        foreach ($this->members as $member) {
            $resolvedMap = $this->router->resolvedMap($member->map);

            if ($member->alias === $resolvedMap->primaryAlias() && ! $this->applies($member)) {
                $previous = $resolvedMap->previousModel;
                $this->records->connection->table((string) $previous->table)->whereIn((string) $previous->primaryForeignKey, $keys)->delete();
            }
        }

        $count = (int) $this->byKeys($keys)->delete();
        $this->replay($before);

        return $count;
    }

    /**
     * Whether a write cannot run as one native statement, because something must happen per row.
     */
    public function needsKeys(bool $delete): bool
    {
        foreach ($this->members as $member) {
            $resolvedMap = $this->router->resolvedMap($member->map);
            $dependent = $resolvedMap->dependent($member->alias);

            if ($this->mirrors($member) || ($delete && $member->alias === $resolvedMap->primaryAlias() && ! $this->applies($member))) {
                return true;
            }

            if (! $delete && ($this->records->refusedStatesOn($resolvedMap, $member->alias) !== [] || ($dependent?->designationColumn !== null && ! $this->applies($member)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an insert has work beyond the native statement.
     */
    public function insertNeedsRows(): bool
    {
        foreach ($this->members as $member) {
            if ($member->alias === $this->router->resolvedMap($member->map)->primaryAlias()) {
                return true;
            }
        }

        return $this->needsKeys(false);
    }

    /**
     * Creates the retained row of each compatibility map whose primary current model this is.
     *
     * @return list<PreviousRecord>
     */
    private function createRetained(int|string $key): array
    {
        $created = [];
        $stored = null;

        foreach ($this->members as $member) {
            $resolvedMap = $this->router->resolvedMap($member->map);

            if ($member->alias !== $resolvedMap->primaryAlias() || $this->applies($member)) {
                continue;
            }

            $previous = $resolvedMap->previousModel;
            $stored ??= $this->stored($key);
            $values = [(string) $previous->primaryForeignKey => $key];

            foreach ($previous->fillWhenCreatedFromCurrent as $column => $fill) {
                $value = $fill($stored);

                if ($value !== null) {
                    $values[$column] = $value;
                }
            }

            foreach ($this->records->refusedStatesOn($resolvedMap, $resolvedMap->previousAlias()) as $state) {
                $this->records->serialize($resolvedMap, $state, $key);
            }

            $previousKey = $this->records->connection->table((string) $previous->table)->insertGetId($values, (string) $previous->keyColumn);
            $previousKey = is_int($previousKey) || is_string($previousKey) ? $previousKey : (string) $previousKey;
            $this->records->refuseStates($resolvedMap, $resolvedMap->previousAlias(), [$previousKey]);

            if ($this->mirrors($member)) {
                $created[] = $this->record($resolvedMap, $previousKey);
            }
        }

        return $created;
    }

    /**
     * The inserted row as stored, defaults included, through this model's class.
     */
    private function stored(int|string $key): Model
    {
        $row = $this->records->connection->table($this->model->getTable())->where($this->model->getKeyName(), $key)->first();

        return ($this->source ?? $this->model)->newFromBuilder((array) $row, $this->records->connection->getName());
    }

    /**
     * @param  list<int|string>  $keys
     */
    private function designate(array $keys): void
    {
        foreach ($this->members as $member) {
            $resolvedMap = $this->router->resolvedMap($member->map);
            $dependent = $resolvedMap->dependent($member->alias);

            if ($dependent?->designationColumn === null || $this->applies($member)) {
                continue;
            }

            foreach ($keys as $key) {
                $this->records->designate($resolvedMap, $dependent, $key);
            }
        }
    }

    /**
     * @param  list<int|string>  $keys
     */
    private function refuseStates(array $keys): void
    {
        foreach ($this->members as $member) {
            $this->records->refuseStates($this->router->resolvedMap($member->map), $member->alias, $keys);
        }
    }

    /**
     * Serializes the writers of each record a refused state compares, before the write.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function serialize(array $rows): void
    {
        foreach ($this->members as $member) {
            $resolvedMap = $this->router->resolvedMap($member->map);
            $dependent = $resolvedMap->dependent($member->alias);

            foreach ($dependent === null ? [] : $this->records->refusedStatesOn($resolvedMap, $member->alias) as $state) {
                foreach ($this->values($rows, $resolvedMap->primaryForeignKey($dependent)) as $primaryKey) {
                    $this->records->serialize($resolvedMap, $state, $primaryKey);
                }
            }
        }
    }

    /**
     * Each map's primary keys a write of these rows touches: a primary row's own key, a dependent row's primary
     * foreign key before and after.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<int|string>>
     */
    private function primaryKeysOf(array $rows): array
    {
        $primaries = [];

        foreach ($this->members as $member) {
            $resolvedMap = $this->router->resolvedMap($member->map);
            $dependent = $resolvedMap->dependent($member->alias);
            $column = $dependent === null ? $this->model->getKeyName() : $resolvedMap->primaryForeignKey($dependent);
            $primaries[$member->map] = array_values(array_unique([...$primaries[$member->map] ?? [], ...$this->values($rows, $column)]));
        }

        return $primaries;
    }

    /**
     * @param  array<string, list<int|string>>  $primaries
     */
    private function lockPrimaries(array $primaries): void
    {
        $byTable = [];

        foreach ($primaries as $map => $keys) {
            $resolvedMap = $this->router->resolvedMap($map);
            $primary = $resolvedMap->primaryAlias();
            $table = $resolvedMap->table($primary);

            if ($table !== $this->model->getTable()) {
                $byTable[$table] = [$resolvedMap->keyColumn($primary), [...$byTable[$table][1] ?? [], ...$keys]];
            }
        }

        ksort($byTable);

        foreach ($byTable as $table => [$keyColumn, $keys]) {
            $this->records->lock($table, $keyColumn, $keys);
        }
    }

    /**
     * The rows the write may change, with every column a member reads, before any lock.
     *
     * @return list<array<string, mixed>>
     */
    private function rowsOf(Builder $builder): array
    {
        $columns = [$this->model->getKeyName()];

        foreach ($this->members as $member) {
            $resolvedMap = $this->router->resolvedMap($member->map);
            $dependent = $resolvedMap->dependent($member->alias);

            if ($dependent !== null) {
                $columns[] = $resolvedMap->primaryForeignKey($dependent);
            }
        }

        $columns = array_values(array_unique($columns));

        return array_map(
            fn (object $row) => (array) $row,
            $builder->toBase()->clone()->get(array_map(fn (string $column) => $this->model->qualifyColumn($column).' as '.$column, $columns))->all(),
        );
    }

    /**
     * Locks the rows that still match the write, in key order, after their primary rows.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<int|string>
     */
    private function lockRows(Builder $builder, array $rows): array
    {
        $keys = $this->values($rows, $this->model->getKeyName());
        sort($keys);
        $qualified = $this->model->getQualifiedKeyName();

        return $this->records->keys($builder->toBase()->clone()->whereIn($qualified, $keys)->reorder($qualified)->lockForUpdate()->pluck($qualified)->all());
    }

    /**
     * @param  list<int|string>  $keys
     */
    private function byKeys(array $keys): QueryBuilder
    {
        return $this->records->connection->table($this->model->getTable())->whereIn($this->model->getKeyName(), $keys);
    }

    /**
     * Locks and reads every previous record of a mirrored map that the write can change.
     *
     * @param  array<string, list<int|string>>  $primaries
     * @return array<string, array{PreviousRecord, null|array<string, mixed>}>
     */
    private function snapshots(array $primaries): array
    {
        $snapshots = [];

        foreach ($this->members as $member) {
            if (! $this->mirrors($member)) {
                continue;
            }

            $resolvedMap = $this->router->resolvedMap($member->map);
            $this->records->lockPrimaries($resolvedMap, $primaries[$member->map] ?? []);

            foreach ($this->records->previousKeys($resolvedMap, $primaries[$member->map] ?? [], true) as $previousKey) {
                $record = $this->record($resolvedMap, $previousKey);
                $snapshots[$record->id()] = [$record, $this->records->viewRow($resolvedMap, $previousKey)];
            }
        }

        return $snapshots;
    }

    /**
     * Reads each record again and raises the previous model's events for each one that changed.
     *
     * @param  array<string, array{PreviousRecord, null|array<string, mixed>}>  $before
     * @param  list<PreviousRecord>  $created
     */
    private function replay(array $before, array $created = []): void
    {
        foreach ($created as $record) {
            $before[$record->id()] ??= [$record, null];
        }

        foreach ($before as [$record, $rowBefore]) {
            $rowAfter = $this->records->viewRow($this->router->resolvedMap($record->map), $record->key);
            $this->router->eventReplayer()->replay($record, $rowBefore, $rowAfter, $this->source ?? $this->model, (string) $this->records->connection->getName());
        }
    }

    private function record(ResolvedMap $resolvedMap, int|string $previousKey): PreviousRecord
    {
        $previous = $resolvedMap->previousModel;

        return new PreviousRecord($resolvedMap->name, (string) $previous->model, $previous->view, (string) $previous->keyColumn, $previousKey);
    }

    /**
     * Whether the compatibility map's own previous model is writing this row, and so does this work itself.
     */
    private function applies(MapMember $member): bool
    {
        return $this->source !== null && $this->router->applyingMap($this->source) === $member->map;
    }

    /**
     * Whether this write raises the member's previous model events: only for a model's own write, as Eloquent
     * raises no events for a query builder write.
     */
    private function mirrors(MapMember $member): bool
    {
        return $this->source !== null && $this->router->mirroring() && ! $this->applies($member);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<int|string>
     */
    private function values(array $rows, string $column): array
    {
        return $this->records->keys(array_values(array_unique(array_map(fn (array $row) => $row[$column] ?? null, $rows), SORT_REGULAR)));
    }
}
