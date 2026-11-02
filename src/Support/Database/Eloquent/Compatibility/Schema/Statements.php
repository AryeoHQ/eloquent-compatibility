<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Illuminate\Database\ConnectionInterface;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;

final readonly class Statements
{
    private const array ACTIONS = ['r' => 'RESTRICT', 'c' => 'CASCADE', 'n' => 'SET NULL', 'd' => 'SET DEFAULT'];

    /**
     * @param  list<string>  $transaction
     * @param  list<string>  $afterwards
     */
    public function __construct(public string $operation, public array $transaction, public array $afterwards = []) {}

    public static function prepare(ResolvedMap $map, ConnectionInterface $connection): self
    {
        return (new Preparer(new ColumnCatalog($connection)))->statements($map);
    }

    public static function install(ResolvedMap $map, ConnectionInterface $connection): self
    {
        $columnCatalog = new ColumnCatalog($connection);
        $view = (new ViewCompiler($columnCatalog))->create($map);

        if (self::kind($connection, $map->table) !== 'r') {
            throw InvalidCompatibilityMap::because($map->table, "{$map->table} is not a plain table, so install() cannot serve it through a view; recompile() replaces an installed view");
        }

        $legacyTable = SqlFragments::identifier($map->table);
        $retainedTable = SqlFragments::identifier(ViewCompiler::retainedTable($map));
        $retainedKey = (string) $map->retainedKey;
        $primary = SqlFragments::identifier($map->primary->table);
        $primaryKey = SqlFragments::identifier($map->primary->key);
        $legacy = $columnCatalog->of($map->table);
        $key = (new $map->model)->getKeyName();

        if (array_key_exists($retainedKey, $legacy)) {
            throw InvalidCompatibilityMap::because($map->table, "{$map->table} already has a column {$retainedKey}, which install() adds as the key of the retained table");
        }

        $repointed = [];
        $statements = [self::refuseMissingPrimaries($map, $key)];

        foreach (self::foreignKeysTo($connection, $map->table) as $foreignKey) {
            if (array_key_exists($foreignKey->referencedColumn, $map->attributes)) {
                throw InvalidCompatibilityMap::because($map->table, "the foreign key {$foreignKey->name} on {$foreignKey->table} references {$map->table}.{$foreignKey->referencedColumn}, which install() moves to a successor; reference an identifier instead");
            }

            if (array_key_exists($foreignKey->referencedColumn, $map->identifiers)) {
                $statements[] = self::repoint($map, $foreignKey, $map->primary->table, (string) $map->identifiers[$foreignKey->referencedColumn]->column());
                $repointed[] = $foreignKey;
            }
        }

        foreach ($map->identifiers as $name => $identifier) {
            $sequence = $identifier->isInteger() ? self::sequence($connection, $map->table, $name) : null;

            if ($sequence !== null) {
                $statements[] = "ALTER SEQUENCE {$sequence} OWNED BY {$primary}.".SqlFragments::identifier((string) $identifier->column());
            }
        }

        $retainedPrimaryKey = SqlFragments::identifier(SqlFragments::objectName($map->table, ViewCompiler::retainedTable($map).'_pkey'));
        $retainedForeignKey = SqlFragments::identifier(SqlFragments::objectName($map->table, ViewCompiler::retainedTable($map).'_'.$retainedKey.'_foreign'));
        $mapped = array_values(array_diff(array_keys($legacy), $map->legacyOnly(array_keys($legacy))));
        $defaults = array_filter($legacy, fn (Column $column, string $name) => $column->default !== null && ! ($map->attributes[$name] ?? null)?->isExternal(), ARRAY_FILTER_USE_BOTH);

        $statements = [
            ...$statements,
            "ALTER TABLE {$legacyTable} ADD COLUMN ".SqlFragments::identifier($retainedKey).' '.$columnCatalog->of($map->primary->table)[$map->primary->key]->type,
            "UPDATE {$legacyTable} SET ".SqlFragments::identifier($retainedKey)." = {$primary}.{$primaryKey} FROM {$primary} WHERE {$primary}.".SqlFragments::identifier((string) $map->identifiers[$key]->column())." = {$legacyTable}.".SqlFragments::identifier($key),
            "ALTER TABLE {$legacyTable}\n  ".implode(",\n  ", array_map(fn (string $column) => 'DROP COLUMN '.SqlFragments::identifier($column), $mapped)),
            "ALTER TABLE {$legacyTable} RENAME TO {$retainedTable}",
            "ALTER TABLE {$retainedTable}\n  ADD CONSTRAINT {$retainedPrimaryKey} PRIMARY KEY (".SqlFragments::identifier($retainedKey)."),\n  ADD CONSTRAINT {$retainedForeignKey} FOREIGN KEY (".SqlFragments::identifier($retainedKey).") REFERENCES {$primary} ({$primaryKey}) ON DELETE CASCADE NOT VALID",
            $view,
            ...array_map(fn (Column $column, string $name) => "ALTER VIEW {$legacyTable} ALTER COLUMN ".SqlFragments::identifier($name).' SET DEFAULT '.$column->default, $defaults, array_keys($defaults)),
        ];

        return new self('install', $statements, [
            ...array_map(fn (object $foreignKey) => self::validate($foreignKey->qualifiedTable, $foreignKey->name), $repointed),
            self::validate($retainedTable, ViewCompiler::retainedTable($map).'_'.$retainedKey.'_foreign'),
        ]);
    }

    public static function recompile(ResolvedMap $map, ConnectionInterface $connection): self
    {
        if (self::kind($connection, $map->table) !== 'v') {
            throw InvalidCompatibilityMap::because($map->table, "{$map->table} is not a view, so recompile() has nothing to replace; install() creates it");
        }

        return new self('recompile', [(new ViewCompiler(new ColumnCatalog($connection)))->replace($map)]);
    }

    public static function uninstall(ResolvedMap $map, ConnectionInterface $connection): self
    {
        if (self::kind($connection, $map->table) !== 'v') {
            throw InvalidCompatibilityMap::because($map->table, "{$map->table} is not a view, so uninstall() has nothing to reverse");
        }

        $columnCatalog = new ColumnCatalog($connection);
        $shape = $columnCatalog->of($map->table);
        $served = (new ViewCompiler($columnCatalog))->served($map, $shape);
        $legacyTable = SqlFragments::identifier($map->table);
        $retainedKey = SqlFragments::identifier((string) $map->retainedKey);
        $primary = SqlFragments::identifier($map->primary->table);
        $primaryKey = SqlFragments::identifier($map->primary->key);
        $key = (new $map->model)->getKeyName();
        $defaults = array_filter(array_intersect_key($shape, $served), fn (Column $column) => $column->default !== null);
        $repointed = [];
        $columns = array_keys($served);

        $statements = [
            "DROP VIEW {$legacyTable}",
            'ALTER TABLE '.SqlFragments::identifier(ViewCompiler::retainedTable($map))." RENAME TO {$legacyTable}",
            "INSERT INTO {$legacyTable} ({$retainedKey}) SELECT {$primary}.{$primaryKey} FROM ".ViewCompiler::primary($map)." WHERE NOT EXISTS (SELECT FROM {$legacyTable} WHERE {$legacyTable}.{$retainedKey} = {$primary}.{$primaryKey})",
            "ALTER TABLE {$legacyTable}\n  ".implode(",\n  ", array_map(fn (string $column) => 'ADD COLUMN '.SqlFragments::identifier($column).' '.$shape[$column]->type, $columns)),
            "UPDATE {$legacyTable} SET\n  ".implode(",\n  ", array_map(fn (string $column) => SqlFragments::identifier($column).' = '.$served[$column], $columns))
                ."\nFROM {$primary}".implode('', array_map(fn (string $lateral) => "\n".$lateral, ViewCompiler::laterals($map)))
                ."\nWHERE {$primary}.{$primaryKey} = {$legacyTable}.{$retainedKey}",
        ];

        if ($defaults !== []) {
            $statements[] = "ALTER TABLE {$legacyTable}\n  ".implode(",\n  ", array_map(fn (Column $column, string $name) => 'ALTER COLUMN '.SqlFragments::identifier($name).' SET DEFAULT '.$column->default, $defaults, array_keys($defaults)));
        }

        foreach ($map->identifiers as $name => $identifier) {
            $sequence = $identifier->isInteger() ? self::sequence($connection, $map->primary->table, (string) $identifier->column()) : null;

            if ($sequence !== null) {
                $statements[] = "ALTER SEQUENCE {$sequence} OWNED BY {$legacyTable}.".SqlFragments::identifier($name);
            }
        }

        $constraints = ['ADD CONSTRAINT '.SqlFragments::identifier(SqlFragments::objectName($map->table, $map->table.'_pkey')).' PRIMARY KEY ('.SqlFragments::identifier($key).')'];

        foreach (array_keys($map->identifiers) as $name) {
            if ($name !== $key) {
                $constraints[] = 'ADD CONSTRAINT '.SqlFragments::identifier(SqlFragments::objectName($map->table, $map->table.'_'.$name.'_unique')).' UNIQUE ('.SqlFragments::identifier($name).')';
            }
        }

        $statements[] = "ALTER TABLE {$legacyTable} DROP COLUMN {$retainedKey}";
        $statements[] = "ALTER TABLE {$legacyTable}\n  ".implode(",\n  ", $constraints);
        $identifiers = [];

        foreach ($map->identifiers as $name => $identifier) {
            $identifiers[(string) $identifier->column()] = $name;
        }

        foreach (self::foreignKeysTo($connection, $map->primary->table) as $foreignKey) {
            if (array_key_exists($foreignKey->referencedColumn, $identifiers)) {
                $statements[] = self::repoint($map, $foreignKey, $map->table, $identifiers[$foreignKey->referencedColumn]);
                $repointed[] = $foreignKey;
            }
        }

        return new self('uninstall', $statements, array_map(fn (object $foreignKey) => self::validate($foreignKey->qualifiedTable, $foreignKey->name), $repointed));
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return [...$this->transaction, ...$this->afterwards];
    }

    private static function refuseMissingPrimaries(ResolvedMap $map, string $key): string
    {
        $legacyTable = SqlFragments::identifier($map->table);
        $primary = SqlFragments::identifier($map->primary->table);
        $matches = [];

        foreach ($map->identifiers as $name => $identifier) {
            $matches[] = "{$primary}.".SqlFragments::identifier((string) $identifier->column())." IS NOT DISTINCT FROM {$legacyTable}.".SqlFragments::identifier($name);
        }

        $missing = "FROM {$legacyTable} WHERE NOT EXISTS (SELECT FROM {$primary} WHERE ".implode(' AND ', $matches).')';
        $legacyKey = "{$legacyTable}.".SqlFragments::identifier($key);
        $identifiers = implode(' and ', array_keys($map->identifiers));
        $refusal = SqlFragments::literal("Compatibility map '{$map->table}' cannot install: ")
            .' || missing || '.SqlFragments::literal(" {$map->table} rows have no {$map->primary->table} row with the same {$identifiers}; the first are {$key} ")
            .' || first_keys || '.SqlFragments::literal('. Backfill them before install().');

        return "DO \$compatibility\$\nDECLARE\n  missing bigint;\n  first_keys text;\nBEGIN\n"
            ."  SELECT count(*) INTO missing {$missing};\n"
            ."  IF missing > 0 THEN\n"
            ."    SELECT string_agg(CAST(missing_key AS text), ', ' ORDER BY missing_key) INTO first_keys FROM (SELECT {$legacyKey} {$missing} ORDER BY {$legacyKey} LIMIT 5) AS first_missing (missing_key);\n"
            ."    RAISE EXCEPTION '%', {$refusal};\n"
            ."  END IF;\nEND\n\$compatibility\$";
    }

    private static function repoint(ResolvedMap $map, object $foreignKey, string $table, string $column): string
    {
        if ($foreignKey->columns !== 1) {
            throw InvalidCompatibilityMap::because($map->table, "the foreign key {$foreignKey->name} on {$foreignKey->table} spans {$foreignKey->columns} columns, and the package repoints a foreign key on one identifier");
        }

        $name = SqlFragments::identifier($foreignKey->name);

        return "ALTER TABLE {$foreignKey->qualifiedTable}\n  DROP CONSTRAINT {$name},\n  ADD CONSTRAINT {$name} FOREIGN KEY (".SqlFragments::identifier($foreignKey->column).') REFERENCES '.SqlFragments::identifier($table).' ('.SqlFragments::identifier($column).')'
            .($foreignKey->matchType === 'f' ? ' MATCH FULL' : '')
            .(array_key_exists($foreignKey->onUpdate, self::ACTIONS) ? ' ON UPDATE '.self::ACTIONS[$foreignKey->onUpdate] : '')
            .(array_key_exists($foreignKey->onDelete, self::ACTIONS) ? ' ON DELETE '.self::ACTIONS[$foreignKey->onDelete] : '')
            .($foreignKey->deferrable ? ' DEFERRABLE'.($foreignKey->deferred ? ' INITIALLY DEFERRED' : '') : '')
            .' NOT VALID';
    }

    private static function validate(string $table, string $constraint): string
    {
        return "ALTER TABLE {$table} VALIDATE CONSTRAINT ".SqlFragments::identifier($constraint);
    }

    /**
     * @return list<object{name: string, table: string, qualifiedTable: string, column: string, referencedColumn: string, columns: int, onUpdate: string, onDelete: string, matchType: string, deferrable: bool, deferred: bool}>
     */
    private static function foreignKeysTo(ConnectionInterface $connection, string $table): array
    {
        $rows = $connection->select(
            "select foreign_key.conname as name,
                    case when referencing_schema.nspname = current_schema() then null else referencing_schema.nspname end as schema,
                    referencing_table.relname as \"table\",
                    referencing_column.attname as \"column\",
                    referenced_column.attname as referenced_column,
                    cardinality(foreign_key.conkey) as columns,
                    foreign_key.confupdtype::text as on_update,
                    foreign_key.confdeltype::text as on_delete,
                    foreign_key.confmatchtype::text as match_type,
                    foreign_key.condeferrable as deferrable,
                    foreign_key.condeferred as deferred
             from pg_constraint foreign_key
             join pg_class referencing_table on referencing_table.oid = foreign_key.conrelid
             join pg_namespace referencing_schema on referencing_schema.oid = referencing_table.relnamespace
             join pg_attribute referencing_column on referencing_column.attrelid = foreign_key.conrelid and referencing_column.attnum = foreign_key.conkey[1]
             join pg_attribute referenced_column on referenced_column.attrelid = foreign_key.confrelid and referenced_column.attnum = foreign_key.confkey[1]
             where foreign_key.contype = 'f' and foreign_key.conparentid = 0
               and foreign_key.confrelid = to_regclass(quote_ident(current_schema()) || '.' || ?)
             order by referencing_schema.nspname, referencing_table.relname, foreign_key.conname",
            [SqlFragments::identifier($table)],
        );

        return array_values(array_map(fn (object $row) => (object) [
            'name' => (string) $row->name,
            'table' => (string) $row->table,
            'qualifiedTable' => ($row->schema === null ? '' : SqlFragments::identifier((string) $row->schema).'.').SqlFragments::identifier((string) $row->table),
            'column' => (string) $row->column,
            'referencedColumn' => (string) $row->referenced_column,
            'columns' => (int) $row->columns,
            'onUpdate' => (string) $row->on_update,
            'onDelete' => (string) $row->on_delete,
            'matchType' => (string) $row->match_type,
            'deferrable' => $row->deferrable === true,
            'deferred' => $row->deferred === true,
        ], $rows));
    }

    private static function sequence(ConnectionInterface $connection, string $table, string $column): null|string
    {
        $sequence = $connection->scalar('select pg_get_serial_sequence(?, ?)', [SqlFragments::identifier($table), $column]);

        return is_string($sequence) ? $sequence : null;
    }

    private static function kind(ConnectionInterface $connection, string $table): null|string
    {
        $kind = $connection->scalar("select relkind::text from pg_class where oid = to_regclass(quote_ident(current_schema()) || '.' || ?)", [SqlFragments::identifier($table)]);

        return is_string($kind) ? $kind : null;
    }
}
