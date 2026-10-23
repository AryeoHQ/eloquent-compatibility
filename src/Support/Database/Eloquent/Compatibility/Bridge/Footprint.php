<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Schema\Sql;

final readonly class Footprint
{
    public function __construct(private MirroredCompatibilityMaps $mirroredMaps) {}

    /**
     * @return array<string, Record>
     */
    public function of(ConnectionInterface $connection, Model $model, string $operation, bool $after): array
    {
        $records = [];

        foreach ($this->mirroredMaps->forModel($model::class) as $member) {
            $declaration = $this->mirroredMaps->declaration($member['map']);
            $locator = $this->mirroredMaps->locator($member['map']);
            $alias = $member['alias'];
            $column = $declaration->key($alias);

            if ($alias === $declaration->previousModel->alias) {
                $key = $operation === 'insert' ? ($after ? $locator->created($connection) : null) : $this->originalKey($model, $column);

                foreach ($key === null ? [] : $locator->currentKeys($connection, $key) as $current => $currentKey) {
                    $record = $locator->currentRecord($current, $currentKey);
                    $records[$record->id()] = $record;
                }

                continue;
            }

            if ($alias === $declaration->primaryCurrentModel->alias) {
                $primaryKey = $operation === 'insert' ? $this->key($model, $column) : $this->originalKey($model, $column);
                $previousKeys = match (true) {
                    $operation === 'insert' && ! $after => [],
                    $primaryKey !== null => $locator->previousKeys($connection, $primaryKey),
                    default => array_filter([$locator->created($connection)], fn (null|string $key) => $key !== null),
                };
            } else {
                $dependent = $declaration->dependent($alias);

                if ($dependent === null) {
                    continue;
                }

                $primaryForeignKey = $declaration->primaryForeignKey($dependent);
                $primaries = array_unique(array_filter(
                    [$operation === 'insert' ? null : $model->getOriginal($primaryForeignKey), $model->getAttribute($primaryForeignKey)],
                    fn (mixed $value) => is_int($value) || is_string($value),
                ));
                $previousKeys = [];

                foreach ($primaries as $primary) {
                    $previousKeys = [...$previousKeys, ...$locator->previousKeys($connection, $primary)];
                }
            }

            foreach ($previousKeys as $previousKey) {
                $record = $locator->previousRecord($previousKey);
                $records[$record->id()] = $record;
            }
        }

        return $this->acrossMaps($connection, $records);
    }

    /**
     * @param  array<string, Record>  $records
     * @return array<string, array{record: Record, row: null|array<string, mixed>}>
     */
    public function rows(ConnectionInterface $connection, array $records, null|Record $native, bool $lock): array
    {
        if ($lock) {
            $locks = array_values($records);

            if ($native !== null) {
                $locks[] = $native;
            }

            usort($locks, fn (Record $a, Record $b) => [! $a->primary, ! $a->previous, $a->lockTable, (string) $a->key] <=> [! $b->primary, ! $b->previous, $b->lockTable, (string) $b->key]);

            foreach ($locks as $record) {
                $connection->select(
                    'select 1 from '.Sql::id($record->lockTable).' where '.Sql::id($record->keyName).' = ? for update',
                    [$record->key],
                );
            }
        }

        $rows = [];

        foreach ($records as $id => $record) {
            $row = $connection->selectOne(
                'select * from '.Sql::id($record->relation).' where '.Sql::id($record->keyName).' = ? limit 1',
                [$record->key],
            );

            $rows[$id] = ['record' => $record, 'row' => $row === null ? null : (array) $row];
        }

        return $rows;
    }

    public function native(Model $model): null|Record
    {
        foreach ($this->mirroredMaps->forModel($model::class) as $member) {
            $declaration = $this->mirroredMaps->declaration($member['map']);
            $key = $this->key($model, $declaration->key($member['alias']));

            if ($key === null) {
                return null;
            }

            return $member['alias'] === $declaration->previousModel->alias
                ? $this->mirroredMaps->locator($member['map'])->previousRecord($key)
                : $this->mirroredMaps->locator($member['map'])->currentRecord($member['alias'], $key);
        }

        return null;
    }

    /**
     * @param  array<string, Record>  $records
     * @return array<string, Record>
     */
    private function acrossMaps(ConnectionInterface $connection, array $records): array
    {
        foreach ($records as $record) {
            if ($record->previous) {
                continue;
            }

            foreach ($this->mirroredMaps->forTable($record->relation) as $member) {
                if ($member['map'] === $record->map) {
                    continue;
                }

                $declaration = $this->mirroredMaps->declaration($member['map']);
                $locator = $this->mirroredMaps->locator($member['map']);
                $dependent = $declaration->dependent($member['alias']);
                $primaryKey = $dependent === null
                    ? $record->key
                    : $connection->scalar(
                        'select '.Sql::id($declaration->primaryForeignKey($dependent)).' from '.Sql::id($record->relation)
                        .' where '.Sql::id($record->keyName).' = ?',
                        [$record->key],
                    );

                if (! is_int($primaryKey) && ! is_string($primaryKey)) {
                    continue;
                }

                foreach ($locator->previousKeys($connection, $primaryKey) as $previousKey) {
                    $previous = $locator->previousRecord($previousKey);
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
