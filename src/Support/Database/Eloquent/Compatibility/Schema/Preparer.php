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
        $foreignChecks = [];
        $indexes = [];

        if ($legacy === []) {
            throw InvalidCompatibilityMap::because($map->table, "the legacy table {$map->table} does not exist");
        }

        foreach ($map->identifiers as $name => $identifier) {
            $column = $legacy[$name] ?? throw InvalidCompatibilityMap::because($map->table, "{$map->table} has no column {$name}, so identifier '{$name}' has no type to take");
            $existing = $this->columnCatalog->of($map->primary->table)[(string) $identifier->column()] ?? null;

            if ($existing !== null && $existing->type !== $column->type) {
                throw InvalidCompatibilityMap::because($map->table, "{$map->primary->table} already has a column {$identifier->column()} of type {$existing->type}, and identifier '{$name}' needs {$column->type}, the type of {$map->table}.{$name}; change or drop {$map->primary->table}.{$identifier->column()}, then prepare again");
            }

            $default = $identifier->isUuid() ? 'gen_random_uuid()' : $this->sequenceDefault($map, $name, $column);
            $successorColumn = SqlFragments::identifier((string) $identifier->column());
            $filledName = SqlFragments::objectName($map->table, $map->primary->table.'_'.$identifier->column().'_filled');
            $filled = SqlFragments::identifier($filledName);
            $unique = SqlFragments::objectName($map->table, $map->primary->table.'_'.$identifier->column().'_unique');
            $columns[] = 'ADD COLUMN IF NOT EXISTS '.$successorColumn.' '.$column->type;
            $defaults[] = 'ALTER COLUMN '.$successorColumn.' SET DEFAULT '.$default;
            $defaults[] = 'DROP CONSTRAINT IF EXISTS '.$filled;
            $foreignChecks[] = $this->refuseForeignCheck($map, $filledName, (string) $identifier->column());
            $foreignChecks[] = $identifier->isUuid() ? '' : $this->refuseUnusableSequence($map, (string) $identifier->column(), $default);
            $fills[] = $successorColumn.' = COALESCE('.$successorColumn.', '.$default.')';
            $missing[] = $successorColumn.' IS NULL';
            $checks[] = ['ADD CONSTRAINT '.$filled.' CHECK ('.$successorColumn.' IS NOT NULL) NOT VALID', 'VALIDATE CONSTRAINT '.$filled];
            $notNull[] = ['ALTER COLUMN '.$successorColumn.' SET NOT NULL', 'DROP CONSTRAINT '.$filled];
            $indexes = [...$indexes, ...$this->uniqueIndex($map->table, $map->primary->table, $unique, '('.$successorColumn.')', true)];
        }

        $table = "ALTER TABLE {$primary}\n  ";

        return [
            [$table.implode(",\n  ", $columns), "DO \$compatibility\$\nDECLARE\n  found text;\nBEGIN\n".implode('', $foreignChecks)."END\n\$compatibility\$", $table.implode(",\n  ", $defaults)],
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

    private function refuseForeignCheck(ResolvedMap $map, string $name, string $column): string
    {
        $refusal = SqlFragments::literal("Compatibility map '{$map->table}' cannot prepare: {$name} already exists on {$map->primary->table} and is not the check prepare() adds. Drop or rename it, then prepare again.");
        $expected = "'CHECK ((' || quote_ident(".SqlFragments::literal($column).") || ' IS NOT NULL))'";

        return '  SELECT pg_get_constraintdef(oid) INTO found FROM pg_constraint WHERE conrelid = to_regclass(quote_ident(current_schema()) || '.SqlFragments::literal('.'.SqlFragments::identifier($map->primary->table)).') AND conname = '.SqlFragments::literal($name).";\n"
            ."  IF found NOT IN ({$expected}, {$expected} || ' NOT VALID') THEN\n"
            ."    RAISE EXCEPTION '%', {$refusal};\n"
            ."  END IF;\n";
    }

    private function refuseUnusableSequence(ResolvedMap $map, string $column, string $default): string
    {
        $sequence = (string) preg_replace('/^(?:pg_catalog\.)?nextval\((.+)\)$/s', '$1', $default);
        $primary = 'to_regclass(quote_ident(current_schema()) || '.SqlFragments::literal('.'.SqlFragments::identifier($map->primary->table)).')';
        $grantee = "CASE WHEN granted.grantee = 0 THEN 'PUBLIC' ELSE quote_ident(pg_get_userbyid(granted.grantee)) END AS inserter, granted.grantee";
        $refusal = SqlFragments::literal("Compatibility map '{$map->table}' cannot prepare: {$map->primary->table}.{$column} draws from ")
            ." || CAST({$sequence} AS text) || ".SqlFragments::literal(', and ').' || found || '.SqlFragments::literal(" can insert into {$map->primary->table} without USAGE on it. Grant USAGE ON SEQUENCE ")
            ." || CAST({$sequence} AS text) || ".SqlFragments::literal(' to each of them, then prepare again.');

        return "  SELECT string_agg(inserter, ', ' ORDER BY inserter) INTO found FROM (\n"
            ."    SELECT {$grantee} FROM pg_class relation CROSS JOIN LATERAL aclexplode(coalesce(relation.relacl, acldefault('r', relation.relowner))) AS granted WHERE relation.oid = {$primary} AND granted.privilege_type = 'INSERT'\n"
            ."    UNION SELECT {$grantee} FROM pg_attribute attribute CROSS JOIN LATERAL aclexplode(attribute.attacl) AS granted WHERE attribute.attrelid = {$primary} AND attribute.attnum > 0 AND NOT attribute.attisdropped AND granted.privilege_type = 'INSERT'\n"
            ."  ) AS inserters WHERE NOT has_sequence_privilege(CAST(CASE WHEN grantee = 0 THEN 'public' ELSE pg_get_userbyid(grantee) END AS name), CAST({$sequence} AS oid), 'USAGE, UPDATE');\n"
            ."  IF found IS NOT NULL THEN\n"
            ."    RAISE EXCEPTION '%', {$refusal};\n"
            ."  END IF;\n";
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
        $primary = 'to_regclass(quote_ident(current_schema()) || '.SqlFragments::literal('.'.SqlFragments::identifier($map->primary->table)).')';
        $refusal = fn (string $what) => SqlFragments::literal("Compatibility map '{$map->table}' cannot install: {$what}, so prepare() has not finished. Run Compatibility::prepare() until it completes, then install again.");
        $identifiers = '';

        foreach ($map->identifiers as $identifier) {
            $column = (string) $identifier->column();
            $identifiers .= "  IF NOT EXISTS (\n"
                ."    SELECT FROM pg_attribute attribute\n"
                ."    JOIN pg_index table_index ON table_index.indrelid = attribute.attrelid AND table_index.indisunique AND table_index.indisvalid AND table_index.indimmediate\n"
                ."      AND table_index.indpred IS NULL AND table_index.indexprs IS NULL AND table_index.indnkeyatts = 1 AND table_index.indkey[0] = attribute.attnum\n"
                ."    WHERE attribute.attrelid = {$primary} AND attribute.attname = ".SqlFragments::literal($column)." AND attribute.attnotnull\n"
                ."  ) THEN\n"
                ."    RAISE EXCEPTION '%', ".$refusal("{$map->primary->table}.{$column} is not yet NOT NULL with a unique index of its own").";\n"
                ."  END IF;\n";
        }

        $statements = ["DO \$compatibility\$\nBEGIN\n{$identifiers}END\n\$compatibility\$"];

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
