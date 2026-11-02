<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Illuminate\Database\ConnectionInterface;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;

final readonly class Statements
{
    private const array ACTIONS = ['r' => 'RESTRICT', 'c' => 'CASCADE', 'n' => 'SET NULL', 'd' => 'SET DEFAULT'];

    private const string CAPTURED = 'compatibility install';

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

        foreach ($map->attributes as $name => $attribute) {
            if ($attribute->isExternal() && array_key_exists($name, $legacy)) {
                throw InvalidCompatibilityMap::because($map->table, "attribute '{$name}' is external(), and this release neither reads nor restores an external attribute, so install() would drop {$map->table}.{$name} and its values");
            }
        }

        if (array_key_exists($retainedKey, $legacy)) {
            throw InvalidCompatibilityMap::because($map->table, "{$map->table} already has a column {$retainedKey}, which install() adds as the key of the retained table");
        }

        $repoints = [];
        $validations = [];
        $moved = [];
        $notValid = [];

        foreach (self::foreignKeysTo($connection, $map->table) as $foreignKey) {
            if (array_key_exists($foreignKey->referencedColumn, $map->attributes)) {
                throw InvalidCompatibilityMap::because($map->table, "the foreign key {$foreignKey->name} on {$foreignKey->table} references {$map->table}.{$foreignKey->referencedColumn}, which install() moves to a successor; reference an identifier instead");
            }

            if (array_key_exists($foreignKey->referencedColumn, $map->identifiers)) {
                $on = $foreignKey->qualifiedTable === $legacyTable ? $retainedTable : $foreignKey->qualifiedTable;
                $repoints[] = self::repoint($map, $foreignKey, $foreignKey->qualifiedTable, $map->primary->table, (string) $map->identifiers[$foreignKey->referencedColumn]->column());
                $moved[] = [$on, $foreignKey->name];

                if ($foreignKey->validated) {
                    $validations[] = self::validate($on, $foreignKey->name);
                } else {
                    $notValid[] = [$on, $foreignKey->name];
                }
            }
        }

        $sequences = [];

        foreach ($map->identifiers as $name => $identifier) {
            $sequence = $identifier->isInteger() ? self::sequence($connection, $map->table, $name) : null;

            if ($sequence !== null) {
                $sequences[] = "ALTER SEQUENCE {$sequence} OWNED BY {$primary}.".SqlFragments::identifier((string) $identifier->column());
            }
        }

        $retainedPrimaryKey = SqlFragments::identifier(SqlFragments::objectName($map->table, ViewCompiler::retainedTable($map).'_pkey'));
        $retainedForeignKey = SqlFragments::identifier(SqlFragments::objectName($map->table, ViewCompiler::retainedTable($map).'_'.$retainedKey.'_foreign'));
        $mapped = array_values(array_diff(array_keys($legacy), $map->legacyOnly(array_keys($legacy))));
        usort($mapped, fn (string $first, string $second) => $legacy[$second]->alwaysGenerated <=> $legacy[$first]->alwaysGenerated);
        $defaults = array_filter($legacy, fn (Column $column, string $name) => $column->default !== null && ! ($map->attributes[$name] ?? null)?->isExternal(), ARRAY_FILTER_USE_BOTH);
        $hidden = self::reportHidden($map, $key);
        [$readGrants, $grant] = self::grants($map->table, $legacyTable, array_keys(array_filter($legacy, fn (Column $column, string $name) => ! ($map->attributes[$name] ?? null)?->isExternal(), ARRAY_FILTER_USE_BOTH)));

        return new self('install', [
            "LOCK TABLE {$legacyTable}, {$primary} IN SHARE ROW EXCLUSIVE MODE",
            ...(new Preparer($columnCatalog))->unfinished($map),
            self::refuseMissingPrimaries($map, $key),
            ...($hidden === null ? [] : [$hidden]),
            "ALTER TABLE {$legacyTable} ADD COLUMN ".SqlFragments::identifier($retainedKey).' '.$columnCatalog->of($map->primary->table)[$map->primary->key]->type,
            "UPDATE {$legacyTable} SET ".SqlFragments::identifier($retainedKey)." = {$primary}.{$primaryKey} FROM {$primary} WHERE {$primary}.".SqlFragments::identifier((string) $map->identifiers[$key]->column())." = {$legacyTable}.".SqlFragments::identifier($key),
            ...$repoints,
            ...$sequences,
            $readGrants,
            "ALTER TABLE {$legacyTable}\n  ".implode(",\n  ", array_map(fn (string $column) => 'DROP COLUMN '.SqlFragments::identifier($column), $mapped)),
            "ALTER TABLE {$legacyTable} RENAME TO {$retainedTable}",
            "ALTER TABLE {$retainedTable}\n  ADD CONSTRAINT {$retainedPrimaryKey} PRIMARY KEY (".SqlFragments::identifier($retainedKey)."),\n  ADD CONSTRAINT {$retainedForeignKey} FOREIGN KEY (".SqlFragments::identifier($retainedKey).") REFERENCES {$primary} ({$primaryKey}) ON DELETE CASCADE NOT VALID",
            "COMMENT ON TABLE {$retainedTable} IS ".SqlFragments::literal(self::capture($connection, $map, $legacy, $mapped, $moved, $notValid)),
            $view,
            ...array_map(fn (Column $column, string $name) => "ALTER VIEW {$legacyTable} ALTER COLUMN ".SqlFragments::identifier($name).' SET DEFAULT '.$column->default, $defaults, array_keys($defaults)),
            $grant,
        ], [
            ...$validations,
            self::validate($retainedTable, ViewCompiler::retainedTable($map).'_'.$retainedKey.'_foreign'),
        ]);
    }

    public static function recompile(ResolvedMap $map, ConnectionInterface $connection): self
    {
        if (self::kind($connection, $map->table) !== 'v') {
            throw InvalidCompatibilityMap::because($map->table, "{$map->table} is not a view, so recompile() has nothing to replace; install() creates it");
        }

        $view = (new ViewCompiler(new ColumnCatalog($connection), $connection))->replace($map);
        $retainedTable = SqlFragments::identifier(ViewCompiler::retainedTable($map));
        $identifiers = array_map(fn ($identifier) => (string) $identifier->column(), array_values($map->identifiers));
        $notValid = self::captured($connection, ViewCompiler::retainedTable($map))['notValid'] ?? [];
        $validations = [];

        foreach (self::foreignKeysTo($connection, $map->primary->table) as $foreignKey) {
            $retained = $foreignKey->qualifiedTable === $retainedTable && $foreignKey->column === $map->retainedKey && $foreignKey->referencedColumn === $map->primary->key;

            if (! $foreignKey->validated && ! in_array([$foreignKey->qualifiedTable, $foreignKey->name], $notValid, true) && ($retained || in_array($foreignKey->referencedColumn, $identifiers, true))) {
                $validations[] = self::validate($foreignKey->qualifiedTable, $foreignKey->name);
            }
        }

        return new self('recompile', [$view], $validations);
    }

    public static function uninstall(ResolvedMap $map, ConnectionInterface $connection): self
    {
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
        $repointed = [];
        $locked = implode(', ', array_values(array_unique([$primary, ...array_map(fn ($related) => SqlFragments::identifier($related->table), $map->related)])));

        $statements = [
            "LOCK TABLE {$locked} IN SHARE ROW EXCLUSIVE MODE",
            "DROP VIEW {$legacyTable}",
            "ALTER TABLE {$retainedTable} RENAME TO {$legacyTable}",
            "INSERT INTO {$legacyTable} ({$retainedKey}) SELECT {$primary}.{$primaryKey} FROM {$primary} WHERE NOT EXISTS (SELECT FROM {$legacyTable} WHERE {$legacyTable}.{$retainedKey} = {$primary}.{$primaryKey})",
            "ALTER TABLE {$legacyTable}\n  ".implode(",\n  ", array_map(fn (string $column) => 'ADD COLUMN '.SqlFragments::identifier($column).' '.$shape[$column]->type, $columns)),
            "UPDATE {$legacyTable} SET\n  ".implode(",\n  ", array_map(fn (string $column) => SqlFragments::identifier($column).' = '.$served[$column], $columns))
                ."\nFROM {$primary}".implode('', array_map(fn (string $lateral) => "\n".$lateral, ViewCompiler::laterals($map)))
                ."\nWHERE {$primary}.{$primaryKey} = {$legacyTable}.{$retainedKey}",
        ];

        if ($defaults !== []) {
            $statements[] = "ALTER TABLE {$legacyTable}\n  ".implode(",\n  ", array_map(fn (Column $column, string $name) => 'ALTER COLUMN '.SqlFragments::identifier($name).' SET DEFAULT '.$column->default, $defaults, array_keys($defaults)));
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
        [$replayed, $validations] = $captured === null ? [[], []] : self::replay($captured, $legacyTable, array_keys($served));
        $statements = [...$statements, ...$replayed];
        $identifiers = [];

        foreach ($map->identifiers as $name => $identifier) {
            $identifiers[(string) $identifier->column()] = $name;
        }

        foreach (self::foreignKeysTo($connection, $map->primary->table) as $foreignKey) {
            if (array_key_exists($foreignKey->referencedColumn, $identifiers) && ($captured === null || in_array([$foreignKey->qualifiedTable, $foreignKey->name], $captured['moved'], true))) {
                $on = $foreignKey->qualifiedTable === $retainedTable ? $legacyTable : $foreignKey->qualifiedTable;
                $statements[] = self::repoint($map, $foreignKey, $on, $map->table, $identifiers[$foreignKey->referencedColumn]);

                if ($captured === null || ! in_array([$foreignKey->qualifiedTable, $foreignKey->name], $captured['notValid'], true)) {
                    $repointed[] = self::validate($on, $foreignKey->name);
                }
            }
        }

        return new self('uninstall', $statements, [...$repointed, ...$validations]);
    }

    /**
     * @return list<string>
     */
    public static function guard(ResolvedMap $map, ConnectionInterface $connection, string $operation): array
    {
        $relations = 'ARRAY['.implode(', ', array_map(fn (string $table) => 'to_regclass(quote_ident(current_schema()) || '.SqlFragments::literal('.'.SqlFragments::identifier($table)).')', $map->tables())).']::oid[]';
        $fingerprint = "SELECT encode(sha256(convert_to(coalesce(string_agg(entry, E'\\n' ORDER BY entry), ''), 'UTF8')), 'hex') FROM (\n"
            ."  SELECT 'relation ' || relation.oid::regclass::text || ' ' || relation.relkind::text || ' ' || relation.relrowsecurity::text || ' ' || relation.relforcerowsecurity::text || ' ' || coalesce(obj_description(relation.oid, 'pg_class'), '') AS entry\n"
            ."  FROM pg_class relation WHERE relation.oid = ANY ({$relations})\n"
            ."  UNION ALL\n"
            ."  SELECT 'column ' || attribute.attrelid::regclass::text || ' ' || attribute.attnum::text || ' ' || quote_ident(attribute.attname) || ' ' || format_type(attribute.atttypid, attribute.atttypmod) || ' ' || attribute.attnotnull::text || ' ' || attribute.attidentity::text || attribute.attgenerated::text || ' ' || attribute.attcollation::text || ' ' || coalesce(pg_get_expr(column_default.adbin, column_default.adrelid), '')\n"
            ."  FROM pg_attribute attribute LEFT JOIN pg_attrdef column_default ON column_default.adrelid = attribute.attrelid AND column_default.adnum = attribute.attnum\n"
            ."  WHERE attribute.attrelid = ANY ({$relations}) AND attribute.attnum > 0 AND NOT attribute.attisdropped\n"
            ."  UNION ALL\n"
            ."  SELECT 'constraint ' || constraint_row.conrelid::regclass::text || ' ' || quote_ident(constraint_row.conname) || ' ' || pg_get_constraintdef(constraint_row.oid) || ' ' || constraint_row.convalidated::text\n"
            ."  FROM pg_constraint constraint_row WHERE constraint_row.conrelid = ANY ({$relations}) OR constraint_row.confrelid = ANY ({$relations})\n"
            ."  UNION ALL\n"
            ."  SELECT 'index ' || pg_get_indexdef(table_index.indexrelid) || ' ' || table_index.indisvalid::text\n"
            ."  FROM pg_index table_index WHERE table_index.indrelid = ANY ({$relations})\n"
            ."  UNION ALL\n"
            ."  SELECT 'sequence ' || dependency.objid::regclass::text || ' ' || dependency.refobjid::regclass::text || ' ' || dependency.refobjsubid::text\n"
            ."  FROM pg_depend dependency JOIN pg_class sequence ON sequence.oid = dependency.objid AND sequence.relkind = 'S'\n"
            ."  WHERE dependency.classid = 'pg_class'::regclass AND dependency.refclassid = 'pg_class'::regclass AND dependency.refobjid = ANY ({$relations}) AND dependency.deptype IN ('a', 'i')\n"
            .') AS entries';
        $locked = array_values(array_filter(array_map(
            fn (string $table) => $connection->scalar("select cast(to_regclass(quote_ident(current_schema()) || '.' || ?) as text)", [SqlFragments::identifier($table)]),
            $map->tables(),
        ), is_string(...)));
        $refusal = SqlFragments::literal("Compatibility map '{$map->table}' cannot {$operation}: ".implode(', ', array_slice($map->tables(), 0, -1)).(count($map->tables()) > 1 ? ' or ' : '').implode('', array_slice($map->tables(), -1)).' changed after compatibility:sql read them. Print the statements again.');

        return [
            ...($locked === [] ? [] : ['LOCK TABLE '.implode(', ', $locked).' IN SHARE UPDATE EXCLUSIVE MODE']),
            "DO \$compatibility\$\nBEGIN\n"
                ."  IF ({$fingerprint}) IS DISTINCT FROM ".SqlFragments::literal((string) $connection->scalar($fingerprint))." THEN\n"
                ."    RAISE EXCEPTION '%', {$refusal};\n"
                ."  END IF;\nEND\n\$compatibility\$",
        ];
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
            $matches[] = "{$primary}.".SqlFragments::identifier((string) $identifier->column()).($name === $key ? ' = ' : ' IS NOT DISTINCT FROM ')."{$legacyTable}.".SqlFragments::identifier($name);
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
        $hidden = "FROM {$legacyTable} WHERE EXISTS (SELECT FROM {$primary} WHERE {$primary}.".SqlFragments::identifier((string) $map->identifiers[$key]->column())." = {$legacyKey} AND ("
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

    /**
     * @param  array<string, Column>  $legacy
     * @param  list<string>  $mapped
     * @param  list<array{string, string}>  $moved
     * @param  list<array{string, string}>  $notValid
     */
    private static function capture(ConnectionInterface $connection, ResolvedMap $map, array $legacy, array $mapped, array $moved, array $notValid): string
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
            'notValid' => $notValid,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return null|array{comment: null|string, notNull: list<string>, generated: array<string, string>, constraints: list<array{name: string, type: string, definition: string, validated: bool}>, indexes: list<string>, moved: list<array{string, string}>, notValid: list<array{string, string}>}
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

        [$moved, $notValid] = array_map(fn (string $list) => array_values(array_map(
            fn (array $foreignKey) => [(string) $foreignKey[0], (string) $foreignKey[1]],
            array_filter(is_array($captured[$list] ?? null) ? $captured[$list] : [], fn (mixed $foreignKey) => is_array($foreignKey) && is_string($foreignKey[0] ?? null) && is_string($foreignKey[1] ?? null)),
        )), ['moved', 'notValid']);

        return [
            'comment' => is_string($captured['comment'] ?? null) ? $captured['comment'] : null,
            'notNull' => array_values(array_filter(is_array($captured['notNull'] ?? null) ? $captured['notNull'] : [], is_string(...))),
            'generated' => $generated,
            'constraints' => $constraints,
            'indexes' => array_values(array_filter(is_array($captured['indexes'] ?? null) ? $captured['indexes'] : [], is_string(...))),
            'moved' => $moved,
            'notValid' => $notValid,
        ];
    }

    /**
     * @param  array{comment: null|string, notNull: list<string>, generated: array<string, string>, constraints: list<array{name: string, type: string, definition: string, validated: bool}>, indexes: list<string>, moved: list<array{string, string}>, notValid: list<array{string, string}>}  $captured
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
     * @return array{string, string}
     */
    private static function grants(string $table, string $view, array $columns): array
    {
        $relation = 'to_regclass(quote_ident(current_schema()) || '.SqlFragments::literal('.'.SqlFragments::identifier($table)).')';
        $on = SqlFragments::literal(' ON '.$view.' TO ');
        $grantee = "CASE WHEN granted.grantee = 0 THEN 'PUBLIC' ELSE quote_ident(pg_get_userbyid(granted.grantee)) END";
        $installer = '(SELECT oid FROM pg_roles WHERE rolname = current_user)';

        return [
            "SELECT set_config('compatibility.grants', coalesce(json_agg(grants.statement ORDER BY grants.position, grants.statement)::text, '[]'), true)\n"
                ."FROM (\n"
                ."  SELECT 1 AS position, 'GRANT ' || string_agg(granted.privilege_type, ', ' ORDER BY granted.privilege_type) || {$on} || {$grantee} || CASE WHEN granted.is_grantable OR granted.grantee = relation.relowner THEN ' WITH GRANT OPTION' ELSE '' END AS statement\n"
                ."  FROM pg_class relation\n"
                ."  CROSS JOIN LATERAL aclexplode(coalesce(relation.relacl, acldefault('r', relation.relowner))) AS granted\n"
                ."  WHERE relation.oid = {$relation} AND granted.grantee <> {$installer}\n"
                ."  GROUP BY granted.grantee, granted.is_grantable, relation.relowner\n"
                ."  UNION ALL\n"
                ."  SELECT 2, 'GRANT ' || string_agg(granted.privilege_type || ' (' || quote_ident(attribute.attname) || ')', ', ' ORDER BY attribute.attnum, granted.privilege_type) || {$on} || {$grantee} || CASE WHEN granted.is_grantable THEN ' WITH GRANT OPTION' ELSE '' END\n"
                ."  FROM pg_attribute attribute\n"
                ."  CROSS JOIN LATERAL aclexplode(attribute.attacl) AS granted\n"
                ."  WHERE attribute.attrelid = {$relation} AND attribute.attnum > 0 AND NOT attribute.attisdropped AND granted.grantee <> {$installer}\n"
                .'    AND attribute.attname IN (SELECT jsonb_array_elements_text(CAST('.SqlFragments::literal(json_encode($columns, JSON_THROW_ON_ERROR)).' AS jsonb)))'."\n"
                ."  GROUP BY granted.grantee, granted.is_grantable\n"
                .') AS grants',
            "DO \$compatibility\$\nDECLARE\n  granted text;\nBEGIN\n"
                ."  FOR granted IN SELECT json_array_elements_text(CAST(current_setting('compatibility.grants') AS json)) LOOP\n"
                ."    EXECUTE granted;\n"
                ."  END LOOP;\n"
                ."END\n\$compatibility\$",
        ];
    }

    private static function repoint(ResolvedMap $map, object $foreignKey, string $on, string $table, string $column): string
    {
        if ($foreignKey->columns !== 1) {
            throw InvalidCompatibilityMap::because($map->table, "the foreign key {$foreignKey->name} on {$foreignKey->table} spans {$foreignKey->columns} columns, and the package repoints a foreign key on one identifier");
        }

        $name = SqlFragments::identifier($foreignKey->name);

        return "ALTER TABLE {$on}\n  DROP CONSTRAINT {$name},\n  ADD CONSTRAINT {$name} FOREIGN KEY (".SqlFragments::identifier($foreignKey->column).') REFERENCES '.SqlFragments::identifier($table).' ('.SqlFragments::identifier($column).')'
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
     * @return list<object{name: string, table: string, qualifiedTable: string, column: string, referencedColumn: string, columns: int, onUpdate: string, onDelete: string, matchType: string, deferrable: bool, deferred: bool, validated: bool}>
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
                    foreign_key.condeferred as deferred,
                    foreign_key.convalidated as validated
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
