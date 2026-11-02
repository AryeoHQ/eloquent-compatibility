<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Support\Database\Eloquent\Compatibility\Definition\Related;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;

final readonly class Preparer
{
    public function __construct(private ColumnCatalog $columnCatalog) {}

    public function statements(ResolvedMap $map): Statements
    {
        $afterwards = [];

        foreach ($map->related as $related) {
            if ($related->successor->atMostOneBecause() !== null) {
                $afterwards = [...$afterwards, ...$this->atMostOne($map, $related)];
            }
        }

        return new Statements('prepare', [$this->identifiers($map)], $afterwards);
    }

    private function identifiers(ResolvedMap $map): string
    {
        $legacy = $this->columnCatalog->of($map->table);
        $primary = $map->primary->table;
        $columns = [];

        if ($legacy === []) {
            throw InvalidCompatibilityMap::because($map->table, "the legacy table {$map->table} does not exist");
        }

        foreach ($map->identifiers as $name => $identifier) {
            $column = $legacy[$name] ?? throw InvalidCompatibilityMap::because($map->table, "{$map->table} has no column {$name}, so identifier '{$name}' has no type to take");
            $default = $identifier->isUuid() ? 'gen_random_uuid()' : $this->sequenceDefault($map, $name, $column);
            $constraint = SqlFragments::objectName($map->table, $primary.'_'.$identifier->column().'_unique');
            $columns[] = 'ADD COLUMN '.SqlFragments::identifier((string) $identifier->column()).' '.$column->type.' NOT NULL DEFAULT '.$default.' CONSTRAINT '.SqlFragments::identifier($constraint).' UNIQUE';
        }

        return 'ALTER TABLE '.SqlFragments::identifier($primary)."\n  ".implode(",\n  ", $columns);
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
     * @return list<string>
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
        $duplicates = 'SELECT '.$key.' FROM '.$table.$where.' GROUP BY '.$key.' HAVING count(*) > 1';
        $refusal = SqlFragments::literal("Compatibility map '{$map->table}' cannot prepare: atMostOne() on {$name} allows one {$related->table} row for each {$related->relatedKey}, and ")
            .' || duplicated || '.SqlFragments::literal(" values of {$related->relatedKey} have more; the first are ").' || first_keys';

        return [
            "DO \$compatibility\$\nDECLARE\n  duplicated bigint;\n  first_keys text;\nBEGIN\n"
                ."  SELECT count(*) INTO duplicated FROM ({$duplicates}) AS duplicates;\n"
                ."  IF duplicated > 0 THEN\n"
                ."    SELECT string_agg(CAST(duplicate AS text), ', ' ORDER BY duplicate) INTO first_keys FROM ({$duplicates} ORDER BY {$key} LIMIT 5) AS first_duplicates (duplicate);\n"
                ."    RAISE EXCEPTION '%', {$refusal};\n"
                ."  END IF;\nEND\n\$compatibility\$",
            'CREATE UNIQUE INDEX CONCURRENTLY '.SqlFragments::identifier($index).' ON '.$table.' ('.$key.')'.$where,
        ];
    }
}
