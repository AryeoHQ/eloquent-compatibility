<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Illuminate\Database\ConnectionInterface;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Schema\Setting;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;

final readonly class KeyTranslator
{
    public function __construct(private ResolvedMap $resolvedMap) {}

    public function previousRecord(int|string $key): AffectedRecord
    {
        $previous = $this->resolvedMap->previousModel;

        return new AffectedRecord($this->resolvedMap->name, (string) $previous->model, $previous->view, (string) $previous->table, (string) $previous->keyColumn, $key, RecordRole::Previous);
    }

    public function currentRecord(string $alias, int|string $key): AffectedRecord
    {
        $model = array_search($alias, $this->resolvedMap->currentModels(), true);
        $table = $this->resolvedMap->table($alias);

        return new AffectedRecord($this->resolvedMap->name, (string) $model, $table, $table, $this->resolvedMap->keyColumn($alias), $key, $alias === $this->resolvedMap->primaryCurrentModel->alias ? RecordRole::Primary : RecordRole::Dependent);
    }

    /**
     * @return array<string, int|string>
     */
    public function currentKeys(ConnectionInterface $connection, int|string $previousKey): array
    {
        $previous = $this->resolvedMap->previousModel;
        $primaryForeignKey = $previous->alias.'.'.SqlFragments::identifier((string) $previous->primaryForeignKey);
        $columns = [$primaryForeignKey.' AS '.SqlFragments::identifier((string) $this->resolvedMap->primaryCurrentModel->alias)];

        foreach ($this->resolvedMap->dependentCurrentModels as $dependent) {
            $alias = (string) $dependent->alias;
            $columns[] = '(SELECT '.$alias.'.'.SqlFragments::identifier($this->resolvedMap->keyColumn($alias))
                .' '.$this->resolvedMap->locate($dependent, $primaryForeignKey, (string) $previous->alias).') AS '.SqlFragments::identifier($alias);
        }

        $row = $connection->selectOne(
            'select '.implode(', ', $columns).' from '.SqlFragments::identifier((string) $previous->table).' '.$previous->alias
            .' where '.$previous->alias.'.'.SqlFragments::identifier((string) $previous->keyColumn).' = ?',
            [$previousKey],
        );

        $keys = [];

        foreach ((array) $row as $alias => $key) {
            if (is_int($key) || is_string($key)) {
                $keys[(string) $alias] = $key;
            }
        }

        return $keys;
    }

    /**
     * @return array<string, list<int|string>>
     */
    public function dependentKeys(ConnectionInterface $connection, int|string $previousKey): array
    {
        $previous = $this->resolvedMap->previousModel;
        $keys = [];

        foreach ($this->resolvedMap->dependentCurrentModels as $dependent) {
            $alias = (string) $dependent->alias;
            $rows = $connection->select(
                'select '.SqlFragments::identifier($this->resolvedMap->keyColumn($alias)).' as key from '.SqlFragments::identifier($this->resolvedMap->table($alias))
                .' where '.SqlFragments::identifier($this->resolvedMap->primaryForeignKey($dependent)).' = (select '.SqlFragments::identifier((string) $previous->primaryForeignKey)
                .' from '.SqlFragments::identifier((string) $previous->table).' where '.SqlFragments::identifier((string) $previous->keyColumn).' = ?) order by 1',
                [$previousKey],
            );

            $keys[$alias] = array_values(array_filter(
                array_map(fn (object $row) => $row->key, $rows),
                fn (mixed $key) => is_int($key) || is_string($key),
            ));
        }

        return $keys;
    }

    /**
     * @return list<int|string>
     */
    public function previousKeys(ConnectionInterface $connection, int|string $primaryKey): array
    {
        $previous = $this->resolvedMap->previousModel;
        $rows = $connection->select(
            'select '.SqlFragments::identifier((string) $previous->keyColumn).' as key from '.SqlFragments::identifier((string) $previous->table)
            .' where '.SqlFragments::identifier((string) $previous->primaryForeignKey).' = ? order by 1',
            [$primaryKey],
        );

        return array_values(array_filter(
            array_map(fn (object $row) => $row->key, $rows),
            fn (mixed $key) => is_int($key) || is_string($key),
        ));
    }

    public function createdPreviousKey(ConnectionInterface $connection): null|string
    {
        $key = $connection->scalar('select current_setting('.SqlFragments::literal(Setting::Created->for($this->resolvedMap)).', true)');

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function clearCreatedPreviousKey(ConnectionInterface $connection): void
    {
        $connection->select('select set_config('.SqlFragments::literal(Setting::Created->for($this->resolvedMap)).", '', true)");
    }
}
