<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Illuminate\Database\ConnectionInterface;

final class ColumnCatalog
{
    /** @var array<string, array<string, Column>> */
    private array $tables = [];

    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * @return array<string, Column>
     */
    public function of(string $table): array
    {
        if (array_key_exists($table, $this->tables)) {
            return $this->tables[$table];
        }

        $rows = $this->connection->select(
            'select table_column.attname as name, format_type(table_column.atttypid, table_column.atttypmod) as type, pg_get_expr(column_default.adbin, column_default.adrelid) as "default", table_column.attnotnull as not_null
             from pg_attribute table_column
             left join pg_attrdef column_default on column_default.adrelid = table_column.attrelid and column_default.adnum = table_column.attnum
             where table_column.attrelid = to_regclass(?) and table_column.attnum > 0 and not table_column.attisdropped
             order by table_column.attnum',
            [SqlFragments::identifier($table)],
        );

        $columns = [];

        foreach ($rows as $row) {
            $columns[(string) $row->name] = new Column(
                (string) $row->type,
                $row->default === null ? null : (string) $row->default,
                $row->not_null === true,
            );
        }

        return $this->tables[$table] = $columns;
    }

    public function type(string $table, string $column): null|string
    {
        return $this->of($table)[$column]->type ?? null;
    }

    public function notNull(string $table, string $column): bool
    {
        return $this->of($table)[$column]->notNull ?? false;
    }
}
