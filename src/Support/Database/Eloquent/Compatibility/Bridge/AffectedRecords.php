<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Mapping\DependentCurrentModel;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;

final readonly class AffectedRecords
{
    public function __construct(private MirrorIndex $mirrorIndex) {}

    /**
     * @return array<string, RecordSnapshot>
     */
    public function before(ConnectionInterface $connection, Model $model, Operation $operation): array
    {
        $records = $this->of($connection, $model, $operation, false);
        $this->lock($connection, [...array_values($records), ...$this->lockedOnly($connection, $model, $operation)]);
        $newcomers = array_diff_key($this->of($connection, $model, $operation, false), $records);
        $this->lock($connection, $newcomers);

        return $this->snapshots($connection, [...$records, ...$newcomers]);
    }

    /**
     * @return array<string, AffectedRecord>
     */
    public function of(ConnectionInterface $connection, Model $model, Operation $operation, bool $afterStatement): array
    {
        $records = [];

        foreach ($this->mirrorIndex->forModel($model::class) as $member) {
            $resolvedMap = $this->mirrorIndex->resolvedMap($member->map);
            $keyTranslator = $this->mirrorIndex->keyTranslator($member->map);
            $alias = $member->alias;
            $column = $resolvedMap->keyColumn($alias);

            if ($alias === $resolvedMap->previousModel->alias) {
                $key = $operation === Operation::Insert ? ($afterStatement ? $keyTranslator->createdPreviousKey($connection) : null) : $this->originalKey($connection, $model, $resolvedMap->previousModel->view, $column);

                foreach ($key === null ? [] : $keyTranslator->currentKeys($connection, $key) as $current => $currentKey) {
                    $record = $keyTranslator->currentRecord($current, $currentKey);
                    $records[$record->id()] = $record;
                }

                continue;
            }

            if ($alias === $resolvedMap->primaryCurrentModel->alias) {
                $primaryKey = $operation === Operation::Insert ? $this->loaded($model, $column) : $this->originalKey($connection, $model, $resolvedMap->table($alias), $column);
                $previousKeys = match (true) {
                    $operation === Operation::Insert && ! $afterStatement => [],
                    $primaryKey !== null => $keyTranslator->previousKeys($connection, $primaryKey),
                    default => array_filter([$keyTranslator->createdPreviousKey($connection)], fn (null|string $key) => $key !== null),
                };
            } else {
                $dependent = $resolvedMap->dependent($alias);

                if ($dependent === null) {
                    continue;
                }

                $previousKeys = [];

                foreach ($this->primaries($connection, $model, $operation, $resolvedMap, $dependent) as $primary) {
                    $previousKeys = [...$previousKeys, ...$keyTranslator->previousKeys($connection, $primary)];
                }
            }

            foreach ($previousKeys as $previousKey) {
                $record = $keyTranslator->previousRecord($previousKey);
                $records[$record->id()] = $record;
            }
        }

        return $this->acrossMaps($connection, [...$records, ...$this->removed($connection, $model, $operation)]);
    }

    /**
     * @param  array<string, AffectedRecord>  $records
     * @return array<string, RecordSnapshot>
     */
    public function snapshots(ConnectionInterface $connection, array $records): array
    {
        $rows = [];

        foreach ($records as $id => $record) {
            $row = $connection->selectOne(
                'select * from '.SqlFragments::identifier($record->readFrom).' where '.SqlFragments::identifier($record->keyColumn).' = ? limit 1',
                [$record->keyValue],
            );

            $rows[$id] = new RecordSnapshot($record, $row === null ? null : (array) $row);
        }

        return $rows;
    }

    /**
     * @param  array<array-key, AffectedRecord>  $records
     */
    private function lock(ConnectionInterface $connection, array $records): void
    {
        $locks = array_values($records);
        usort($locks, fn (AffectedRecord $a, AffectedRecord $b) => [$a->role->lockOrder(), $a->lockedTable, (string) $a->keyValue] <=> [$b->role->lockOrder(), $b->lockedTable, (string) $b->keyValue]);

        foreach ($locks as $record) {
            $connection->select(
                'select 1 from '.SqlFragments::identifier($record->lockedTable).' where '.SqlFragments::identifier($record->keyColumn).' = ? for update',
                [$record->keyValue],
            );
        }
    }

    /**
     * @return list<AffectedRecord>
     */
    private function lockedOnly(ConnectionInterface $connection, Model $model, Operation $operation): array
    {
        $native = $this->native($connection, $model);
        $records = $native === null ? [] : [$native];

        foreach ($this->mirrorIndex->forModel($model::class) as $member) {
            $resolvedMap = $this->mirrorIndex->resolvedMap($member->map);
            $dependent = $resolvedMap->dependent($member->alias);

            foreach ($dependent === null ? [] : $this->primaries($connection, $model, $operation, $resolvedMap, $dependent) as $primary) {
                $records[] = $this->mirrorIndex->keyTranslator($member->map)->currentRecord((string) $resolvedMap->primaryCurrentModel->alias, $primary);
            }
        }

        return $records;
    }

    /**
     * @return list<int|string>
     */
    private function primaries(ConnectionInterface $connection, Model $model, Operation $operation, ResolvedMap $resolvedMap, DependentCurrentModel $dependent): array
    {
        $primaryForeignKey = $resolvedMap->primaryForeignKey($dependent);

        return array_values(array_unique(array_filter(
            [$operation === Operation::Insert ? null : $this->originalKey($connection, $model, $resolvedMap->table((string) $dependent->alias), $primaryForeignKey), $this->loaded($model, $primaryForeignKey)],
            fn (null|int|string $value) => $value !== null,
        )));
    }

    private function native(ConnectionInterface $connection, Model $model): null|AffectedRecord
    {
        foreach ($this->mirrorIndex->forModel($model::class) as $member) {
            $resolvedMap = $this->mirrorIndex->resolvedMap($member->map);
            $previous = $member->alias === $resolvedMap->previousModel->alias;
            $column = $resolvedMap->keyColumn($member->alias);
            $key = array_key_exists($column, $model->getAttributes())
                ? $this->loaded($model, $column)
                : $this->persisted($connection, $model, $previous ? $resolvedMap->previousModel->view : $resolvedMap->table($member->alias), $column);

            if ($key === null) {
                return null;
            }

            return $previous
                ? $this->mirrorIndex->keyTranslator($member->map)->previousRecord($key)
                : $this->mirrorIndex->keyTranslator($member->map)->currentRecord($member->alias, $key);
        }

        return null;
    }

    /**
     * @return array<string, AffectedRecord>
     */
    private function removed(ConnectionInterface $connection, Model $model, Operation $operation): array
    {
        $records = [];

        foreach ($this->removes($model, $operation) ? $this->mirrorIndex->forModel($model::class) : [] as $member) {
            $resolvedMap = $this->mirrorIndex->resolvedMap($member->map);

            if ($member->alias !== $resolvedMap->previousModel->alias) {
                continue;
            }

            $keyTranslator = $this->mirrorIndex->keyTranslator($member->map);
            $key = $this->originalKey($connection, $model, $resolvedMap->previousModel->view, $resolvedMap->keyColumn($member->alias));

            foreach ($key === null ? [] : $keyTranslator->dependentKeys($connection, $key) as $dependent => $dependentKeys) {
                foreach ($dependentKeys as $dependentKey) {
                    $record = $keyTranslator->currentRecord($dependent, $dependentKey);
                    $records[$record->id()] = $record;
                }
            }
        }

        return $records;
    }

    /**
     * @param  array<string, AffectedRecord>  $records
     * @return array<string, AffectedRecord>
     */
    private function acrossMaps(ConnectionInterface $connection, array $records): array
    {
        foreach ($records as $record) {
            if ($record->role === RecordRole::Previous) {
                continue;
            }

            foreach ($this->mirrorIndex->forRelation($record->readFrom) as $member) {
                if ($member->map === $record->map) {
                    continue;
                }

                $resolvedMap = $this->mirrorIndex->resolvedMap($member->map);
                $keyTranslator = $this->mirrorIndex->keyTranslator($member->map);
                $dependent = $resolvedMap->dependent($member->alias);
                $primaryKey = $dependent === null
                    ? $record->keyValue
                    : $connection->scalar(
                        'select '.SqlFragments::identifier($resolvedMap->primaryForeignKey($dependent)).' from '.SqlFragments::identifier($record->readFrom)
                        .' where '.SqlFragments::identifier($record->keyColumn).' = ?',
                        [$record->keyValue],
                    );

                if (! is_int($primaryKey) && ! is_string($primaryKey)) {
                    continue;
                }

                foreach ($keyTranslator->previousKeys($connection, $primaryKey) as $previousKey) {
                    $previous = $keyTranslator->previousRecord($previousKey);
                    $records[$previous->id()] = $previous;
                }
            }
        }

        return $records;
    }

    private function removes(Model $model, Operation $operation): bool
    {
        return $operation === Operation::Delete && (! method_exists($model, 'isForceDeleting') || $model->isForceDeleting());
    }

    private function originalKey(ConnectionInterface $connection, Model $model, string $relation, string $column): null|int|string
    {
        $key = array_key_exists($column, $model->getRawOriginal())
            ? $model->getOriginal($column) ?? $this->loaded($model, $column)
            : $this->persisted($connection, $model, $relation, $column);

        return is_int($key) || is_string($key) ? $key : null;
    }

    private function persisted(ConnectionInterface $connection, Model $model, string $relation, string $column): null|int|string
    {
        $self = $model->getOriginal($model->getKeyName());
        $key = $self === null ? null : $connection->scalar(
            'select '.SqlFragments::identifier($column).' from '.SqlFragments::identifier($relation).' where '.SqlFragments::identifier($model->getKeyName()).' = ?',
            [$self],
        );

        return is_int($key) || is_string($key) ? $key : null;
    }

    private function loaded(Model $model, string $column): null|int|string
    {
        $key = array_key_exists($column, $model->getAttributes()) ? $model->getAttribute($column) : null;

        return is_int($key) || is_string($key) ? $key : null;
    }
}
