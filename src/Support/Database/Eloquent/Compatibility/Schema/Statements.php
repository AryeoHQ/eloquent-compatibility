<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\Membership;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Write\ExternalAttributes;

final readonly class Statements
{
    private const array ACTIONS = ['r' => 'RESTRICT', 'c' => 'CASCADE', 'n' => 'SET NULL', 'd' => 'SET DEFAULT'];

    private const string CAPTURED = 'compatibility install';

    /**
     * @param  list<string>  $transaction
     * @param  list<string>  $afterwards
     * @param  array<int, list<Closure(ConnectionInterface): void>>  $steps
     */
    public function __construct(public string $operation, public array $transaction, public array $afterwards = [], public array $steps = []) {}

    public static function prepare(ResolvedMap $map, ConnectionInterface $connection): self
    {
        $preparer = new Preparer(new ColumnCatalog($connection));
        $prepared = array_map($preparer->statements(...), $map->branches === [] ? [$map] : array_values($map->branches));

        return new self('prepare', array_merge(...array_map(fn (self $statements) => $statements->transaction, $prepared)), array_merge(...array_map(fn (self $statements) => $statements->afterwards, $prepared)));
    }

    public static function install(ResolvedMap $map, ConnectionInterface $connection): self
    {
        self::refuseBranches($map, 'install');
        $columnCatalog = new ColumnCatalog($connection);
        $view = (new ViewCompiler($columnCatalog, $connection))->create($map);

        if (self::kind($connection, $map->table) !== 'r') {
            throw InvalidCompatibilityMap::because($map->table, "{$map->table} is not a plain table, so install() cannot serve it through a view; recompile() replaces an installed view");
        }

        if ($connection->scalar("select relrowsecurity or relforcerowsecurity from pg_class where oid = to_regclass(quote_ident(current_schema()) || '.' || ?)", [SqlFragments::identifier($map->table)]) === true) {
            throw InvalidCompatibilityMap::because($map->table, "{$map->table} enables row-level security, and the view would read its successors with its owner's privileges and serve rows a policy hides");
        }

        $legacyTable = SqlFragments::identifier($map->table);
        $retainedTable = SqlFragments::identifier(ViewCompiler::retainedTable($map));
        $retainedKey = (string) $map->retainedKey;
        $primary = SqlFragments::identifier($map->primary->table);
        $primaryKey = SqlFragments::identifier($map->primary->key);
        $legacy = $columnCatalog->of($map->table);
        $key = (new $map->model)->getKeyName();

        $external = array_values(array_filter($map->externals(), fn (string $name) => array_key_exists($name, $legacy)));

        foreach ($external as $name) {
            if ($legacy[$name]->generated) {
                throw InvalidCompatibilityMap::because($map->table, "{$map->table}.{$name} is a generated column, and attribute '{$name}' is external(), so its value would come from outside the database and nothing would keep it generated from its columns; serve it with readAs() and readOnly()");
            }
        }

        if (array_key_exists($retainedKey, $legacy)) {
            throw InvalidCompatibilityMap::because($map->table, "{$map->table} already has a column {$retainedKey}, which install() adds as the key of the retained table");
        }

        $membership = $map->membership;

        if ($membership !== null) {
            self::refuseSharedAlias($connection, $map, $membership);
        }

        $repoints = [];
        $validations = [];
        $moved = [];
        $constants = [];

        foreach (self::foreignKeysTo($connection, $map->table) as $foreignKey) {
            if (array_key_exists($foreignKey->referencedColumn, $map->attributes)) {
                throw InvalidCompatibilityMap::because($map->table, "the foreign key {$foreignKey->name} on {$foreignKey->table} references {$map->table}.{$foreignKey->referencedColumn}, which install() moves to a successor; reference an identifier instead");
            }

            if (array_key_exists($foreignKey->referencedColumn, $map->identifiers)) {
                $identifier = $map->identifiers[$foreignKey->referencedColumn];
                $installed = $foreignKey->qualifiedTable === $legacyTable ? $retainedTable : $foreignKey->qualifiedTable;
                $composite = null;

                if ($membership !== null) {
                    $constant = SqlFragments::objectName($map->table, $membership->alias.'_'.$membership->column);
                    $type = $columnCatalog->type($map->primary->table, $membership->column) ?? 'text';
                    $composite = [$constant, $membership->column];
                    $constants[$foreignKey->qualifiedTable.'.'.$constant] = [
                        "ALTER TABLE {$foreignKey->qualifiedTable} ADD COLUMN ".SqlFragments::identifier($constant)." {$type} GENERATED ALWAYS AS (CAST(".SqlFragments::literal($membership->alias)." AS {$type})) STORED",
                        [$installed, $constant],
                    ];
                }

                $repoints[] = self::repoint($map, $foreignKey, $foreignKey->qualifiedTable, $map->primary->table, (string) $identifier->column(), composite: $composite);
                $validations[] = self::validate($installed, $foreignKey->name);
                $moved[] = [$installed, $foreignKey->name];
            }
        }

        $sequences = [];

        foreach ($map->identifiers as $name => $identifier) {
            if ($membership !== null && $identifier->isInteger()) {
                if ($legacy[$name]->default !== Identifier::sharedDefault()) {
                    throw InvalidCompatibilityMap::because($map->table, "{$map->table}.{$name} still defaults to ".($legacy[$name]->default ?? 'nothing').', and Compatibility::prepare() moves it onto the sequence '.Identifier::SHARED_SEQUENCE.' before install()');
                }

                $sequences[] = Preparer::advance($map->table, $name);

                continue;
            }

            $sequence = $identifier->isInteger() ? self::sequence($connection, $map->table, $name) : null;

            if ($sequence !== null) {
                $sequences[] = "ALTER SEQUENCE {$sequence} OWNED BY {$primary}.".SqlFragments::identifier((string) $identifier->column());
            }
        }

        [$refuseOutsiders, $check, $validateCheck] = $membership === null ? [[], [], []] : self::membershipCheck($map, $membership, 'install');

        $retainedPrimaryKey = SqlFragments::identifier(SqlFragments::objectName($map->table, ViewCompiler::retainedTable($map).'_pkey'));
        $retainedForeignKey = SqlFragments::identifier(SqlFragments::objectName($map->table, ViewCompiler::retainedTable($map).'_'.$retainedKey.'_foreign'));
        $mapped = array_values(array_diff(array_keys($legacy), $map->legacyOnly(array_keys($legacy))));
        usort($mapped, fn (string $first, string $second) => $legacy[$second]->alwaysGenerated <=> $legacy[$first]->alwaysGenerated);
        $defaults = array_filter($legacy, fn (Column $column, string $name) => $column->default !== null && ! ($map->attributes[$name] ?? null)?->isExternal(), ARRAY_FILTER_USE_BOTH);
        $hidden = self::reportHidden($map, $key);

        return new self('install', [
            "LOCK TABLE {$legacyTable}, {$primary} IN SHARE ROW EXCLUSIVE MODE",
            self::refuseMissingPrimaries($map, $key),
            ...$refuseOutsiders,
            ...($hidden === null ? [] : [$hidden]),
            "ALTER TABLE {$legacyTable} ADD COLUMN ".SqlFragments::identifier($retainedKey).' '.$columnCatalog->of($map->primary->table)[$map->primary->key]->type,
            "UPDATE {$legacyTable} SET ".SqlFragments::identifier($retainedKey)." = {$primary}.{$primaryKey} FROM {$primary} WHERE {$primary}.".SqlFragments::identifier((string) $map->identifiers[$key]->column())." = {$legacyTable}.".SqlFragments::identifier($key)
                .($membership === null ? '' : ' AND '.$membership->of($map->primary->table)),
            ...array_column($constants, 0),
            ...$repoints,
            ...$sequences,
            "ALTER TABLE {$legacyTable}\n  ".implode(",\n  ", array_map(fn (string $column) => 'DROP COLUMN '.SqlFragments::identifier($column), $mapped)),
            "ALTER TABLE {$legacyTable} RENAME TO {$retainedTable}",
            "ALTER TABLE {$retainedTable}\n  ADD CONSTRAINT {$retainedPrimaryKey} PRIMARY KEY (".SqlFragments::identifier($retainedKey)."),\n  ADD CONSTRAINT {$retainedForeignKey} FOREIGN KEY (".SqlFragments::identifier($retainedKey).") REFERENCES {$primary} ({$primaryKey}) ON DELETE CASCADE NOT VALID",
            "COMMENT ON TABLE {$retainedTable} IS ".SqlFragments::literal(self::capture($connection, $map, $legacy, $mapped, $moved, array_column($constants, 1), $external)),
            $view,
            ...array_map(fn (Column $column, string $name) => "ALTER VIEW {$legacyTable} ALTER COLUMN ".SqlFragments::identifier($name).' SET DEFAULT '.$column->default, $defaults, array_keys($defaults)),
            ...self::grants($connection, $map->table, $legacyTable, array_keys(array_filter($legacy, fn (Column $column, string $name) => ! ($map->attributes[$name] ?? null)?->isExternal(), ARRAY_FILTER_USE_BOTH))),
            ...$check,
        ], [
            ...$validations,
            self::validate($retainedTable, ViewCompiler::retainedTable($map).'_'.$retainedKey.'_foreign'),
            ...$validateCheck,
        ], $external === [] ? [] : [1 => array_map(fn (string $name) => fn (ConnectionInterface $connection) => ExternalAttributes::verify($map, $connection, $name), $external)]);
    }

    public static function recompile(ResolvedMap $map, ConnectionInterface $connection): self
    {
        self::refuseBranches($map, 'recompile');

        if (self::kind($connection, $map->table) !== 'v') {
            throw InvalidCompatibilityMap::because($map->table, "{$map->table} is not a view, so recompile() has nothing to replace; install() creates it");
        }

        $view = (new ViewCompiler(new ColumnCatalog($connection), $connection))->replace($map);
        $retainedTable = SqlFragments::identifier(ViewCompiler::retainedTable($map));
        $identifiers = array_map(fn ($identifier) => (string) $identifier->column(), array_values($map->identifiers));
        $validations = [];

        foreach (self::foreignKeysTo($connection, $map->primary->table) as $foreignKey) {
            $retained = $foreignKey->qualifiedTable === $retainedTable && $foreignKey->column === $map->retainedKey && $foreignKey->referencedColumn === $map->primary->key;

            if (! $foreignKey->validated && ($retained || in_array($foreignKey->referencedColumn, $identifiers, true))) {
                $validations[] = self::validate($foreignKey->qualifiedTable, $foreignKey->name);
            }
        }

        $membership = $map->membership;

        if ($membership === null) {
            return new self('recompile', [$view], $validations);
        }

        $primary = SqlFragments::identifier($map->primary->table);
        [$refuseOutsiders, $check, $validateCheck] = self::membershipCheck($map, $membership, 'recompile');

        return new self('recompile', [
            "LOCK TABLE {$primary} IN SHARE ROW EXCLUSIVE MODE",
            ...$refuseOutsiders,
            $view,
            ...($check === [] ? ["ALTER TABLE {$primary} DROP CONSTRAINT IF EXISTS ".SqlFragments::identifier($membership->constraint())] : $check),
        ], [...$validations, ...$validateCheck]);
    }

    public static function uninstall(ResolvedMap $map, ConnectionInterface $connection): self
    {
        self::refuseBranches($map, 'uninstall');

        if (self::kind($connection, $map->table) !== 'v') {
            throw InvalidCompatibilityMap::because($map->table, "{$map->table} is not a view, so uninstall() has nothing to reverse");
        }

        $columnCatalog = new ColumnCatalog($connection);
        $shape = $columnCatalog->of($map->table);
        $served = (new ViewCompiler($columnCatalog, $connection))->served($map, $shape);
        $legacyTable = SqlFragments::identifier($map->table);
        $retainedTable = SqlFragments::identifier(ViewCompiler::retainedTable($map));
        $retainedKey = SqlFragments::identifier((string) $map->retainedKey);
        $primary = SqlFragments::identifier($map->primary->table);
        $primaryKey = SqlFragments::identifier($map->primary->key);
        $key = (new $map->model)->getKeyName();
        $captured = self::captured($connection, ViewCompiler::retainedTable($map));
        $generated = $captured === null ? [] : array_intersect_key($captured['generated'], $served);
        $columns = array_keys(array_diff_key($served, $generated));
        $defaults = array_filter(array_intersect_key($shape, array_flip($columns)), fn (Column $column) => $column->default !== null);
        $external = $captured === null ? [] : array_intersect_key($captured['external'], array_flip($map->externals()));
        $repointed = [];

        $membership = $map->membership;
        $statements = [
            "LOCK TABLE {$primary} IN SHARE ROW EXCLUSIVE MODE",
            "DROP VIEW {$legacyTable}",
            "ALTER TABLE {$retainedTable} RENAME TO {$legacyTable}",
            "INSERT INTO {$legacyTable} ({$retainedKey}) SELECT {$primary}.{$primaryKey} FROM {$primary} WHERE "
                .($membership === null ? '' : $membership->of($map->primary->table).' AND ')
                ."NOT EXISTS (SELECT FROM {$legacyTable} WHERE {$legacyTable}.{$retainedKey} = {$primary}.{$primaryKey})",
            "ALTER TABLE {$legacyTable}\n  ".implode(",\n  ", [
                ...array_map(fn (string $column) => 'ADD COLUMN '.SqlFragments::identifier($column).' '.$shape[$column]->type, $columns),
                ...array_map(fn (array $column, string $name) => 'ADD COLUMN '.SqlFragments::identifier($name).' '.$column['type'], $external, array_keys($external)),
            ]),
            "UPDATE {$legacyTable} SET\n  ".implode(",\n  ", array_map(fn (string $column) => SqlFragments::identifier($column).' = '.$served[$column], $columns))
                ."\nFROM {$primary}".implode('', array_map(fn (string $lateral) => "\n".$lateral, ViewCompiler::laterals($map)))
                ."\nWHERE {$primary}.{$primaryKey} = {$legacyTable}.{$retainedKey}",
        ];

        $restoredDefaults = [...array_map(fn (Column $column) => (string) $column->default, $defaults), ...array_filter(array_map(fn (array $column) => $column['default'], $external), is_string(...))];

        if ($restoredDefaults !== []) {
            $statements[] = "ALTER TABLE {$legacyTable}\n  ".implode(",\n  ", array_map(fn (string $default, string $name) => 'ALTER COLUMN '.SqlFragments::identifier($name).' SET DEFAULT '.$default, $restoredDefaults, array_keys($restoredDefaults)));
        }

        if ($generated !== []) {
            $statements[] = "ALTER TABLE {$legacyTable}\n  ".implode(",\n  ", array_map(fn (string $clause, string $name) => 'ADD COLUMN '.SqlFragments::identifier($name).' '.$shape[$name]->type.' '.$clause, $generated, array_keys($generated)));
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
        [$replayed, $validations] = $captured === null ? [[], []] : self::replay($captured, $legacyTable, [...array_keys($served), ...array_keys($external)]);
        $statements = [...$statements, ...$replayed];
        $identifiers = [];

        foreach ($map->identifiers as $name => $identifier) {
            $identifiers[(string) $identifier->column()] = $name;
        }

        $constants = $captured === null ? [] : $captured['constants'];

        foreach (self::foreignKeysTo($connection, $map->primary->table) as $foreignKey) {
            $composite = in_array([$foreignKey->qualifiedTable, $foreignKey->referencingColumns[0]], $constants, true);

            if (array_key_exists($foreignKey->referencedColumn, $identifiers) && ($captured === null || in_array([$foreignKey->qualifiedTable, $foreignKey->name], $captured['moved'], true))) {
                $on = $foreignKey->qualifiedTable === $retainedTable ? $legacyTable : $foreignKey->qualifiedTable;
                $statements[] = self::repoint($map, $foreignKey, $on, $map->table, $identifiers[$foreignKey->referencedColumn], columns: $composite ? 2 : 1);
                $repointed[] = self::validate($on, $foreignKey->name);
            }
        }

        foreach ($constants as [$table, $constant]) {
            $statements[] = 'ALTER TABLE '.($table === $retainedTable ? $legacyTable : $table).' DROP COLUMN '.SqlFragments::identifier($constant);
        }

        if ($membership !== null) {
            $statements[] = "ALTER TABLE {$primary} DROP CONSTRAINT IF EXISTS ".SqlFragments::identifier($membership->constraint());
        }

        return new self('uninstall', $statements, [...$repointed, ...$validations], $external === [] ? [] : [5 => array_map(fn (array $column, string $name) => fn (ConnectionInterface $connection) => ExternalAttributes::restore($map, $connection, $name, $column['type']), $external, array_keys($external))]);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return [...$this->transaction, ...$this->afterwards];
    }

    public static function refuseBranches(ResolvedMap $map, string $operation): void
    {
        if ($map->branches !== []) {
            throw InvalidCompatibilityMap::cannot($map->table, $operation, 'it serves '.$map->table.' from '.implode(' and ', array_map(class_basename(...), array_keys($map->branches))).", and {$operation}() serves a map from one successor only");
        }
    }

    private static function refuseMissingPrimaries(ResolvedMap $map, string $key): string
    {
        $legacyTable = SqlFragments::identifier($map->table);
        $primary = SqlFragments::identifier($map->primary->table);
        $matches = [];

        foreach ($map->identifiers as $name => $identifier) {
            $matches[] = "{$primary}.".SqlFragments::identifier((string) $identifier->column()).($name === $key ? ' = ' : ' IS NOT DISTINCT FROM ')."{$legacyTable}.".SqlFragments::identifier($name);
        }

        if ($map->membership !== null) {
            $matches[] = $map->membership->of($map->primary->table);
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

    private static function reportHidden(ResolvedMap $map, string $key): null|string
    {
        $conditions = ViewCompiler::hidden($map);

        if ($conditions === []) {
            return null;
        }

        $legacyTable = SqlFragments::identifier($map->table);
        $primary = SqlFragments::identifier($map->primary->table);
        $legacyKey = "{$legacyTable}.".SqlFragments::identifier($key);
        $hidden = "FROM {$legacyTable} WHERE EXISTS (SELECT FROM {$primary} WHERE {$primary}.".SqlFragments::identifier((string) $map->identifiers[$key]->column())." = {$legacyKey} AND "
            .($map->membership === null ? '' : $map->membership->of($map->primary->table).' AND ').'('
            .implode(' OR ', array_map(fn (string $condition) => 'COALESCE(('.$condition.'), false)', $conditions)).'))';
        $notice = SqlFragments::literal("Compatibility map '{$map->table}' install hides ")
            .' || hidden_rows || '.SqlFragments::literal(" {$map->table} rows, because a hide() rule matches their {$map->primary->table} row; the first are {$key} ")
            .' || first_keys || '.SqlFragments::literal('.');

        return "DO \$compatibility\$\nDECLARE\n  hidden_rows bigint;\n  first_keys text;\nBEGIN\n"
            ."  SELECT count(*) INTO hidden_rows {$hidden};\n"
            ."  IF hidden_rows > 0 THEN\n"
            ."    SELECT string_agg(CAST(hidden_key AS text), ', ' ORDER BY hidden_key) INTO first_keys FROM (SELECT {$legacyKey} {$hidden} ORDER BY {$legacyKey} LIMIT 5) AS first_hidden (hidden_key);\n"
            ."    RAISE NOTICE '%', {$notice};\n"
            ."  END IF;\nEND\n\$compatibility\$";
    }

    private static function refuseSharedAlias(ConnectionInterface $connection, ResolvedMap $map, Membership $membership): void
    {
        $claim = ['table' => $membership->table, 'column' => $membership->column, 'alias' => $membership->alias];

        foreach ($connection->select(
            "select relation.relname as name, obj_description(relation.oid, 'pg_class') as comment
             from pg_class relation
             where relation.relnamespace = (select oid from pg_namespace where nspname = current_schema()) and relation.relkind = 'r'
               and obj_description(relation.oid, 'pg_class') like ?
             order by relation.relname",
            ['{"captured":"'.self::CAPTURED.'"%'],
        ) as $row) {
            $captured = json_decode((string) $row->comment, true);

            if ((string) $row->name !== ViewCompiler::retainedTable($map) && is_array($captured) && ($captured['membership'] ?? null) === $claim) {
                throw InvalidCompatibilityMap::because($map->table, "the installed map retained in {$row->name} already serves the {$membership->table} rows whose {$membership->column} is '{$membership->alias}', and two installed maps cannot share a morph alias");
            }
        }
    }

    /**
     * @return array{list<string>, list<string>, list<string>}
     */
    private static function membershipCheck(ResolvedMap $map, Membership $membership, string $operation): array
    {
        $check = $membership->check();

        if ($check === null) {
            return [[], [], []];
        }

        $primary = SqlFragments::identifier($map->primary->table);
        $primaryKey = SqlFragments::identifier($map->primary->key);
        $constraint = SqlFragments::identifier($membership->constraint());
        $outside = "FROM {$primary} WHERE NOT ({$check})";
        $refusal = SqlFragments::literal("Compatibility map '{$map->table}' cannot {$operation}: ")
            .' || outside || '.SqlFragments::literal(" {$map->primary->table} rows carry {$membership->column} '{$membership->alias}' and fail its when(); the first are {$map->primary->key} ")
            .' || first_keys || '.SqlFragments::literal(". Correct their {$membership->column} before {$operation}().");

        return [
            ["DO \$compatibility\$\nDECLARE\n  outside bigint;\n  first_keys text;\nBEGIN\n"
                ."  SELECT count(*) INTO outside {$outside};\n"
                ."  IF outside > 0 THEN\n"
                ."    SELECT string_agg(CAST(outside_key AS text), ', ' ORDER BY outside_key) INTO first_keys FROM (SELECT {$primary}.{$primaryKey} {$outside} ORDER BY {$primary}.{$primaryKey} LIMIT 5) AS first_outside (outside_key);\n"
                ."    RAISE EXCEPTION '%', {$refusal};\n"
                ."  END IF;\nEND\n\$compatibility\$"],
            ["ALTER TABLE {$primary}\n  DROP CONSTRAINT IF EXISTS {$constraint},\n  ADD CONSTRAINT {$constraint} CHECK ({$check}) NOT VALID"],
            [self::validate($primary, $membership->constraint())],
        ];
    }

    /**
     * @param  array<string, Column>  $legacy
     * @param  list<string>  $mapped
     * @param  list<array{string, string}>  $moved
     * @param  list<array{string, string}>  $constants
     * @param  list<string>  $external
     */
    private static function capture(ConnectionInterface $connection, ResolvedMap $map, array $legacy, array $mapped, array $moved, array $constants, array $external): string
    {
        $table = SqlFragments::identifier($map->table);
        $relation = "to_regclass(quote_ident(current_schema()) || '.' || ?)";
        $columns = json_encode($mapped, JSON_THROW_ON_ERROR);
        $generated = [];

        foreach ($connection->select(
            "select attribute.attname as name, attribute.attgenerated::text as kind, pg_get_expr(column_default.adbin, column_default.adrelid) as expression
             from pg_attribute attribute
             join pg_attrdef column_default on column_default.adrelid = attribute.attrelid and column_default.adnum = attribute.attnum
             where attribute.attrelid = {$relation} and attribute.attgenerated <> '' and attribute.attname in (select jsonb_array_elements_text(cast(? as jsonb)))
             order by attribute.attnum",
            [$table, $columns],
        ) as $row) {
            $generated[(string) $row->name] = 'GENERATED ALWAYS AS ('.$row->expression.') '.($row->kind === 'v' ? 'VIRTUAL' : 'STORED');
        }

        $constraints = array_map(fn (object $row) => ['name' => (string) $row->name, 'type' => (string) $row->type, 'definition' => (string) $row->definition, 'validated' => $row->validated === true], $connection->select(
            "select constraint_row.conname as name, constraint_row.contype::text as type, pg_get_constraintdef(constraint_row.oid) as definition, constraint_row.convalidated as validated
             from pg_constraint constraint_row
             where constraint_row.conrelid = {$relation} and constraint_row.contype in ('c', 'f', 'u', 'x')
               and constraint_row.conkey && array(select attnum from pg_attribute where attrelid = constraint_row.conrelid and attname in (select jsonb_array_elements_text(cast(? as jsonb))))
               and not (constraint_row.contype = 'u' and cardinality(constraint_row.conkey) = 1 and constraint_row.conkey[1] in (select attnum from pg_attribute where attrelid = constraint_row.conrelid and attname in (select jsonb_array_elements_text(cast(? as jsonb)))))
             order by constraint_row.conname",
            [$table, $columns, json_encode(array_keys($map->identifiers), JSON_THROW_ON_ERROR)],
        ));

        $indexes = array_map(fn (object $row) => (string) $row->definition, $connection->select(
            "select pg_get_indexdef(table_index.indexrelid) as definition
             from pg_index table_index
             where table_index.indrelid = {$relation}
               and not exists (select from pg_constraint where conrelid = table_index.indrelid and conindid = table_index.indexrelid)
               and exists (
                   select from pg_depend
                   where classid = 'pg_class'::regclass and objid = table_index.indexrelid and refobjid = table_index.indrelid
                     and refobjsubid in (select attnum from pg_attribute where attrelid = table_index.indrelid and attname in (select jsonb_array_elements_text(cast(? as jsonb))))
               )
             order by table_index.indexrelid::regclass::text",
            [$table, $columns],
        ));

        return json_encode([
            'captured' => self::CAPTURED,
            'comment' => $connection->scalar("select obj_description({$relation}, 'pg_class')", [$table]),
            'notNull' => array_values(array_filter($mapped, fn (string $column) => $legacy[$column]->notNull)),
            'generated' => $generated,
            'constraints' => array_values($constraints),
            'indexes' => array_values($indexes),
            'moved' => $moved,
            ...($map->membership === null ? [] : [
                'constants' => $constants,
                'membership' => ['table' => $map->membership->table, 'column' => $map->membership->column, 'alias' => $map->membership->alias],
            ]),
            ...($external === [] ? [] : ['external' => array_combine($external, array_map(fn (string $name) => ['type' => $legacy[$name]->type, 'default' => $legacy[$name]->default], $external))]),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<array{string, string}>
     */
    private static function pairs(mixed $pairs): array
    {
        $parsed = [];

        foreach (is_array($pairs) ? $pairs : [] as $pair) {
            if (is_array($pair) && is_string($pair[0] ?? null) && is_string($pair[1] ?? null)) {
                $parsed[] = [$pair[0], $pair[1]];
            }
        }

        return $parsed;
    }

    /**
     * @return null|array{comment: null|string, notNull: list<string>, generated: array<string, string>, constraints: list<array{name: string, type: string, definition: string, validated: bool}>, indexes: list<string>, moved: list<array{string, string}>, constants: list<array{string, string}>, external: array<string, array{type: string, default: null|string}>}
     */
    private static function captured(ConnectionInterface $connection, string $table): null|array
    {
        $comment = $connection->scalar("select obj_description(to_regclass(quote_ident(current_schema()) || '.' || ?), 'pg_class')", [SqlFragments::identifier($table)]);
        $captured = is_string($comment) ? json_decode($comment, true) : null;

        if (! is_array($captured) || ($captured['captured'] ?? null) !== self::CAPTURED) {
            return null;
        }

        $generated = [];

        foreach (is_array($captured['generated'] ?? null) ? $captured['generated'] : [] as $name => $clause) {
            if (is_string($clause)) {
                $generated[(string) $name] = $clause;
            }
        }

        $constraints = [];

        foreach (is_array($captured['constraints'] ?? null) ? $captured['constraints'] : [] as $constraint) {
            if (is_array($constraint) && is_string($constraint['name'] ?? null) && is_string($constraint['type'] ?? null) && is_string($constraint['definition'] ?? null)) {
                $constraints[] = ['name' => $constraint['name'], 'type' => $constraint['type'], 'definition' => $constraint['definition'], 'validated' => ($constraint['validated'] ?? false) === true];
            }
        }

        $external = [];

        foreach (is_array($captured['external'] ?? null) ? $captured['external'] : [] as $name => $column) {
            if (is_string($name) && is_array($column) && is_string($column['type'] ?? null)) {
                $external[$name] = ['type' => $column['type'], 'default' => is_string($column['default'] ?? null) ? $column['default'] : null];
            }
        }

        return [
            'comment' => is_string($captured['comment'] ?? null) ? $captured['comment'] : null,
            'notNull' => array_values(array_filter(is_array($captured['notNull'] ?? null) ? $captured['notNull'] : [], is_string(...))),
            'generated' => $generated,
            'constraints' => $constraints,
            'indexes' => array_values(array_filter(is_array($captured['indexes'] ?? null) ? $captured['indexes'] : [], is_string(...))),
            'moved' => self::pairs($captured['moved'] ?? null),
            'constants' => self::pairs($captured['constants'] ?? null),
            'external' => $external,
        ];
    }

    /**
     * @param  array{comment: null|string, notNull: list<string>, generated: array<string, string>, constraints: list<array{name: string, type: string, definition: string, validated: bool}>, indexes: list<string>, moved: list<array{string, string}>, constants: list<array{string, string}>, external: array<string, array{type: string, default: null|string}>}  $captured
     * @param  list<string>  $served
     * @return array{list<string>, list<string>}
     */
    private static function replay(array $captured, string $legacyTable, array $served): array
    {
        $statements = [];
        $validations = [];
        $notNull = array_values(array_intersect($captured['notNull'], $served));

        if ($notNull !== []) {
            $statements[] = "ALTER TABLE {$legacyTable}\n  ".implode(",\n  ", array_map(fn (string $column) => 'ALTER COLUMN '.SqlFragments::identifier($column).' SET NOT NULL', $notNull));
        }

        foreach ($captured['constraints'] as $constraint) {
            $deferred = in_array($constraint['type'], ['c', 'f'], true) && $constraint['validated'];
            $statements[] = "ALTER TABLE {$legacyTable} ADD CONSTRAINT ".SqlFragments::identifier($constraint['name']).' '.$constraint['definition'].($deferred ? ' NOT VALID' : '');

            if ($deferred) {
                $validations[] = self::validate($legacyTable, $constraint['name']);
            }
        }

        return [
            [...$statements, ...$captured['indexes'], "COMMENT ON TABLE {$legacyTable} IS ".($captured['comment'] === null ? 'NULL' : SqlFragments::literal($captured['comment']))],
            $validations,
        ];
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private static function grants(ConnectionInterface $connection, string $table, string $view, array $columns): array
    {
        $rows = $connection->select(
            "select case when granted.grantee = 0 then 'PUBLIC' else quote_ident(pg_get_userbyid(granted.grantee)) end as grantee,
                    string_agg(granted.privilege_type, ', ' order by granted.privilege_type) as privileges,
                    granted.is_grantable or granted.grantee = relation.relowner as grantable
             from pg_class relation
             cross join lateral aclexplode(coalesce(relation.relacl, acldefault('r', relation.relowner))) as granted
             where relation.oid = to_regclass(quote_ident(current_schema()) || '.' || ?) and granted.grantee <> (select oid from pg_roles where rolname = current_user)
             group by granted.grantee, granted.is_grantable, relation.relowner
             order by 1, 3",
            [SqlFragments::identifier($table)],
        );

        $columnRows = $connection->select(
            "select string_agg(granted.privilege_type || ' (' || quote_ident(attribute.attname) || ')', ', ' order by attribute.attnum, granted.privilege_type) as privileges,
                    case when granted.grantee = 0 then 'PUBLIC' else quote_ident(pg_get_userbyid(granted.grantee)) end as grantee,
                    granted.is_grantable as grantable
             from pg_attribute attribute
             cross join lateral aclexplode(attribute.attacl) as granted
             where attribute.attrelid = to_regclass(quote_ident(current_schema()) || '.' || ?) and attribute.attnum > 0 and not attribute.attisdropped
               and granted.grantee <> (select oid from pg_roles where rolname = current_user)
               and attribute.attname in (select jsonb_array_elements_text(cast(? as jsonb)))
             group by granted.grantee, granted.is_grantable
             order by 2, 3",
            [SqlFragments::identifier($table), json_encode($columns, JSON_THROW_ON_ERROR)],
        );

        return array_values(array_map(fn (object $row) => 'GRANT '.$row->privileges.' ON '.$view.' TO '.$row->grantee.($row->grantable === true ? ' WITH GRANT OPTION' : ''), [...$rows, ...$columnRows]));
    }

    /**
     * @param  null|array{string, string}  $composite
     */
    private static function repoint(ResolvedMap $map, object $foreignKey, string $on, string $table, string $column, int $columns = 1, null|array $composite = null): string
    {
        if ($foreignKey->columns !== $columns) {
            throw InvalidCompatibilityMap::because($map->table, "the foreign key {$foreignKey->name} on {$foreignKey->table} spans {$foreignKey->columns} columns, and the package repoints a foreign key on one identifier");
        }

        if ($composite !== null && (in_array($foreignKey->onUpdate, ['c', 'n', 'd'], true) || in_array($foreignKey->onDelete, ['n', 'd'], true))) {
            $action = in_array($foreignKey->onUpdate, ['c', 'n', 'd'], true) ? 'ON UPDATE '.self::ACTIONS[$foreignKey->onUpdate] : 'ON DELETE '.self::ACTIONS[$foreignKey->onDelete];

            throw InvalidCompatibilityMap::because($map->table, "the foreign key {$foreignKey->name} on {$foreignKey->table} takes {$action}, and install() repoints it onto ({$composite[1]}, {$column}) through the generated column {$composite[0]}, which no referential action may change");
        }

        $name = SqlFragments::identifier($foreignKey->name);
        $referencing = array_map(SqlFragments::identifier(...), $composite === null ? [$foreignKey->column] : [$composite[0], $foreignKey->column]);
        $referenced = array_map(SqlFragments::identifier(...), $composite === null ? [$column] : [$composite[1], $column]);

        return "ALTER TABLE {$on}\n  DROP CONSTRAINT {$name},\n  ADD CONSTRAINT {$name} FOREIGN KEY (".implode(', ', $referencing).') REFERENCES '.SqlFragments::identifier($table).' ('.implode(', ', $referenced).')'
            .($foreignKey->matchType === 'f' && $columns === 1 && $composite === null ? ' MATCH FULL' : '')
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
     * @return list<object{name: string, table: string, qualifiedTable: string, column: string, referencingColumns: list<string>, referencedColumn: string, columns: int, onUpdate: string, onDelete: string, matchType: string, deferrable: bool, deferred: bool, validated: bool}>
     */
    private static function foreignKeysTo(ConnectionInterface $connection, string $table): array
    {
        $rows = $connection->select(
            "select foreign_key.conname as name,
                    case when referencing_schema.nspname = current_schema() then null else referencing_schema.nspname end as schema,
                    referencing_table.relname as \"table\",
                    referencing_column.attname as \"column\",
                    (select json_agg(attribute.attname order by key_column.position)
                     from unnest(foreign_key.conkey) with ordinality as key_column (attnum, position)
                     join pg_attribute attribute on attribute.attrelid = foreign_key.conrelid and attribute.attnum = key_column.attnum) as referencing_columns,
                    referenced_column.attname as referenced_column,
                    cardinality(foreign_key.conkey) as columns,
                    foreign_key.confupdtype::text as on_update,
                    foreign_key.confdeltype::text as on_delete,
                    foreign_key.confmatchtype::text as match_type,
                    foreign_key.condeferrable as deferrable,
                    foreign_key.condeferred as deferred,
                    foreign_key.convalidated as validated
             from pg_constraint foreign_key
             join pg_class referencing_table on referencing_table.oid = foreign_key.conrelid
             join pg_namespace referencing_schema on referencing_schema.oid = referencing_table.relnamespace
             join pg_attribute referencing_column on referencing_column.attrelid = foreign_key.conrelid and referencing_column.attnum = foreign_key.conkey[cardinality(foreign_key.conkey)]
             join pg_attribute referenced_column on referenced_column.attrelid = foreign_key.confrelid and referenced_column.attnum = foreign_key.confkey[cardinality(foreign_key.confkey)]
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
            'referencingColumns' => array_values(array_map(strval(...), (array) json_decode((string) $row->referencing_columns, true))),
            'referencedColumn' => (string) $row->referenced_column,
            'columns' => (int) $row->columns,
            'onUpdate' => (string) $row->on_update,
            'onDelete' => (string) $row->on_delete,
            'matchType' => (string) $row->match_type,
            'deferrable' => $row->deferrable === true,
            'deferred' => $row->deferred === true,
            'validated' => $row->validated === true,
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
