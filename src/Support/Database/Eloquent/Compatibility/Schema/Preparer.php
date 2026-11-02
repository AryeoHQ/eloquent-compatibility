<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Support\Database\Eloquent\Compatibility\Definition\Related;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;

final readonly class Preparer
{
    private const int CHUNK = 1000;

    public function __construct(private ColumnCatalog $columnCatalog) {}

    public function statements(ResolvedMap $map): Statements
    {
        $checks = [];
        $indexes = [];

        foreach ($map->related as $related) {
            if ($related->successor->atMostOneBecause() !== null) {
                [$check, $index] = $this->atMostOne($map, $related);
                $checks[] = $check;
                $indexes = [...$indexes, ...$index];
            }
        }

        [$transaction, $afterwards] = $this->identifiers($map);

        return new Statements('prepare', [...$checks, ...$transaction], [...$afterwards, ...$indexes]);
    }

    /**
     * @return array{list<string>, list<string>}
     */
    private function identifiers(ResolvedMap $map): array
    {
        $legacy = $this->columnCatalog->of($map->table);
        $primary = SqlFragments::identifier($map->primary->table);
        $key = SqlFragments::identifier($map->primary->key);
        $keyType = $this->columnCatalog->type($map->primary->table, $map->primary->key) ?? throw InvalidCompatibilityMap::because($map->table, "the successor table {$map->primary->table} has no column {$map->primary->key}");
        $columns = [];
        $defaults = [];
        $fills = [];
        $missing = [];
        $checks = [];
        $notNull = [];
        $indexes = [];

        if ($legacy === []) {
            throw InvalidCompatibilityMap::because($map->table, "the legacy table {$map->table} does not exist");
        }

        foreach ($map->identifiers as $name => $identifier) {
            $column = $legacy[$name] ?? throw InvalidCompatibilityMap::because($map->table, "{$map->table} has no column {$name}, so identifier '{$name}' has no type to take");
            $default = $identifier->isUuid() ? 'gen_random_uuid()' : $this->sequenceDefault($map, $name, $column);
            $successorColumn = SqlFragments::identifier((string) $identifier->column());
            $filled = SqlFragments::identifier(SqlFragments::objectName($map->table, $map->primary->table.'_'.$identifier->column().'_filled'));
            $unique = SqlFragments::objectName($map->table, $map->primary->table.'_'.$identifier->column().'_unique');
            $columns[] = 'ADD COLUMN IF NOT EXISTS '.$successorColumn.' '.$column->type;
            $defaults[] = 'ALTER COLUMN '.$successorColumn.' SET DEFAULT '.$default;
            $defaults[] = 'DROP CONSTRAINT IF EXISTS '.$filled;
            $fills[] = $successorColumn.' = COALESCE('.$successorColumn.', '.$default.')';
            $missing[] = $successorColumn.' IS NULL';
            $checks[] = ['ADD CONSTRAINT '.$filled.' CHECK ('.$successorColumn.' IS NOT NULL) NOT VALID', 'VALIDATE CONSTRAINT '.$filled];
            $notNull[] = ['ALTER COLUMN '.$successorColumn.' SET NOT NULL', 'DROP CONSTRAINT '.$filled];
            $indexes = [...$indexes, ...$this->uniqueIndex($map->table, $map->primary->table, $unique, '('.$successorColumn.')', true)];
        }

        $table = "ALTER TABLE {$primary}\n  ";

        return [
            [$table.implode(",\n  ", $columns), $table.implode(",\n  ", $defaults)],
            [
                "DO \$compatibility\$\nDECLARE\n  filled_through {$keyType};\n  fill_upto {$keyType};\nBEGIN\n"
                    ."  LOOP\n"
                    ."    SELECT chunk.{$key} INTO fill_upto FROM (SELECT {$key} FROM {$primary} WHERE filled_through IS NULL OR {$key} > filled_through ORDER BY {$key} LIMIT ".self::CHUNK.") AS chunk ORDER BY chunk.{$key} DESC LIMIT 1;\n"
                    ."    EXIT WHEN fill_upto IS NULL;\n"
                    ."    UPDATE {$primary} SET ".implode(', ', $fills).' WHERE ('.implode(' OR ', $missing).") AND {$key} <= fill_upto AND (filled_through IS NULL OR {$key} > filled_through);\n"
                    ."    filled_through := fill_upto;\n"
                    ."    COMMIT;\n"
                    ."  END LOOP;\n"
                    ."END\n\$compatibility\$",
                $table.implode(",\n  ", array_column($checks, 0)),
                $table.implode(",\n  ", array_column($checks, 1)),
                // Two statements: ALTER TABLE drops constraints before SET NOT NULL, which would then scan the table.
                $table.implode(",\n  ", array_column($notNull, 0)),
                $table.implode(",\n  ", array_column($notNull, 1)),
                ...$indexes,
            ],
        ];
    }

    private function sequenceDefault(ResolvedMap $map, string $name, Column $column): string
    {
        if ($column->generated) {
            throw InvalidCompatibilityMap::because($map->table, "{$map->table}.{$name} is an identity column, and integer() shares a sequence that must outlive the legacy column");
        }

        if ($column->default === null || preg_match('/^(pg_catalog\.)?nextval\(/', $column->default) !== 1) {
            throw InvalidCompatibilityMap::because($map->table, "{$map->table}.{$name} draws from no sequence, and integer() shares the legacy table's own sequence");
        }

        return $column->default;
    }

    /**
     * @return array{string, list<string>}
     */
    private function atMostOne(ResolvedMap $map, Related $related): array
    {
        $name = 'the relation from '.class_basename($related->parent).' to '.class_basename($related->model);

        if ($related->belongsTo) {
            throw InvalidCompatibilityMap::because($map->table, "atMostOne() on {$name} needs no index, because the {$related->parentTable} row names one {$related->table} row by its key");
        }

        $index = SqlFragments::objectName($map->table, $related->table.'_'.$map->table.'_at_most_one');
        $table = SqlFragments::identifier($related->table);
        $key = SqlFragments::identifier($related->relatedKey);
        $conditions = ViewCompiler::conditions($related, false);
        $where = $conditions === [] ? '' : ' WHERE '.implode(' AND ', $conditions);
        $duplicates = 'SELECT '.$key.' FROM '.$table.' WHERE '.implode(' AND ', [...$conditions, $key.' IS NOT NULL']).' GROUP BY '.$key.' HAVING count(*) > 1';
        $refusal = SqlFragments::literal("Compatibility map '{$map->table}' cannot prepare: atMostOne() on {$name} allows one {$related->table} row for each {$related->relatedKey}, and ")
            .' || duplicated || '.SqlFragments::literal(" values of {$related->relatedKey} have more; the first are ").' || first_keys';

        return [
            "DO \$compatibility\$\nDECLARE\n  duplicated bigint;\n  first_keys text;\nBEGIN\n"
                ."  SELECT count(*) INTO duplicated FROM ({$duplicates}) AS duplicates;\n"
                ."  IF duplicated > 0 THEN\n"
                ."    SELECT string_agg(CAST(duplicate AS text), ', ' ORDER BY duplicate) INTO first_keys FROM ({$duplicates} ORDER BY {$key} LIMIT 5) AS first_duplicates (duplicate);\n"
                ."    RAISE EXCEPTION '%', {$refusal};\n"
                ."  END IF;\nEND\n\$compatibility\$",
            $this->uniqueIndex($map->table, $related->table, $index, '('.$key.')'.$where, false),
        ];
    }

    /**
     * @return list<string>
     */
    private function uniqueIndex(string $map, string $table, string $index, string $columns, bool $constraint): array
    {
        $relation = fn (string $name) => 'to_regclass(quote_ident(current_schema()) || '.SqlFragments::literal('.'.SqlFragments::identifier($name)).')';
        $definition = fn (string $of) => "substr(pg_get_indexdef({$of}), strpos(pg_get_indexdef({$of}), ' USING '))";
        $refusal = SqlFragments::literal("Compatibility map '{$map}' cannot prepare: {$index} already exists and is not the unique index prepare() builds. Drop or rename it, then prepare again.");
        $statements = [
            "DO \$compatibility\$\nDECLARE\n  existing regclass := {$relation($index)};\n  expected text;\n  found text;\nBEGIN\n"
                ."  IF existing IS NULL THEN\n    RETURN;\n  END IF;\n"
                ."  IF EXISTS (SELECT FROM pg_index WHERE indexrelid = existing AND NOT indisvalid) THEN\n"
                .'    DROP INDEX '.SqlFragments::identifier($index).";\n"
                ."    RETURN;\n  END IF;\n"
                ."  EXECUTE format('CREATE TEMPORARY TABLE %I (LIKE %I.%I)', ".SqlFragments::literal($table).', current_schema(), '.SqlFragments::literal($table).");\n"
                .'  CREATE UNIQUE INDEX '.SqlFragments::identifier($index).' ON '.SqlFragments::identifier($table).' '.$columns.";\n"
                .'  SELECT '.$definition('indexrelid').' INTO expected FROM pg_index WHERE indrelid = '.SqlFragments::literal('pg_temp.'.SqlFragments::identifier($table))."::regclass;\n"
                .'  SELECT '.$definition('indexrelid').' INTO found FROM pg_index WHERE indexrelid = existing AND indisunique AND indrelid = '.$relation($table).";\n"
                .'  DROP TABLE pg_temp.'.SqlFragments::identifier($table).";\n"
                ."  IF found IS DISTINCT FROM expected THEN\n"
                ."    RAISE EXCEPTION '%', {$refusal};\n"
                ."  END IF;\nEND\n\$compatibility\$",
            'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS '.SqlFragments::identifier($index).' ON '.SqlFragments::identifier($table).' '.$columns,
        ];

        if ($constraint) {
            $statements[] = "DO \$compatibility\$\nBEGIN\n"
                .'  IF NOT EXISTS (SELECT FROM pg_constraint WHERE conrelid = to_regclass(quote_ident(current_schema()) || '.SqlFragments::literal('.'.SqlFragments::identifier($table)).') AND conname = '.SqlFragments::literal($index).") THEN\n"
                .'    ALTER TABLE '.SqlFragments::identifier($table).' ADD CONSTRAINT '.SqlFragments::identifier($index).' UNIQUE USING INDEX '.SqlFragments::identifier($index).";\n"
                ."  END IF;\nEND\n\$compatibility\$";
        }

        return $statements;
    }
}
