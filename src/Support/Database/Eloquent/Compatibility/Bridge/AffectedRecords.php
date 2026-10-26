<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;

final readonly class AffectedRecords
{
    public function __construct(private MirrorIndex $mirrorIndex) {}

    /**
     * @return array<string, AffectedRecord>
     */
    public function of(ConnectionInterface $connection, Model $model, Operation $operation, bool $after): array
    {
        $records = [];

        foreach ($this->mirrorIndex->forModel($model::class) as $member) {
            $resolvedMap = $this->mirrorIndex->resolvedMap($member->map);
            $keyTranslator = $this->mirrorIndex->keyTranslator($member->map);
            $alias = $member->alias;
            $column = $resolvedMap->key($alias);

            if ($alias === $resolvedMap->previousModel->alias) {
                $key = $operation === Operation::Insert ? ($after ? $keyTranslator->created($connection) : null) : $this->originalKey($model, $column);

                foreach ($key === null ? [] : $keyTranslator->currentKeys($connection, $key) as $current => $currentKey) {
                    $record = $keyTranslator->currentRecord($current, $currentKey);
                    $records[$record->id()] = $record;
                }

                continue;
            }

            if ($alias === $resolvedMap->primaryCurrentModel->alias) {
                $primaryKey = $operation === Operation::Insert ? $this->key($model, $column) : $this->originalKey($model, $column);
                $previousKeys = match (true) {
                    $operation === Operation::Insert && ! $after => [],
                    $primaryKey !== null => $keyTranslator->previousKeys($connection, $primaryKey),
                    default => array_filter([$keyTranslator->created($connection)], fn (null|string $key) => $key !== null),
                };
            } else {
                $dependent = $resolvedMap->dependent($alias);

                if ($dependent === null) {
                    continue;
                }

                $primaryForeignKey = $resolvedMap->primaryForeignKey($dependent);
                $primaries = array_unique(array_filter(
                    [$operation === Operation::Insert ? null : $model->getOriginal($primaryForeignKey), $model->getAttribute($primaryForeignKey)],
                    fn (mixed $value) => is_int($value) || is_string($value),
                ));
                $previousKeys = [];

                foreach ($primaries as $primary) {
                    $previousKeys = [...$previousKeys, ...$keyTranslator->previousKeys($connection, $primary)];
                }
            }

            foreach ($previousKeys as $previousKey) {
                $record = $keyTranslator->previousRecord($previousKey);
                $records[$record->id()] = $record;
            }
        }

        return $this->acrossMaps($connection, $records);
    }

    /**
     * @param  array<string, AffectedRecord>  $records
     * @return array<string, RecordSnapshot>
     */
    public function snapshots(ConnectionInterface $connection, array $records, null|AffectedRecord $native, bool $lock): array
    {
        if ($lock) {
            $locks = array_values($records);

            if ($native !== null) {
                $locks[] = $native;
            }

            usort($locks, fn (AffectedRecord $a, AffectedRecord $b) => [$a->role->lockOrder(), $a->lockTable, (string) $a->key] <=> [$b->role->lockOrder(), $b->lockTable, (string) $b->key]);

            foreach ($locks as $record) {
                $connection->select(
                    'select 1 from '.SqlFragments::id($record->lockTable).' where '.SqlFragments::id($record->keyName).' = ? for update',
                    [$record->key],
                );
            }
        }

        $rows = [];

        foreach ($records as $id => $record) {
            $row = $connection->selectOne(
                'select * from '.SqlFragments::id($record->relation).' where '.SqlFragments::id($record->keyName).' = ? limit 1',
                [$record->key],
            );

            $rows[$id] = new RecordSnapshot($record, $row === null ? null : (array) $row);
        }

        return $rows;
    }

    public function native(Model $model): null|AffectedRecord
    {
        foreach ($this->mirrorIndex->forModel($model::class) as $member) {
            $resolvedMap = $this->mirrorIndex->resolvedMap($member->map);
            $key = $this->key($model, $resolvedMap->key($member->alias));

            if ($key === null) {
                return null;
            }

            return $member->alias === $resolvedMap->previousModel->alias
                ? $this->mirrorIndex->keyTranslator($member->map)->previousRecord($key)
                : $this->mirrorIndex->keyTranslator($member->map)->currentRecord($member->alias, $key);
        }

        return null;
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

            foreach ($this->mirrorIndex->forTable($record->relation) as $member) {
                if ($member->map === $record->map) {
                    continue;
                }

                $resolvedMap = $this->mirrorIndex->resolvedMap($member->map);
                $keyTranslator = $this->mirrorIndex->keyTranslator($member->map);
                $dependent = $resolvedMap->dependent($member->alias);
                $primaryKey = $dependent === null
                    ? $record->key
                    : $connection->scalar(
                        'select '.SqlFragments::id($resolvedMap->primaryForeignKey($dependent)).' from '.SqlFragments::id($record->relation)
                        .' where '.SqlFragments::id($record->keyName).' = ?',
                        [$record->key],
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

    private function originalKey(Model $model, string $column): null|int|string
    {
        $key = $model->getOriginal($column) ?? $model->getAttribute($column);

        return is_int($key) || is_string($key) ? $key : null;
    }

    private function key(Model $model, string $column): null|int|string
    {
        $key = $model->getAttribute($column);

        return is_int($key) || is_string($key) ? $key : null;
    }
}
