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

        return new AffectedRecord($this->resolvedMap->name, (string) $previous->model, $previous->view, (string) $previous->table, (string) $previous->key, $key, RecordRole::Previous);
    }

    public function currentRecord(string $alias, int|string $key): AffectedRecord
    {
        $model = array_search($alias, $this->resolvedMap->currentModels(), true);
        $table = $this->resolvedMap->table($alias);

        return new AffectedRecord($this->resolvedMap->name, (string) $model, $table, $table, $this->resolvedMap->key($alias), $key, $alias === $this->resolvedMap->primaryCurrentModel->alias ? RecordRole::Primary : RecordRole::Dependent);
    }

    /**
     * @return array<string, int|string>
     */
    public function currentKeys(ConnectionInterface $connection, int|string $previousKey): array
    {
        $previous = $this->resolvedMap->previousModel;
        $primaryForeignKey = $previous->alias.'.'.SqlFragments::id((string) $previous->primaryForeignKey);
        $columns = [$primaryForeignKey.' AS '.SqlFragments::id((string) $this->resolvedMap->primaryCurrentModel->alias)];

        foreach ($this->resolvedMap->dependentCurrentModels as $dependent) {
            $alias = (string) $dependent->alias;
            $columns[] = '(SELECT '.$alias.'.'.SqlFragments::id($this->resolvedMap->key($alias))
                .' '.$this->resolvedMap->locate($dependent, $primaryForeignKey, (string) $previous->alias).') AS '.SqlFragments::id($alias);
        }

        $row = $connection->selectOne(
            'select '.implode(', ', $columns).' from '.SqlFragments::id((string) $previous->table).' '.$previous->alias
            .' where '.$previous->alias.'.'.SqlFragments::id((string) $previous->key).' = ?',
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
     * @return list<int|string>
     */
    public function previousKeys(ConnectionInterface $connection, int|string $primaryKey): array
    {
        $previous = $this->resolvedMap->previousModel;
        $rows = $connection->select(
            'select '.SqlFragments::id((string) $previous->key).' as key from '.SqlFragments::id((string) $previous->table)
            .' where '.SqlFragments::id((string) $previous->primaryForeignKey).' = ? order by 1',
            [$primaryKey],
        );

        return array_values(array_filter(
            array_map(fn (object $row) => $row->key, $rows),
            fn (mixed $key) => is_int($key) || is_string($key),
        ));
    }

    public function created(ConnectionInterface $connection): null|string
    {
        $key = $connection->scalar('select current_setting('.SqlFragments::literal(Setting::Created->for($this->resolvedMap)).', true)');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
