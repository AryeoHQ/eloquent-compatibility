<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Illuminate\Database\ConnectionInterface;
use Support\Database\Eloquent\Compatibility\Definition\Related;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Write\Backfiller;

final readonly class Preparer
{
    public function __construct(private ColumnCatalog $columnCatalog) {}

    public function statements(ResolvedMap $map, ConnectionInterface $connection): Statements
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

        return new Statements(
            operation: 'prepare',
            transaction: [
                ...$checks,
                ...$this->successorKey($map),
                ...Backfiller::storage(
                    map: $map,
                    connection: $connection,
                ),
            ],
            afterwards: [
                ...$this->uniqueIndex(
                    map: $map->table,
                    table: $map->table,
                    index: self::uniqueName($map),
                    columns: '('.SqlFragments::identifier($map->successorKey).')',
                    constraint: true,
                ),
                ...$indexes,
                ...$this->updatedAtIndex($map),
            ],
        );
    }

    public static function uniqueName(ResolvedMap $map): string
    {
        return SqlFragments::objectName(
            map: $map->table,
            name: $map->table.'_'.$map->successorKey.'_unique',
        );
    }

    /**
     * @return list<string>
     */
    private function successorKey(ResolvedMap $map): array
    {
        $legacy = $this->columnCatalog->of($map->table);

        if ($legacy === []) {
            throw InvalidCompatibilityMap::because($map->table, "the legacy table {$map->table} does not exist");
        }

        $type = $this->columnCatalog->type(
            table: $map->primary->table,
            column: $map->primary->key,
        ) ?? throw InvalidCompatibilityMap::because(
            map: $map->table,
            problem: "the successor table {$map->primary->table} has no column {$map->primary->key}",
        );
        $existing = $legacy[$map->successorKey] ?? null;

        if ($existing !== null && $existing->type !== $type) {
            throw InvalidCompatibilityMap::because(
                map: $map->table,
                problem: "{$map->table}.{$map->successorKey} is {$existing->type}, and the successor key holds the {$type} key of {$map->primary->table}; change or drop {$map->table}.{$map->successorKey}, then prepare again",
            );
        }

        if ($existing !== null && $existing->generated) {
            throw InvalidCompatibilityMap::because(
                map: $map->table,
                problem: "{$map->table}.{$map->successorKey} is a generated or identity column, and the backfill writes the key of each row's ".class_basename($map->primary->model).' into it',
            );
        }

        return $existing === null ? ['ALTER TABLE '.SqlFragments::identifier($map->table).' ADD COLUMN IF NOT EXISTS '.SqlFragments::identifier($map->successorKey).' '.$type] : [];
    }

    /**
     * @return list<string>
     */
    private function updatedAtIndex(ResolvedMap $map): array
    {
        $legacy = new $map->model;
        $column = $legacy->usesTimestamps() ? $legacy->getUpdatedAtColumn() : null;

        if ($column === null || ! array_key_exists($column, $this->columnCatalog->of($map->table)) || $this->columnCatalog->leadsAnIndex($map->table, $column)) {
            return [];
        }

        $index = SqlFragments::objectName($map->table, $map->table.'_'.$column.'_index');
        $relation = 'to_regclass(quote_ident(current_schema()) || '.SqlFragments::literal('.'.SqlFragments::identifier($index)).')';
        $refusal = InvalidCompatibilityMap::cannot($map->table, 'prepare', "{$index} already exists and is not a valid B-tree index on {$map->table} that leads with {$column} over every row, so the catch-up before install would scan {$map->table}. Rename or drop {$index}, then prepare again");

        return [
            "DO \$compatibility\$\nBEGIN\n"
                ."  IF EXISTS (SELECT FROM pg_index WHERE indexrelid = {$relation} AND NOT indisvalid) THEN\n"
                .'    DROP INDEX '.SqlFragments::identifier($index).";\n"
                ."  ELSIF {$relation} IS NOT NULL THEN\n"
                ."    RAISE EXCEPTION '%', ".SqlFragments::literal($refusal->getMessage()).";\n"
                ."  END IF;\nEND\n\$compatibility\$",
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS '.SqlFragments::identifier($index).' ON '.SqlFragments::identifier($map->table).' ('.SqlFragments::identifier($column).')',
        ];
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

        [$index, $columns] = $this->atMostOneIndex($map, $related);
        $table = SqlFragments::identifier($related->table);
        $key = SqlFragments::identifier($related->relatedKey);
        $conditions = ViewCompiler::conditions($related, false);
        $where = $conditions === [] ? '' : ' WHERE '.implode(' AND ', $conditions);
        $duplicates = 'SELECT '.$key.' FROM '.$table.' WHERE '.implode(' AND ', [...$conditions, $key.' IS NOT NULL']).' GROUP BY '.$key.' HAVING count(*) > 1';
        $refusal = SqlFragments::literal("Compatibility map '{$map->table}' cannot prepare: atMostOne() on {$name} allows one {$related->table} row for each {$related->relatedKey}, and ")
            .' || duplicated || '.SqlFragments::literal(" values of {$related->relatedKey} have more; the first are ").' || first_keys';
        $zoned = $related->conditions === null ? '' : $this->refuseZonedCondition($map, $name, $related->table, $key, $where);

        return [
            "DO \$compatibility\$\nDECLARE\n  duplicated bigint;\n  first_keys text;\n".($zoned === '' ? '' : "  zone text := current_setting('TimeZone');\n  west text;\n  east text;\n")."BEGIN\n"
                .$zoned
                ."  SELECT count(*) INTO duplicated FROM ({$duplicates}) AS duplicates;\n"
                ."  IF duplicated > 0 THEN\n"
                ."    SELECT string_agg(CAST(duplicate AS text), ', ' ORDER BY duplicate) INTO first_keys FROM ({$duplicates} ORDER BY {$key} LIMIT 5) AS first_duplicates (duplicate);\n"
                ."    RAISE EXCEPTION '%', {$refusal};\n"
                ."  END IF;\nEND\n\$compatibility\$",
            $this->uniqueIndex($map->table, $related->table, $index, $columns, false),
        ];
    }

    private function refuseZonedCondition(ResolvedMap $map, string $name, string $relation, string $key, string $where): string
    {
        $table = SqlFragments::identifier($relation);
        $refusal = SqlFragments::literal("Compatibility map '{$map->table}' cannot prepare: atMostOne() on {$name} has a condition whose instant follows the session time zone, and the index and the view would each read it in the time zone of their own session. Compare a timestamp with time zone with a value that names its offset.");
        $predicate = fn (string $index) => "  SELECT pg_get_expr(indpred, indrelid) INTO {$index} FROM pg_index WHERE indexrelid = ".SqlFragments::literal('pg_temp.compatibility_'.$index)."::regclass;\n";

        return "  EXECUTE format('CREATE TEMPORARY TABLE %I (LIKE %I.%I)', ".SqlFragments::literal($relation).', current_schema(), '.SqlFragments::literal($relation).");\n"
            ."  PERFORM set_config('TimeZone', 'Etc/GMT+12', true);\n"
            ."  CREATE INDEX compatibility_west ON {$table} ({$key}){$where};\n"
            ."  PERFORM set_config('TimeZone', 'Etc/GMT-14', true);\n"
            ."  CREATE INDEX compatibility_east ON {$table} ({$key}){$where};\n"
            ."  PERFORM set_config('TimeZone', zone, true);\n"
            .$predicate('west')
            .$predicate('east')
            ."  DROP TABLE pg_temp.{$table};\n"
            ."  IF west IS DISTINCT FROM east THEN\n"
            ."    RAISE EXCEPTION '%', {$refusal};\n"
            ."  END IF;\n";
    }

    /**
     * @return list<string>
     */
    public function unfinished(ResolvedMap $map): array
    {
        $refusal = fn (string $what) => SqlFragments::literal("Compatibility map '{$map->table}' cannot install: {$what}, so prepare() has not finished. Run Compatibility::prepare() until it completes, then install again.");
        $legacy = $this->relation($map->table);
        $statements = ["DO \$compatibility\$\nBEGIN\n"
            ."  IF NOT EXISTS (\n"
            ."    SELECT FROM pg_attribute attribute\n"
            ."    JOIN pg_index table_index ON table_index.indrelid = attribute.attrelid AND table_index.indisunique AND table_index.indisvalid AND table_index.indimmediate\n"
            ."      AND table_index.indpred IS NULL AND table_index.indexprs IS NULL AND table_index.indnkeyatts = 1 AND table_index.indkey[0] = attribute.attnum\n"
            ."    WHERE attribute.attrelid = {$legacy} AND attribute.attname = ".SqlFragments::literal($map->successorKey)." AND NOT attribute.attisdropped\n"
            ."  ) THEN\n"
            ."    RAISE EXCEPTION '%', ".$refusal("{$map->table}.{$map->successorKey} has no unique index of its own").";\n"
            ."  END IF;\n"
            .'  IF '.$this->relation(Backfiller::ledger($map->table))." IS NULL THEN\n"
            ."    RAISE EXCEPTION '%', ".$refusal(Backfiller::ledger($map->table).' does not exist').";\n"
            ."  END IF;\nEND\n\$compatibility\$"];

        foreach ($map->related as $related) {
            if ($related->successor->atMostOneBecause() !== null && ! $related->belongsTo) {
                [$index, $columns] = $this->atMostOneIndex($map, $related);
                $statements[] = "DO \$compatibility\$\nDECLARE\n  existing regclass := ".$this->relation($index).";\n  expected text;\n  found text;\nBEGIN\n"
                    .$this->definitions($related->table, $index, $columns, true)
                    ."  IF found IS DISTINCT FROM expected THEN\n"
                    ."    RAISE EXCEPTION '%', ".$refusal("{$index} is missing, not valid, or not the unique index prepare() builds on {$related->table}").";\n"
                    ."  END IF;\nEND\n\$compatibility\$";
            }
        }

        return $statements;
    }

    /**
     * @return array{string, string}
     */
    private function atMostOneIndex(ResolvedMap $map, Related $related): array
    {
        $conditions = ViewCompiler::conditions($related, false);

        return [
            SqlFragments::objectName($map->table, $related->table.'_'.$map->table.'_at_most_one'),
            '('.SqlFragments::identifier($related->relatedKey).')'.($conditions === [] ? '' : ' WHERE '.implode(' AND ', $conditions)),
        ];
    }

    private function relation(string $name): string
    {
        return 'to_regclass(quote_ident(current_schema()) || '.SqlFragments::literal('.'.SqlFragments::identifier($name)).')';
    }

    private function definitions(string $table, string $index, string $columns, bool $valid): string
    {
        $definition = "substr(pg_get_indexdef(indexrelid), strpos(pg_get_indexdef(indexrelid), ' USING '))";

        return "  EXECUTE format('CREATE TEMPORARY TABLE %I (LIKE %I.%I)', ".SqlFragments::literal($table).', current_schema(), '.SqlFragments::literal($table).");\n"
            .'  CREATE UNIQUE INDEX '.SqlFragments::identifier($index).' ON '.SqlFragments::identifier($table).' '.$columns.";\n"
            .'  SELECT '.$definition.' INTO expected FROM pg_index WHERE indrelid = '.SqlFragments::literal('pg_temp.'.SqlFragments::identifier($table))."::regclass;\n"
            .'  SELECT '.$definition.' INTO found FROM pg_index WHERE indexrelid = existing AND indisunique'.($valid ? ' AND indisvalid' : '').' AND indrelid = '.$this->relation($table).";\n"
            .'  DROP TABLE pg_temp.'.SqlFragments::identifier($table).";\n";
    }

    /**
     * @return list<string>
     */
    private function uniqueIndex(string $map, string $table, string $index, string $columns, bool $constraint): array
    {
        $refusal = SqlFragments::literal("Compatibility map '{$map}' cannot prepare: {$index} already exists and is not the unique index prepare() builds. Drop or rename it, then prepare again.");
        $statements = [
            "DO \$compatibility\$\nDECLARE\n  existing regclass := {$this->relation($index)};\n  expected text;\n  found text;\nBEGIN\n"
                ."  IF existing IS NULL THEN\n    RETURN;\n  END IF;\n"
                .$this->definitions($table, $index, $columns, false)
                ."  IF found IS DISTINCT FROM expected THEN\n"
                ."    RAISE EXCEPTION '%', {$refusal};\n"
                ."  END IF;\n"
                ."  IF EXISTS (SELECT FROM pg_index WHERE indexrelid = existing AND NOT indisvalid) THEN\n"
                ."    EXECUTE format('DROP INDEX %s', existing);\n"
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
