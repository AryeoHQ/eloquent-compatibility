<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Support\Database\Eloquent\Compatibility\Contracts\ManagesDependents;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Write\Backfiller;
use Support\Database\Eloquent\Compatibility\Write\ExternalAttributes;

final readonly class Statements
{
    private const string CAPTURED = 'compatibility install';

    /**
     * @param  list<string>  $transaction
     * @param  list<string>  $afterwards
     * @param  array<int, list<Closure(ConnectionInterface): void>>  $steps
     * @param  array<int, list<array{string, Closure(Connection): void}>>  $hooks
     * @param  list<string>  $notes
     * @param  list<array{string, string}>  $locks
     */
    public function __construct(
        public string $operation,
        public array $transaction,
        public array $afterwards = [],
        public array $steps = [],
        public array $hooks = [],
        public array $notes = [],
        public array $locks = [],
    ) {}

    public static function prepare(ResolvedMap $map, ConnectionInterface $connection): self
    {
        if (self::kind(
            connection: $connection,
            table: $map->table,
        ) === 'v') {
            throw InvalidCompatibilityMap::cannot(
                map: $map->table,
                operation: 'prepare',
                because: "{$map->table} is already installed as a view, and prepare() runs on the legacy table before install()",
            );
        }

        $prepared = (new Preparer(new ColumnCatalog($connection)))->statements(
            map: $map,
            connection: $connection,
        );

        return new self(
            operation: 'prepare',
            transaction: $prepared->transaction,
            afterwards: $prepared->afterwards,
            notes: self::dependentNotes(
                connection: $connection,
                table: $map->table,
            ),
        );
    }

    public static function install(ResolvedMap $map, ConnectionInterface $connection, bool $catchingUp = false): self
    {
        if (! $catchingUp && (new Backfiller($map, $connection))->remembered() !== null) {
            throw InvalidCompatibilityMap::cannot($map->table, 'install', "the last compatibility:backfill of {$map->table} left a watermark, and Compatibility::install() catches up the legacy writes since it and deletes the successor rows of hard-deleted legacy rows before it takes the lock, and no printed SQL runs that catch-up. Run Compatibility::install() instead");
        }

        $legacyKind = self::kind(
            connection: $connection,
            table: $map->table,
        );

        if ($legacyKind === 'v' && self::kind(
            connection: $connection,
            table: ViewCompiler::retainedTable($map),
        ) === 'r') {
            return new self(
                operation: 'install',
                transaction: [],
            );
        }

        if ($legacyKind === null) {
            throw InvalidCompatibilityMap::because(
                map: $map->table,
                problem: "the legacy table {$map->table} does not exist",
            );
        }

        if ($legacyKind !== 'r') {
            throw InvalidCompatibilityMap::because(
                map: $map->table,
                problem: "{$map->table} is not a plain table, so install() cannot serve it through a view; update() replaces an installed view",
            );
        }

        if ($connection->scalar("select relrowsecurity or relforcerowsecurity from pg_class where oid = to_regclass(quote_ident(current_schema()) || '.' || ?)", [SqlFragments::identifier($map->table)]) === true) {
            throw InvalidCompatibilityMap::because($map->table, "{$map->table} enables row-level security, and the view would read its successors with its owner's privileges and serve rows a policy hides");
        }

        self::refuseUndeclaredDependents(
            map: $map,
            connection: $connection,
        );

        $columnCatalog = new ColumnCatalog($connection);
        $view = (new ViewCompiler(
            columnCatalog: $columnCatalog,
            connection: $connection,
        ))->create($map);
        $legacyTable = SqlFragments::identifier($map->table);
        $retainedName = ViewCompiler::retainedTable($map);
        $retainedTable = SqlFragments::identifier($retainedName);
        $successorKey = SqlFragments::identifier($map->successorKey);
        $primary = SqlFragments::identifier($map->primary->table);
        $primaryKey = SqlFragments::identifier($map->primary->key);
        $legacy = $columnCatalog->of($map->table);
        $key = (new $map->model)->getKeyName();

        if (! array_key_exists(
            key: $key,
            array: $legacy,
        )) {
            throw InvalidCompatibilityMap::because(
                map: $map->table,
                problem: "{$map->table} has no column {$key}, the key of ".class_basename($map->model),
            );
        }

        $external = array_values(array_filter($map->externals(), fn (string $name) => array_key_exists($name, $legacy)));

        foreach ($external as $name) {
            if ($legacy[$name]->generated) {
                throw InvalidCompatibilityMap::because($map->table, "{$map->table}.{$name} is a generated column, and attribute '{$name}' is external(), so its value would come from outside the database and nothing would keep it generated from its columns; serve it with readAs() and readOnly()");
            }

            $ownedSequence = self::sequence($connection, $map->table, $name);

            if ($ownedSequence !== null) {
                throw InvalidCompatibilityMap::because(
                    map: $map->table,
                    problem: "{$map->table}.{$name} owns the sequence {$ownedSequence}, and attribute '{$name}' is external(), so install() would drop the sequence with the column and rollback() could not restore the default that reads it; detach it with ALTER SEQUENCE {$ownedSequence} OWNED BY NONE first",
                );
            }
        }

        foreach (self::foreignKeysTo($connection, $map->table) as $foreignKey) {
            if (array_key_exists($foreignKey->referencedColumn, $map->attributes)) {
                throw InvalidCompatibilityMap::because(
                    map: $map->table,
                    problem: "the foreign key {$foreignKey->name} on {$foreignKey->table} references {$map->table}.{$foreignKey->referencedColumn}, which install() drops from {$retainedName}; reference a column the retained table keeps instead",
                );
            }
        }

        $mapped = array_values(array_diff(array_keys($legacy), $map->legacyOnly(array_keys($legacy))));
        usort($mapped, fn (string $first, string $second) => $legacy[$second]->alwaysGenerated <=> $legacy[$first]->alwaysGenerated);
        $defaults = array_filter($legacy, fn (Column $column, string $name) => $column->default !== null && ! ($map->attributes[$name] ?? null)?->isExternal(), ARRAY_FILTER_USE_BOTH);
        $hidden = self::reportHidden(
            map: $map,
            key: $key,
        );
        [$readGrants, $grant] = self::grants($map->table, $legacyTable, array_keys(array_filter($legacy, fn (Column $column, string $name) => ! ($map->attributes[$name] ?? null)?->isExternal(), ARRAY_FILTER_USE_BOTH)));
        $unfinished = (new Preparer($columnCatalog))->unfinished($map);
        $verifications = [
            ...($map->morphs === [] ? [] : [fn (ConnectionInterface $connection) => (new Backfiller(
                map: $map,
                connection: $connection,
            ))->refuseMisplaced()]),
            ...array_map(
                callback: fn (string $name) => fn (ConnectionInterface $connection) => ExternalAttributes::verify(
                    map: $map,
                    connection: $connection,
                    name: $name,
                ),
                array: $external,
            ),
        ];
        $notNull = SqlFragments::identifier(self::notNullName($map));
        $foreign = SqlFragments::identifier(self::foreignName($map));

        $transaction = [
            "LOCK TABLE {$legacyTable} IN SHARE ROW EXCLUSIVE MODE",
            ...$unfinished,
            self::refuseMissingRoots(
                map: $map,
                key: $key,
            ),
            ...($hidden === null ? [] : [$hidden]),
            $readGrants,
        ];
        $beforeInstall = count($transaction) - 1;
        $transaction = [
            ...$transaction,
            "ALTER TABLE {$legacyTable} RENAME TO {$retainedTable}",
            ...($mapped === [] ? [] : ["ALTER TABLE {$retainedTable}\n  ".implode(
                separator: ",\n  ",
                array: array_map(
                    callback: fn (string $column) => 'DROP COLUMN '.SqlFragments::identifier($column),
                    array: $mapped,
                ),
            )]),
            "ALTER TABLE {$retainedTable} ADD CONSTRAINT {$notNull} CHECK ({$successorKey} IS NOT NULL) NOT VALID",
            "COMMENT ON TABLE {$retainedTable} IS ".SqlFragments::literal(self::capture(
                connection: $connection,
                map: $map,
                legacy: $legacy,
                mapped: $mapped,
                external: $external,
            )),
            $view,
            ...array_map(fn (Column $column, string $name) => "ALTER VIEW {$legacyTable} ALTER COLUMN ".SqlFragments::identifier($name).' SET DEFAULT '.$column->default, $defaults, array_keys($defaults)),
            $grant,
        ];
        $afterInstall = count($transaction) - 1;
        $transaction = [
            ...$transaction,
            Backfiller::forgetStatement($map),
            "ALTER TABLE {$retainedTable} ADD CONSTRAINT {$foreign} FOREIGN KEY ({$successorKey}) REFERENCES {$primary} ({$primaryKey}) ON DELETE CASCADE NOT VALID",
        ];
        $hooks = self::hooks(
            map: $map,
            positions: [$beforeInstall => 'beforeInstall', $afterInstall => 'afterInstall'],
        );

        return new self(
            operation: 'install',
            transaction: $transaction,
            steps: $verifications === [] ? [] : [count($unfinished) + 1 => $verifications],
            hooks: $hooks,
            notes: self::dependentNotes(
                connection: $connection,
                table: $map->table,
            ),
            locks: array_map(
                callback: fn (string $table) => [$table, match ($table) {
                    $map->table => 'SHARE UPDATE EXCLUSIVE from install\'s first statement, then SHARE ROW EXCLUSIVE, then ACCESS EXCLUSIVE from the rename until commit',
                    $map->primary->table => 'SHARE UPDATE EXCLUSIVE from install\'s first statement, then SHARE ROW EXCLUSIVE from the foreign key, install\'s last statement, until commit',
                    default => 'SHARE UPDATE EXCLUSIVE from install\'s first statement until commit',
                }],
                array: array_values(array_filter(
                    array: $map->tables(),
                    callback: fn (string $table) => self::kind(
                        connection: $connection,
                        table: $table,
                    ) !== null,
                )),
            ),
        );
    }

    public static function update(ResolvedMap $map, ConnectionInterface $connection): self
    {
        if (self::kind(
            connection: $connection,
            table: $map->table,
        ) !== 'v') {
            throw InvalidCompatibilityMap::because(
                map: $map->table,
                problem: "{$map->table} is not a view, so update() has nothing to replace; install() creates it",
            );
        }

        return new self(
            operation: 'update',
            transaction: [(new ViewCompiler(
                columnCatalog: new ColumnCatalog($connection),
                connection: $connection,
            ))->replace($map)],
        );
    }

    public static function rollback(ResolvedMap $map, ConnectionInterface $connection): self
    {
        $retainedName = ViewCompiler::retainedTable($map);
        $legacyKind = self::kind(
            connection: $connection,
            table: $map->table,
        );

        if ($legacyKind === 'r' && self::kind(
            connection: $connection,
            table: $retainedName,
        ) === null) {
            return new self(
                operation: 'rollback',
                transaction: [],
            );
        }

        if ($legacyKind !== 'v') {
            throw InvalidCompatibilityMap::because(
                map: $map->table,
                problem: "{$map->table} is not a view, so rollback() has nothing to reverse",
            );
        }

        $columnCatalog = new ColumnCatalog($connection);
        $shape = $columnCatalog->of($map->table);
        $served = (new ViewCompiler($columnCatalog, $connection))->served($map, $shape);
        $legacyTable = SqlFragments::identifier($map->table);
        $retainedTable = SqlFragments::identifier($retainedName);
        $successorKey = SqlFragments::identifier($map->successorKey);
        $primary = SqlFragments::identifier($map->primary->table);
        $primaryKey = SqlFragments::identifier($map->primary->key);
        $captured = self::captured(
            connection: $connection,
            table: $retainedName,
        );
        $generated = $captured === null ? [] : array_intersect_key($captured['generated'], $served);
        $columns = array_keys(array_diff_key($served, $generated));
        $defaults = array_filter(array_intersect_key($shape, array_flip($columns)), fn (Column $column) => $column->default !== null);
        $external = $captured === null ? [] : array_intersect_key($captured['external'], array_flip($map->externals()));
        $locked = implode(', ', array_values(array_unique([$primary, ...array_map(fn ($related) => SqlFragments::identifier($related->table), $map->related)])));
        $collations = ViewCompiler::collations($connection, $map->table);
        [$readGrants, $grant] = self::grants(
            table: $map->table,
            view: $legacyTable,
            columns: array_keys($shape),
        );

        $statements = [
            "LOCK TABLE {$locked} IN SHARE ROW EXCLUSIVE MODE",
            $readGrants,
        ];
        $beforeRollback = count($statements) - 1;
        $statements = [
            ...$statements,
            "DROP VIEW {$legacyTable}",
            "ALTER TABLE {$retainedTable} RENAME TO {$legacyTable}",
            ...($columns === [] && $external === [] ? [] : ["ALTER TABLE {$legacyTable}\n  ".implode(
                separator: ",\n  ",
                array: [
                    ...array_map(fn (string $column) => 'ADD COLUMN '.SqlFragments::identifier($column).' '.$shape[$column]->type.(($collations[$column]['declared'] ?? false) ? ' COLLATE '.$collations[$column]['name'] : ''), $columns),
                    ...array_map(fn (array $column, string $name) => 'ADD COLUMN '.SqlFragments::identifier($name).' '.$column['type'].($column['collation'] === null ? '' : ' COLLATE '.$column['collation']), $external, array_keys($external)),
                ],
            )]),
            ...($columns === [] ? [] : ["UPDATE {$legacyTable} AS {$retainedTable} SET\n  ".implode(
                separator: ",\n  ",
                array: array_map(
                    callback: fn (string $column) => SqlFragments::identifier($column).' = '.$served[$column],
                    array: $columns,
                ),
            )
                ."\nFROM {$primary}".implode('', array_map(fn (string $lateral) => "\n".$lateral, ViewCompiler::laterals($map)))
                ."\nWHERE {$primary}.{$primaryKey} = {$retainedTable}.{$successorKey}"]),
        ];
        $filled = count($statements) - 1;
        $restoredDefaults = [...array_map(fn (Column $column) => (string) $column->default, $defaults), ...array_filter(array_map(fn (array $column) => $column['default'], $external), is_string(...))];

        if ($restoredDefaults !== []) {
            $statements[] = "ALTER TABLE {$legacyTable}\n  ".implode(",\n  ", array_map(fn (string $default, string $name) => 'ALTER COLUMN '.SqlFragments::identifier($name).' SET DEFAULT '.$default, $restoredDefaults, array_keys($restoredDefaults)));
        }

        if ($generated !== []) {
            $statements[] = "ALTER TABLE {$legacyTable}\n  ".implode(",\n  ", array_map(fn (string $clause, string $name) => 'ADD COLUMN '.SqlFragments::identifier($name).' '.$shape[$name]->type.' '.$clause, $generated, array_keys($generated)));
        }

        [$replayed, $validations] = $captured === null ? [[], []] : self::replay(
            captured: $captured,
            legacyTable: $legacyTable,
            served: [...array_keys($served), ...array_keys($external)],
        );
        $statements = [...$statements, ...$replayed, $grant];
        $hooks = self::hooks(
            map: $map,
            positions: [$beforeRollback => 'beforeRollback', count($statements) - 1 => 'afterRollback'],
        );
        $statements = [
            ...$statements,
            Installer::lockTimeout(),
            "ALTER TABLE {$legacyTable}\n  DROP CONSTRAINT IF EXISTS ".SqlFragments::identifier(self::foreignName($map)).",\n  DROP CONSTRAINT IF EXISTS ".SqlFragments::identifier(self::notNullName($map)),
        ];

        return new self(
            operation: 'rollback',
            transaction: $statements,
            afterwards: $validations,
            steps: $external === [] ? [] : [$filled => array_map(fn (array $column, string $name) => fn (ConnectionInterface $connection) => ExternalAttributes::restore(
                map: $map,
                connection: $connection,
                name: $name,
                type: $column['type'],
            ), $external, array_keys($external))],
            hooks: $hooks,
            notes: self::dependentNotes(
                connection: $connection,
                table: $map->table,
            ),
        );
    }

    public static function retire(ResolvedMap $map, ConnectionInterface $connection): self
    {
        $retainedName = ViewCompiler::retainedTable($map);
        $ledger = Backfiller::ledger($map->table);

        $legacyKind = self::kind(
            connection: $connection,
            table: $map->table,
        );
        $retainedKind = self::kind(
            connection: $connection,
            table: $retainedName,
        );

        if ($legacyKind === null && $retainedKind === null) {
            return new self(
                operation: 'retire',
                transaction: self::kind(
                    connection: $connection,
                    table: $ledger,
                ) === null ? [] : [Backfiller::forgetStatement($map), 'DROP TABLE IF EXISTS '.SqlFragments::identifier($ledger)],
            );
        }

        if ($legacyKind !== 'v' || $retainedKind !== 'r') {
            throw InvalidCompatibilityMap::cannot(
                map: $map->table,
                operation: 'retire',
                because: "{$map->table} is not an installed view over {$retainedName}, and retire() drops the view and the retained table of an installed model",
            );
        }

        $dependents = [
            ...self::dependents(
                connection: $connection,
                table: $map->table,
            ),
            ...self::dependents(
                connection: $connection,
                table: $retainedName,
                except: $map->table,
            ),
            ...array_map(
                callback: fn (object $foreignKey) => "the foreign key {$foreignKey->name} on {$foreignKey->table}",
                array: array_values(array_filter(
                    array: self::foreignKeysTo(
                        connection: $connection,
                        table: $retainedName,
                    ),
                    callback: fn (object $foreignKey) => $foreignKey->table !== $retainedName,
                )),
            ),
        ];

        if ($dependents !== []) {
            throw InvalidCompatibilityMap::cannot(
                map: $map->table,
                operation: 'retire',
                because: implode(
                    separator: ', ',
                    array: $dependents,
                ).' '.(count($dependents) === 1 ? 'depends' : 'depend')." on {$map->table} or {$retainedName}; drop each one, then retire again",
            );
        }

        $legacyOnly = array_values(array_diff($map->legacyOnly(array_keys((new ColumnCatalog($connection))->of($retainedName))), [(new $map->model)->getKeyName(), $map->successorKey]));
        $rows = $connection->scalar('select count(*) from '.SqlFragments::identifier($retainedName));

        return new self(
            operation: 'retire',
            transaction: [
                'DROP VIEW '.SqlFragments::identifier($map->table),
                'DROP TABLE '.SqlFragments::identifier($retainedName),
                Backfiller::forgetStatement($map),
                'DROP TABLE IF EXISTS '.SqlFragments::identifier($ledger),
            ],
            notes: [
                "retire() discards {$retainedName}: ".(is_int($rows) ? $rows : 0).' rows'.($legacyOnly === [] ? '' : ', with the legacy-only columns '.implode(
                    separator: ', ',
                    array: $legacyOnly,
                )),
                "retire() discards {$ledger}, the backfill ledger of {$map->table}",
                "retire() discards the compatibility_backfills row of {$map->table}",
            ],
        );
    }

    /**
     * @return list<string>
     */
    public static function dependents(ConnectionInterface $connection, string $table, null|string $except = null): array
    {
        $relation = "to_regclass(quote_ident(current_schema()) || '.' || ?)";
        $named = SqlFragments::identifier($table);

        return array_values(array_map(
            callback: fn (object $row) => (string) $row->kind.' '.(string) $row->name,
            array: $connection->select(
                query: "select kind, name from (
                 select distinct case dependent.relkind when 'm' then 'materialized view' else 'view' end as kind, dependent.oid::regclass::text as name
                 from pg_depend dependency
                 join pg_rewrite rewrite on rewrite.oid = dependency.objid
                 join pg_class dependent on dependent.oid = rewrite.ev_class
                 where dependency.classid = 'pg_rewrite'::regclass and dependency.refclassid = 'pg_class'::regclass
                   and dependency.refobjid = {$relation} and dependent.oid <> dependency.refobjid".($except === null ? '' : " and dependent.oid is distinct from {$relation}")."
                 union
                 select 'rule', rewrite.rulename || ' on ' || rewrite.ev_class::regclass::text
                 from pg_rewrite rewrite where rewrite.ev_class = {$relation} and rewrite.rulename <> '_RETURN'
                 union
                 select 'policy', policy.polname || ' on ' || policy.polrelid::regclass::text
                 from pg_policy policy where policy.polrelid = {$relation}
                 union
                 select 'function', function_row.oid::regprocedure::text
                 from pg_depend dependency join pg_proc function_row on function_row.oid = dependency.objid
                 where dependency.classid = 'pg_proc'::regclass and dependency.refclassid = 'pg_class'::regclass and dependency.refobjid = {$relation}
             ) dependents order by kind, name",
                bindings: [$named, ...($except === null ? [] : [SqlFragments::identifier($except)]), $named, $named, $named],
            ),
        ));
    }

    public static function refuseUndeclaredDependents(ResolvedMap $map, ConnectionInterface $connection): void
    {
        if ($map->dependents !== null || self::kind(
            connection: $connection,
            table: $map->table,
        ) !== 'r') {
            return;
        }

        $dependents = self::dependents(
            connection: $connection,
            table: $map->table,
        );

        if ($dependents !== []) {
            throw InvalidCompatibilityMap::cannot(
                map: $map->table,
                operation: 'install',
                because: implode(
                    separator: ', ',
                    array: $dependents,
                ).' '.(count($dependents) === 1 ? 'depends' : 'depend')." on {$map->table}, and the map declares no dependents(); declare a ".class_basename(ManagesDependents::class).' class that drops and recreates them inside the install transaction',
            );
        }
    }

    public static function notNullName(ResolvedMap $map): string
    {
        return SqlFragments::objectName(
            map: $map->table,
            name: ViewCompiler::retainedTable($map).'_'.$map->successorKey.'_not_null',
        );
    }

    public static function foreignName(ResolvedMap $map): string
    {
        return SqlFragments::objectName(
            map: $map->table,
            name: ViewCompiler::retainedTable($map).'_'.$map->successorKey.'_foreign',
        );
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

    /**
     * @return list<string>
     */
    private static function dependentNotes(ConnectionInterface $connection, string $table): array
    {
        return array_map(
            callback: fn (string $dependent) => "{$dependent} depends on {$table}",
            array: self::dependents(
                connection: $connection,
                table: $table,
            ),
        );
    }

    /**
     * @param  array<int, string>  $positions
     * @return array<int, list<array{string, Closure(Connection): void}>>
     */
    private static function hooks(ResolvedMap $map, array $positions): array
    {
        $dependents = $map->dependents;

        if ($dependents === null) {
            return [];
        }

        $hooks = [];

        foreach ($positions as $position => $hook) {
            $hooks[$position][] = [class_basename($dependents).'::'.$hook.'()', function (Connection $connection) use ($dependents, $hook): void {
                Container::getInstance()->make($dependents)->{$hook}($connection);
            }];
        }

        return $hooks;
    }

    private static function refuseMissingRoots(ResolvedMap $map, string $key): string
    {
        $legacyTable = SqlFragments::identifier($map->table);
        $primary = SqlFragments::identifier($map->primary->table);
        $successorKey = "{$legacyTable}.".SqlFragments::identifier($map->successorKey);
        $missing = "FROM {$legacyTable} WHERE {$successorKey} IS NULL OR NOT EXISTS (SELECT FROM {$primary} WHERE {$primary}.".SqlFragments::identifier($map->primary->key)." = {$successorKey})";
        $legacyKey = "{$legacyTable}.".SqlFragments::identifier($key);
        $refusal = SqlFragments::literal("Compatibility map '{$map->table}' cannot install: ")
            .' || missing || '.SqlFragments::literal(" {$map->table} rows have no {$map->primary->table} row that their {$map->successorKey} names; the first are {$key} ")
            .' || first_keys || '.SqlFragments::literal('. Backfill them before install().');

        return "DO \$compatibility\$\nDECLARE\n  missing bigint;\n  first_keys text;\nBEGIN\n"
            ."  SELECT count(*) INTO missing {$missing};\n"
            ."  IF missing > 0 THEN\n"
            ."    SELECT string_agg(CAST(missing_key AS text), ', ' ORDER BY missing_key) INTO first_keys FROM (SELECT {$legacyKey} {$missing} ORDER BY {$legacyKey} LIMIT 5) AS first_missing (missing_key);\n"
            ."    RAISE EXCEPTION '%', {$refusal};\n"
            ."  END IF;\nEND\n\$compatibility\$";
    }

    public static function hiddenBeforeTheLock(ResolvedMap $map, ConnectionInterface $connection): null|string
    {
        $key = (new $map->model)->getKeyName();
        $hidden = self::hiddenRows($map);

        if ($hidden === null || self::kind(
            connection: $connection,
            table: $map->table,
        ) !== 'r' || $connection->scalar(
            query: "select exists (select from pg_attribute where attrelid = to_regclass(quote_ident(current_schema()) || '.' || ?) and attname = ? and attnum > 0 and not attisdropped)",
            bindings: [SqlFragments::identifier($map->table), $map->successorKey],
            useReadPdo: false,
        ) !== true) {
            return null;
        }

        $legacyKey = SqlFragments::identifier($map->table).'.'.SqlFragments::identifier($key);
        $count = $connection->scalar(
            query: "SELECT count(*) {$hidden}",
            useReadPdo: false,
        );

        if (! is_int($count) || $count === 0) {
            return null;
        }

        return "hides {$count} {$map->table} rows, because a hide() rule matches their {$map->primary->table} row; the first are {$key} ".implode(
            separator: ', ',
            array: array_map(
                callback: fn (object $row) => (string) (((array) $row)['key'] ?? ''),
                array: $connection->select(
                    query: "SELECT {$legacyKey} AS key {$hidden} ORDER BY {$legacyKey} LIMIT 5",
                    useReadPdo: false,
                ),
            ),
        ).'. Install read this count before it took its lock.';
    }

    private static function hiddenRows(ResolvedMap $map): null|string
    {
        $conditions = ViewCompiler::hidden($map);

        if ($conditions === []) {
            return null;
        }

        $legacyTable = SqlFragments::identifier($map->table);
        $primary = SqlFragments::identifier($map->primary->table);

        return "FROM {$legacyTable} WHERE EXISTS (SELECT FROM {$primary} WHERE {$primary}.".SqlFragments::identifier($map->primary->key)." = {$legacyTable}.".SqlFragments::identifier($map->successorKey).' AND ('
            .implode(' OR ', array_map(fn (string $condition) => 'COALESCE(('.$condition.'), false)', $conditions)).'))';
    }

    private static function reportHidden(ResolvedMap $map, string $key): null|string
    {
        $hidden = self::hiddenRows($map);

        if ($hidden === null) {
            return null;
        }

        $legacyTable = SqlFragments::identifier($map->table);
        $legacyKey = "{$legacyTable}.".SqlFragments::identifier($key);
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
     * @param  list<string>  $external
     */
    private static function capture(ConnectionInterface $connection, ResolvedMap $map, array $legacy, array $mapped, array $external): string
    {
        $table = SqlFragments::identifier($map->table);
        $relation = "to_regclass(quote_ident(current_schema()) || '.' || ?)";
        $columns = json_encode($mapped, JSON_THROW_ON_ERROR);
        $collations = $external === [] ? [] : ViewCompiler::collations($connection, $map->table);
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
             order by constraint_row.conname",
            [$table, $columns],
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

        $comment = $connection->scalar(
            query: "select obj_description(to_regclass(quote_ident(current_schema()) || '.' || ?), 'pg_class')",
            bindings: [$table],
        );

        return json_encode([
            'captured' => self::CAPTURED,
            'comment' => is_string($comment) ? $comment : null,
            'notNull' => array_values(array_filter($mapped, fn (string $column) => $legacy[$column]->notNull)),
            'generated' => $generated,
            'constraints' => array_values($constraints),
            'indexes' => array_values($indexes),
            ...($external === [] ? [] : ['external' => array_combine($external, array_map(fn (string $name) => ['type' => $legacy[$name]->type, 'default' => $legacy[$name]->default, 'collation' => ($collations[$name]['declared'] ?? false) ? $collations[$name]['name'] : null], $external))]),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return null|array{comment: null|string, notNull: list<string>, generated: array<string, string>, constraints: list<array{name: string, type: string, definition: string, validated: bool}>, indexes: list<string>, external: array<string, array{type: string, default: null|string, collation: null|string}>}
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
                $external[$name] = ['type' => $column['type'], 'default' => is_string($column['default'] ?? null) ? $column['default'] : null, 'collation' => is_string($column['collation'] ?? null) ? $column['collation'] : null];
            }
        }

        return [
            'comment' => is_string($captured['comment'] ?? null) ? $captured['comment'] : null,
            'notNull' => array_values(array_filter(is_array($captured['notNull'] ?? null) ? $captured['notNull'] : [], is_string(...))),
            'generated' => $generated,
            'constraints' => $constraints,
            'indexes' => array_values(array_filter(is_array($captured['indexes'] ?? null) ? $captured['indexes'] : [], is_string(...))),
            'external' => $external,
        ];
    }

    /**
     * @param  array{comment: null|string, notNull: list<string>, generated: array<string, string>, constraints: list<array{name: string, type: string, definition: string, validated: bool}>, indexes: list<string>, external: array<string, array{type: string, default: null|string, collation: null|string}>}  $captured
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
            "DO \$compatibility\$\nDECLARE\n  granted text;\n  inherited text;\nBEGIN\n"
                ."  FOR inherited IN SELECT DISTINCT {$grantee} FROM pg_class relation CROSS JOIN LATERAL aclexplode(relation.relacl) AS granted WHERE relation.oid = {$relation} AND granted.grantee <> relation.relowner LOOP\n"
                .'    EXECUTE '.SqlFragments::literal('REVOKE ALL ON '.$view.' FROM ')." || inherited;\n"
                ."  END LOOP;\n"
                ."  FOR granted IN SELECT json_array_elements_text(CAST(current_setting('compatibility.grants') AS json)) LOOP\n"
                ."    EXECUTE granted;\n"
                ."  END LOOP;\n"
                ."END\n\$compatibility\$",
        ];
    }

    private static function validate(string $table, string $constraint): string
    {
        return "ALTER TABLE {$table} VALIDATE CONSTRAINT ".SqlFragments::identifier($constraint);
    }

    /**
     * @return list<object{name: string, table: string, referencedColumn: string}>
     */
    private static function foreignKeysTo(ConnectionInterface $connection, string $table): array
    {
        $rows = $connection->select(
            "select foreign_key.conname as name,
                    referencing_table.relname as \"table\",
                    referenced_column.attname as referenced_column
             from pg_constraint foreign_key
             join pg_class referencing_table on referencing_table.oid = foreign_key.conrelid
             join pg_namespace referencing_schema on referencing_schema.oid = referencing_table.relnamespace
             join pg_attribute referenced_column on referenced_column.attrelid = foreign_key.confrelid and referenced_column.attnum = foreign_key.confkey[cardinality(foreign_key.confkey)]
             where foreign_key.contype = 'f' and foreign_key.conparentid = 0
               and foreign_key.confrelid = to_regclass(quote_ident(current_schema()) || '.' || ?)
             order by referencing_schema.nspname, referencing_table.relname, foreign_key.conname",
            [SqlFragments::identifier($table)],
        );

        return array_values(array_map(fn (object $row) => (object) [
            'name' => (string) $row->name,
            'table' => (string) $row->table,
            'referencedColumn' => (string) $row->referenced_column,
        ], $rows));
    }

    private static function sequence(ConnectionInterface $connection, string $table, string $column): null|string
    {
        $sequence = $connection->scalar('select pg_get_serial_sequence(?, ?)', [SqlFragments::identifier($table), $column]);

        return is_string($sequence) ? $sequence : null;
    }

    public static function kind(ConnectionInterface $connection, string $table): null|string
    {
        $kind = $connection->scalar(
            query: "select relkind::text from pg_class where oid = to_regclass(quote_ident(current_schema()) || '.' || ?)",
            bindings: [SqlFragments::identifier($table)],
            useReadPdo: false,
        );

        return is_string($kind) ? $kind : null;
    }
}
