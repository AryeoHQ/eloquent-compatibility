<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\AsType;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\InTimeZone;
use Support\Database\Eloquent\Compatibility\Definition\Conversions\ReadAs;
use Support\Database\Eloquent\Compatibility\Definition\Related;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;

final readonly class ViewCompiler
{
    public function __construct(private ColumnCatalog $columnCatalog, private ConnectionInterface $connection) {}

    public function create(ResolvedMap $map): string
    {
        $legacy = $this->columnCatalog->of($map->table);

        return 'CREATE VIEW '.SqlFragments::identifier($map->table)." AS\n".$this->select(
            map: $map,
            shape: $legacy,
            defaultsOn: $map->table,
            retained: $legacy,
        );
    }

    public function replace(ResolvedMap $map): string
    {
        $shape = $this->columnCatalog->of($map->table);
        $retained = $this->columnCatalog->of(self::retainedTable($map));

        return 'CREATE OR REPLACE VIEW '.SqlFragments::identifier($map->table)." AS\n".$this->select(
            map: $map,
            shape: $shape,
            defaultsOn: self::retainedTable($map),
            retained: $retained,
        );
    }

    public function beforeInstall(ResolvedMap $map, bool $hides = true): string
    {
        $legacy = $this->columnCatalog->of($map->table);

        return $this->select(
            map: $map,
            shape: $legacy,
            defaultsOn: $map->table,
            retained: $legacy,
            hides: $hides,
            from: $map->table,
        );
    }

    public function unhidden(ResolvedMap $map): string
    {
        $retainedTable = self::retainedTable($map);

        return $this->select(
            map: $map,
            shape: $this->columnCatalog->of($map->table),
            defaultsOn: $retainedTable,
            retained: $this->columnCatalog->of($retainedTable),
            hides: false,
        );
    }

    /**
     * @param  array<string, Column>  $shape
     * @param  list<string>  $external
     * @return array<string, string>
     */
    public function served(ResolvedMap $map, array $shape, array $external = [], null|string $retainedFrom = null): array
    {
        if ($map->morphs !== []) {
            return $this->morphed($map, $shape, $external, $retainedFrom);
        }

        $external = [...$external, ...$map->externals()];
        $this->check($map, $shape, $external);
        $reads = [];

        foreach ($map->attributes as $name => $attribute) {
            if (! in_array($name, $external, true)) {
                $table = $map->owners[$name]->table;
                $column = $attribute->column();
                $stored = $column === null ? null : $this->baseType($table, $column);
                $reads[$name] = [$attribute->read($table, $stored, $this->columnCatalog->baseType(...)), $this->columnCatalog->baseType($attribute->type($stored)), ($attribute->conversions()[0] ?? null) instanceof ReadAs];
            }
        }

        $served = [];
        $collations = self::collations($this->connection, $map->table, $this->columnCatalog->throughSearchPath);

        foreach ($shape as $name => $column) {
            if (! array_key_exists($name, $reads)) {
                continue;
            }

            [$read, $type, $raw] = $reads[$name];

            if ($raw && SqlFragments::isNaive($column->baseType ?? $column->type)) {
                $type = $this->expressionType($map, $read, $retainedFrom ?? self::retainedTable($map));
            }

            $served[$name] = self::cast($read, $type, $column->type, $column->baseType, $collations[$name]['name'] ?? null);
        }

        return $served;
    }

    /**
     * @param  array<string, Column>  $shape
     * @param  list<string>  $external
     * @return array<string, string>
     */
    private function morphed(ResolvedMap $map, array $shape, array $external, null|string $retainedFrom): array
    {
        $external = [...$external, ...$map->externals()];
        $variants = array_map(fn (ResolvedMap $variant) => $this->served($variant, $shape, $external, $retainedFrom), $map->morphs);
        $collations = self::collations($this->connection, $map->table, $this->columnCatalog->throughSearchPath);
        $type = SqlFragments::identifier($map->primary->table).'.'.SqlFragments::identifier((string) $map->morph?->typeColumn);
        $served = [];

        foreach (array_values($variants)[0] ?? [] as $name => $read) {
            if (! $map->isMorphed($name)) {
                $served[$name] = $read;

                continue;
            }

            $cases = array_map(fn (ResolvedMap $variant, string $target) => 'WHEN '.SqlFragments::literal((string) $variant->target?->morphAlias).' THEN '.$variants[$target][$name], $map->morphs, array_keys($map->morphs));
            $served[$name] = self::cast('CASE '.$type.' '.implode(' ', $cases).' END', null, $shape[$name]->type, null, $collations[$name]['name'] ?? null);
        }

        return $served;
    }

    public static function cast(string $read, null|string $type, string $served, null|string $servedBase = null, null|string $collation = null): string
    {
        if (SqlFragments::isZoned($type) && SqlFragments::isNaive($servedBase ?? $served)) {
            $read = SqlFragments::inApplicationZone($read);
        }

        return 'CAST('.$read.' AS '.$served.')'.($collation === null ? '' : ' COLLATE '.$collation);
    }

    /**
     * @return array<string, array{name: string, deterministic: bool, declared: bool}>
     */
    public static function collations(ConnectionInterface $connection, string $table, bool $throughSearchPath = false): array
    {
        $collations = [];

        foreach ($connection->select(
            "select attribute.attname as name, quote_ident(collation_schema.nspname) || '.' || quote_ident(column_collation.collname) as collation,
                    column_collation.collisdeterministic as deterministic, attribute.attcollation <> column_type.typcollation as declared
             from pg_attribute attribute
             join pg_type column_type on column_type.oid = attribute.atttypid
             join pg_collation column_collation on column_collation.oid = attribute.attcollation
             join pg_namespace collation_schema on collation_schema.oid = column_collation.collnamespace
             where attribute.attrelid = ".($throughSearchPath ? 'to_regclass(?)' : "to_regclass(quote_ident(current_schema()) || '.' || ?)").' and attribute.attnum > 0 and not attribute.attisdropped',
            [SqlFragments::identifier($table)],
        ) as $row) {
            $collations[(string) $row->name] = ['name' => (string) $row->collation, 'deterministic' => $row->deterministic === true, 'declared' => $row->declared === true];
        }

        return $collations;
    }

    public static function retainedTable(ResolvedMap $map): string
    {
        return $map->retainedTable ?? throw InvalidCompatibilityMap::because($map->table, 'it declares no retainedIn(), and install() keeps the legacy table as the retained table');
    }

    public static function primary(ResolvedMap $map): string
    {
        $table = SqlFragments::identifier($map->primary->table);
        $conditions = array_map(
            callback: fn (string $condition) => 'NOT COALESCE(('.$condition.'), false)',
            array: self::hidden($map),
        );

        return $conditions === [] ? $table : '(SELECT * FROM '.$table.' WHERE '.implode(' AND ', $conditions).') AS '.$table;
    }

    /**
     * @return list<string>
     */
    public static function laterals(ResolvedMap $map, null|string $owner = null): array
    {
        $path = $owner === null ? null : self::path($map, $owner);
        $laterals = [];

        foreach ($map->related as $related) {
            if ($related->morphType !== null && ($path === null || in_array($related->table, $path, true))) {
                $laterals[] = 'LEFT JOIN '.SqlFragments::identifier($related->table).' ON '.implode(' AND ', self::conditions($related, true));
            } elseif ($path === null || in_array($related->table, $path, true)) {
                $laterals[] = 'LEFT JOIN LATERAL (SELECT * FROM '.SqlFragments::identifier($related->table).' WHERE '.implode(' AND ', self::conditions($related, true))
                    .($related->orders === null ? '' : ' ORDER BY '.$related->orders).' LIMIT 1) AS '.SqlFragments::identifier($related->table).' ON true';
            }
        }

        return $laterals;
    }

    /**
     * @return list<string>
     */
    public static function conditions(Related $related, bool $joined): array
    {
        $table = SqlFragments::identifier($related->table);
        $parent = SqlFragments::identifier($related->parentTable);
        $conditions = match (true) {
            ! $joined => [],
            $related->morphType !== null => [$parent.'.'.SqlFragments::identifier($related->morphType).' = '.SqlFragments::literal((string) $related->morphAlias), $parent.'.'.SqlFragments::identifier($related->parentKey).' = '.$table.'.'.SqlFragments::identifier($related->relatedKey)],
            default => [$table.'.'.SqlFragments::identifier($related->relatedKey).' = '.$parent.'.'.SqlFragments::identifier($related->parentKey)],
        };

        if ($related->conditions !== null) {
            $conditions[] = '('.SqlFragments::bind($related->conditions, $related->bindings).')';
        }

        if ($related->deletedAt !== null) {
            $conditions[] = $table.'.'.SqlFragments::identifier($related->deletedAt).' IS NULL';
        }

        return $conditions;
    }

    public static function root(ResolvedMap $map, string $retained, bool $hides = true): string
    {
        return 'JOIN '.($hides ? self::primary($map) : SqlFragments::identifier($map->primary->table)).' ON '.SqlFragments::identifier($map->primary->table).'.'.SqlFragments::identifier($map->primary->key).' = '.SqlFragments::identifier($retained).'.'.SqlFragments::identifier($map->successorKey);
    }

    /**
     * @param  array<string, Column>  $shape
     * @param  array<string, Column>  $retained
     * @param  list<string>  $external
     * @return array<string, string>
     */
    public function columns(ResolvedMap $map, array $shape, string $defaultsOn, array $retained, array $external = []): array
    {
        $served = $this->served($map, $shape, $external, $defaultsOn);
        $retainedTable = self::retainedTable($map);
        $legacyOnly = $map->legacyOnly(array_keys($shape));
        $columns = [];

        foreach ($shape as $name => $column) {
            if (array_key_exists($name, $served)) {
                $columns[$name] = $served[$name];

                continue;
            }

            if (! in_array($name, $legacyOnly, true)) {
                continue;
            }

            if (! array_key_exists($name, $retained)) {
                throw InvalidCompatibilityMap::because($map->table, "{$retainedTable} has no column {$name}, which {$map->table} keeps as a legacy-only column");
            }

            $columns[$name] = 'CAST('.SqlFragments::identifier($retainedTable).'.'.SqlFragments::identifier($name).' AS '.$column->type.')';
        }

        return $columns;
    }

    /**
     * @param  array<string, Column>  $shape
     * @param  array<string, Column>  $retained
     */
    private function select(ResolvedMap $map, array $shape, string $defaultsOn, array $retained, bool $hides = true, null|string $from = null): string
    {
        $retainedTable = self::retainedTable($map);

        if ($shape === []) {
            throw InvalidCompatibilityMap::because(
                map: $map->table,
                problem: "the legacy table {$map->table} does not exist",
            );
        }

        if (! array_key_exists(
            key: $map->successorKey,
            array: $shape,
        )) {
            throw InvalidCompatibilityMap::because(
                map: $map->table,
                problem: "{$map->table} has no column {$map->successorKey}, the successor key servedFrom() names; Compatibility::prepare() adds it before install()",
            );
        }

        $reads = $this->columns(
            map: $map,
            shape: $shape,
            defaultsOn: $defaultsOn,
            retained: $retained,
        );
        $columns = array_map(fn (string $read, string $name) => $read.' AS '.SqlFragments::identifier($name), $reads, array_keys($reads));

        return "SELECT\n  ".implode(",\n  ", $columns)
            ."\nFROM ".($from === null ? '' : SqlFragments::identifier($from).' AS ').SqlFragments::identifier($retainedTable)
            ."\n".self::root(
                map: $map,
                retained: $retainedTable,
                hides: $hides,
            )
            .implode('', array_map(fn (string $lateral) => "\n".$lateral, self::laterals($map)));
    }

    private function baseType(string $table, string $column): null|string
    {
        $stored = $this->columnCatalog->of($table)[$column] ?? null;

        return $stored === null ? null : $stored->baseType ?? $stored->type;
    }

    private function expressionType(ResolvedMap $map, string $read, string $retainedFrom): null|string
    {
        $retainedTable = self::retainedTable($map);
        $type = $this->connection->scalar(
            'SELECT CAST(pg_typeof(probe.value) AS text) FROM (SELECT) AS compatibility_row LEFT JOIN (SELECT '.$read.' AS value FROM '.self::primary($map)
                .' LEFT JOIN '.SqlFragments::identifier($retainedFrom).($retainedFrom === $retainedTable ? '' : ' AS '.SqlFragments::identifier($retainedTable)).' ON false'
                .implode('', array_map(fn (string $lateral) => ' '.$lateral, self::laterals($map))).' WHERE false) AS probe ON true',
        );

        return is_string($type) ? $this->columnCatalog->baseType($type) : null;
    }

    /**
     * @param  array<string, Column>  $shape
     * @param  list<string>  $external
     */
    private function check(ResolvedMap $map, array $shape, array $external): void
    {
        self::retainedTable($map);

        if ($shape === []) {
            throw InvalidCompatibilityMap::because($map->table, "the legacy table {$map->table} does not exist");
        }

        $this->columnsOf(
            map: $map,
            table: $map->primary->table,
            columns: [$map->primary->key],
            because: 'the view joins it on the successor key',
        );

        foreach ($map->related as $related) {
            $this->columnsOf($map, $related->parentTable, array_values(array_filter([$related->parentKey, $related->morphType], is_string(...))), 'the relation from '.class_basename($related->parent).' to '.class_basename($related->model).' joins on it');
            $this->columnsOf($map, $related->table, array_values(array_filter([$related->relatedKey, $related->deletedAt], fn (null|string $column) => $column !== null)), 'the relation from '.class_basename($related->parent).' to '.class_basename($related->model).' reads it');
        }

        foreach (array_diff(array_keys($map->attributes), $external) as $name) {
            if (! array_key_exists($name, $shape)) {
                throw InvalidCompatibilityMap::because($map->table, "{$map->table} has no column {$name}, so the view cannot serve it");
            }
        }

        foreach ($map->attributes as $name => $attribute) {
            $column = $attribute->column();

            if ($column === null || in_array($name, $external, true)) {
                continue;
            }

            $table = $map->owners[$name]->table;
            $stored = $this->columnsOf($map, $table, [$column], "attribute '{$name}' reads it")[$column];
            $type = $stored->baseType ?? $stored->type;

            foreach ($attribute->conversions() as $conversion) {
                if ($conversion instanceof InTimeZone && ($type === null || preg_match('/^(timestamp(\(\d\))? with time zone|timestamptz(\(\d\))?)$/i', $type) !== 1)) {
                    throw InvalidCompatibilityMap::because($map->table, "attribute '{$name}' reads {$table}.{$column} in the time zone {$conversion->zone}, and AT TIME ZONE reads a wall clock only from a timestamp with time zone, not from ".($type ?? 'the type the conversions before inTimeZone() give'));
                }

                $type = $conversion instanceof AsType ? $this->columnCatalog->baseType($conversion->type) : null;
            }
        }
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, Column>
     */
    private function columnsOf(ResolvedMap $map, string $table, array $columns, string $because): array
    {
        $catalog = $this->columnCatalog->of($table);

        if ($catalog === []) {
            throw InvalidCompatibilityMap::because($map->table, "the successor table {$table} does not exist");
        }

        foreach ($columns as $column) {
            if (! array_key_exists($column, $catalog)) {
                throw InvalidCompatibilityMap::because($map->table, "{$table} has no column {$column}, and {$because}");
            }
        }

        return $catalog;
    }

    /**
     * @return list<string>
     */
    public static function hidden(ResolvedMap $map): array
    {
        $conditions = [];

        foreach ($map->hides as $hide) {
            $query = (new $map->primary->model)->newModelQuery();
            ($hide->scope)($query);
            $base = $query->getQuery();
            $where = $base->getGrammar()->compileWheres($base);

            if ($where === '' || ($base->joins ?? []) !== [] || ($base->orders ?? []) !== [] || $base->limit !== null || ($base->groups ?? []) !== []) {
                throw InvalidCompatibilityMap::because($map->table, "a hide() scope compiles to no plain condition on {$map->primary->table}; it takes where clauses only, because \"{$hide->because}\" becomes the view's filter");
            }

            $conditions[] = SqlFragments::bind(Str::after($where, 'where '), SqlFragments::asSent(array_values($base->getRawBindings()['where']), $base->getGrammar()->getDateFormat()));
        }

        return $conditions;
    }

    /**
     * @return list<string>
     */
    private static function path(ResolvedMap $map, string $owner): array
    {
        $parents = [];

        foreach ($map->related as $related) {
            $parents[$related->table] = $related->parentTable;
        }

        $path = [];

        for ($table = $owner; array_key_exists($table, $parents); $table = $parents[$table]) {
            $path[] = $table;
        }

        return $path;
    }
}
