<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Illuminate\Database\ConnectionInterface;
use Support\Database\Eloquent\Compatibility\Mapping\Declaration;
use Support\Database\Eloquent\Compatibility\Schema\Sql;

final readonly class Locator
{
    public function __construct(private Declaration $declaration) {}

    public function previousRecord(int|string $key): Record
    {
        $previous = $this->declaration->previousModel;

        return new Record($this->declaration->name, (string) $previous->model, $previous->view, (string) $previous->table, (string) $previous->key, $key, true);
    }

    public function currentRecord(string $alias, int|string $key): Record
    {
        $model = array_search($alias, $this->declaration->currentModels(), true);
        $table = $this->declaration->table($alias);

        return new Record($this->declaration->name, (string) $model, $table, $table, $this->declaration->key($alias), $key, false, $alias === $this->declaration->primaryCurrentModel->alias);
    }

    /**
     * @return array<string, int|string>
     */
    public function currentKeys(ConnectionInterface $connection, int|string $previousKey): array
    {
        $previous = $this->declaration->previousModel;
        $primaryForeignKey = $previous->alias.'.'.Sql::id((string) $previous->primaryForeignKey);
        $columns = [$primaryForeignKey.' AS '.Sql::id((string) $this->declaration->primaryCurrentModel->alias)];

        foreach ($this->declaration->dependentCurrentModels as $dependent) {
            $alias = (string) $dependent->alias;
            $columns[] = '(SELECT '.$alias.'.'.Sql::id($this->declaration->key($alias))
                .' '.$this->declaration->locate($dependent, $primaryForeignKey, (string) $previous->alias).') AS '.Sql::id($alias);
        }

        $row = $connection->selectOne(
            'select '.implode(', ', $columns).' from '.Sql::id((string) $previous->table).' '.$previous->alias
            .' where '.$previous->alias.'.'.Sql::id((string) $previous->key).' = ?',
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
        $previous = $this->declaration->previousModel;
        $rows = $connection->select(
            'select '.Sql::id((string) $previous->key).' as key from '.Sql::id((string) $previous->table)
            .' where '.Sql::id((string) $previous->primaryForeignKey).' = ? order by 1',
            [$primaryKey],
        );

        return array_values(array_filter(
            array_map(fn (object $row) => $row->key, $rows),
            fn (mixed $key) => is_int($key) || is_string($key),
        ));
    }

    public function created(ConnectionInterface $connection): null|string
    {
        $key = $connection->scalar("select current_setting('compatibility.created_".$this->declaration->name."', true)");

        return is_string($key) && $key !== '' ? $key : null;
    }
}
