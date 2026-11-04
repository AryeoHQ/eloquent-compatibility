<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Closure;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Support\Database\Eloquent\Compatibility\Definition\Identifier;
use Support\Database\Eloquent\Compatibility\Definition\Membership;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Write\Backfiller;
use Support\Database\Eloquent\Compatibility\Write\ExternalAttributes;

final readonly class Statements
{
    private const array ACTIONS = ['r' => 'RESTRICT', 'c' => 'CASCADE', 'n' => 'SET NULL', 'd' => 'SET DEFAULT'];

    private const string CAPTURED = 'compatibility install';

    private const string RETAINED_KEY = 'compatibility retained key';

    private const int CHUNK = 1000;

    /**
     * @param  list<string>  $transaction
     * @param  list<string>  $afterwards
     * @param  array<int, list<Closure(ConnectionInterface): void>>  $steps
     * @param  list<string>  $exclusive
     */
    public function __construct(public string $operation, public array $transaction, public array $afterwards = [], public array $steps = [], public array $exclusive = []) {}

    public static function prepare(ResolvedMap $map, ConnectionInterface $connection): self
    {
        $preparer = new Preparer(new ColumnCatalog($connection));
        $prepared = array_map($preparer->statements(...), $map->branches === [] ? [$map] : array_values($map->branches));

        return new self('prepare', array_merge(...array_map(fn (self $statements) => $statements->transaction, $prepared)), [...array_merge(...array_map(fn (self $statements) => $statements->afterwards, $prepared)), ...$preparer->updatedAtIndex($map)]);
    }

    public static function retain(ResolvedMap $map, ConnectionInterface $connection): self
    {
        $legacyTable = SqlFragments::identifier($map->table);
        $retainedKey = SqlFragments::identifier((string) $map->retainedKey);
        $primary = SqlFragments::identifier($map->primary->table);
        $primaryKey = SqlFragments::identifier($map->primary->key);
        $columnCatalog = new ColumnCatalog($connection);
        $key = (new $map->model)->getKeyName();
        $legacyKey = SqlFragments::identifier($key);
        $keyType = $columnCatalog->type($map->table, $key) ?? throw InvalidCompatibilityMap::because($map->table, "{$map->table} has no column {$key}");
        $index = SqlFragments::objectName($map->table, ViewCompiler::retainedTable($map).'_pkey');

        $theirs = InvalidCompatibilityMap::because($map->table, "{$map->table} already has a column {$map->retainedKey}, which install() adds as the key of the retained table");
        $taken = self::retainedIndexTaken($map);

        if (self::retainedKeyOf($connection, $map) === 'theirs') {
            throw $theirs;
        }

        return new self('retain', [
            "LOCK TABLE {$legacyTable} IN ACCESS EXCLUSIVE MODE",
            "DO \$compatibility\$\nBEGIN\n"
                .'  IF EXISTS (SELECT FROM pg_attribute attribute WHERE attribute.attrelid = to_regclass(quote_ident(current_schema()) || '.SqlFragments::literal('.'.$legacyTable).') AND attribute.attname = '.SqlFragments::literal((string) $map->retainedKey).' AND attribute.attnum > 0 AND NOT attribute.attisdropped AND col_description(attribute.attrelid, attribute.attnum) IS DISTINCT FROM '.SqlFragments::literal(self::RETAINED_KEY).") THEN\n"
                ."    RAISE EXCEPTION '%', ".SqlFragments::literal($theirs->getMessage()).";\n"
                ."  END IF;\nEND\n\$compatibility\$",
            "ALTER TABLE {$legacyTable} ADD COLUMN IF NOT EXISTS {$retainedKey} ".$columnCatalog->of($map->primary->table)[$map->primary->key]->type,
            "COMMENT ON COLUMN {$legacyTable}.{$retainedKey} IS ".SqlFragments::literal(self::RETAINED_KEY),
        ], [
            "DO \$compatibility\$\nDECLARE\n  filled_through {$keyType};\n  fill_upto {$keyType};\nBEGIN\n"
                ."  LOOP\n"
                ."    SELECT chunk.{$legacyKey} INTO fill_upto FROM (SELECT {$legacyKey} FROM {$legacyTable} WHERE filled_through IS NULL OR {$legacyKey} > filled_through ORDER BY {$legacyKey} LIMIT ".self::CHUNK.") AS chunk ORDER BY chunk.{$legacyKey} DESC LIMIT 1;\n"
                ."    EXIT WHEN fill_upto IS NULL;\n"
                ."    UPDATE {$legacyTable} SET {$retainedKey} = {$primary}.{$primaryKey} FROM {$primary} WHERE {$primary}.".SqlFragments::identifier((string) $map->identifiers[$key]->column())." = {$legacyTable}.{$legacyKey}"
                .($map->membership === null ? '' : ' AND '.$map->membership->of($map->primary->table))
                ." AND {$legacyTable}.{$retainedKey} IS DISTINCT FROM {$primary}.{$primaryKey} AND {$legacyTable}.{$legacyKey} <= fill_upto AND (filled_through IS NULL OR {$legacyTable}.{$legacyKey} > filled_through);\n"
                ."    filled_through := fill_upto;\n"
                ."    COMMIT;\n"
                ."  END LOOP;\n"
                ."END\n\$compatibility\$",
            "DO \$compatibility\$\nBEGIN\n"
                .'  IF EXISTS ('.self::retainedIndex($map).' AND NOT retained_index.indisvalid) THEN'."\n"
                .'    DROP INDEX '.SqlFragments::identifier($index).";\n"
                .'  ELSIF '.self::relationIn($index).' IS NOT NULL AND NOT EXISTS ('.self::retainedIndex($map).' AND retained_index.indisvalid) THEN'."\n"
                ."    RAISE EXCEPTION '%', ".SqlFragments::literal($taken->getMessage()).";\n"
                ."  END IF;\nEND\n\$compatibility\$",
            'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS '.SqlFragments::identifier($index)." ON {$legacyTable} ({$retainedKey})",
        ]);
    }

    public static function unretain(ResolvedMap $map): string
    {
        $relation = 'to_regclass(quote_ident(current_schema()) || '.SqlFragments::literal('.'.SqlFragments::identifier($map->table)).')';

        return "DO \$compatibility\$\nBEGIN\n"
            ."  IF EXISTS (SELECT FROM pg_attribute attribute JOIN pg_class relation ON relation.oid = attribute.attrelid WHERE relation.oid = {$relation} AND relation.relkind = 'r' AND attribute.attname = ".SqlFragments::literal((string) $map->retainedKey).' AND NOT attribute.attisdropped AND col_description(relation.oid, attribute.attnum) = '.SqlFragments::literal(self::RETAINED_KEY).") THEN\n"
            .'    ALTER TABLE '.SqlFragments::identifier($map->table).' DROP COLUMN '.SqlFragments::identifier((string) $map->retainedKey).";\n"
            ."  END IF;\nEND\n\$compatibility\$";
    }

    public static function install(ResolvedMap $map, ConnectionInterface $connection, bool $catchingUp = false): self
    {
        if (! $catchingUp && (new Backfiller($map, $connection))->remembered() !== null) {
            throw InvalidCompatibilityMap::cannot($map->table, 'install', "the last compatibility:backfill of {$map->table} left a watermark, and Compatibility::install() catches up the legacy writes since it and deletes the successor rows of hard-deleted legacy rows before it takes the lock, and no printed SQL runs that catch-up. Run Compatibility::install() instead");
        }

        $branched = $map->branches !== [];
        $served = $branched ? array_values($map->branches) : [$map];
        $retainedKeyOf = $branched ? 'absent' : self::retainedKeyOf($connection, $map);
        $prefilled = $retainedKeyOf === 'ours';
        $columnCatalog = new ColumnCatalog($connection, hidden: $prefilled ? [$map->table => [(string) $map->retainedKey]] : []);
        $owned = self::ownedConstants($connection, $map);
        $view = (new ViewCompiler($columnCatalog, $connection))->create($map, $owned);

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
        $legacy = array_diff_key($columnCatalog->of($map->table), array_flip($owned));
        $key = (new $map->model)->getKeyName();

        $external = array_values(array_filter($map->externals(), fn (string $name) => array_key_exists($name, $legacy)));

        foreach ($external as $name) {
            if ($legacy[$name]->generated) {
                throw InvalidCompatibilityMap::because($map->table, "{$map->table}.{$name} is a generated column, and attribute '{$name}' is external(), so its value would come from outside the database and nothing would keep it generated from its columns; serve it with readAs() and readOnly()");
            }

            $ownedSequence = self::sequence($connection, $map->table, $name);

            if ($ownedSequence !== null) {
                throw InvalidCompatibilityMap::because($map->table, "{$map->table}.{$name} owns the sequence {$ownedSequence}, and attribute '{$name}' is external(), so install() would drop the sequence with the column and uninstall() could not restore the default that reads it; detach it with ALTER SEQUENCE {$ownedSequence} OWNED BY NONE first");
            }
        }

        if ($branched && $retainedKey !== $key) {
            throw InvalidCompatibilityMap::because($map->table, 'retainedIn() keys '.ViewCompiler::retainedTable($map)." by {$retainedKey}, and install() keeps the retained rows of a map over ".self::successorNames($map)." under the legacy key {$key}");
        }

        if ($retainedKeyOf === 'theirs') {
            throw InvalidCompatibilityMap::because($map->table, "{$map->table} already has a column {$retainedKey}, which install() adds as the key of the retained table");
        }

        $membership = $map->membership;

        foreach ($served as $branch) {
            if ($branch->membership !== null) {
                self::refuseSharedAlias($connection, $map, $branch->membership);
            }
        }

        $repoints = [];
        $validations = [];
        $moved = [];
        $constants = [];
        $notValid = [];

        foreach (self::foreignKeysTo($connection, $map->table) as $foreignKey) {
            if (array_key_exists($foreignKey->referencedColumn, $map->attributes)) {
                throw InvalidCompatibilityMap::because($map->table, "the foreign key {$foreignKey->name} on {$foreignKey->table} references {$map->table}.{$foreignKey->referencedColumn}, which install() moves to a successor; reference an identifier instead");
            }

            if ($branched && array_key_exists($foreignKey->referencedColumn, $map->identifiers)) {
                throw InvalidCompatibilityMap::because($map->table, "the foreign key {$foreignKey->name} on {$foreignKey->table} references {$map->table}.{$foreignKey->referencedColumn}, and install() serves {$map->table} from ".self::successorNames($map).', so no one table can take the reference');
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
                $moved[] = [$installed, $foreignKey->name];

                if ($foreignKey->validated) {
                    $validations[] = self::validate($installed, $foreignKey->name);
                } else {
                    $notValid[] = [$installed, $foreignKey->name];
                }
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

        [$refuseOutsiders, $check, $validateCheck] = self::membershipChecks($served, 'install');

        $retainedPrimaryKeyName = SqlFragments::objectName($map->table, ViewCompiler::retainedTable($map).'_pkey');
        $retainedPrimaryKey = SqlFragments::identifier($retainedPrimaryKeyName);
        $retainedForeignKey = SqlFragments::identifier(SqlFragments::objectName($map->table, ViewCompiler::retainedTable($map).'_'.$retainedKey.'_foreign'));
        $retainedIndex = $branched ? 'absent' : $connection->scalar('select case when '.self::relationIn($retainedPrimaryKeyName)." is null then 'absent' when exists (".self::retainedIndex($map)." and retained_index.indisvalid) then 'ours' else 'theirs' end");

        if ($retainedIndex === 'theirs') {
            throw self::retainedIndexTaken($map);
        }

        $indexed = $prefilled && $retainedIndex === 'ours';
        $retainedNotNullName = SqlFragments::objectName($map->table, ViewCompiler::retainedTable($map).'_'.$retainedKey.'_not_null');
        $retainedNotNull = SqlFragments::identifier($retainedNotNullName);
        $retainedConstraints = "ALTER TABLE {$retainedTable}\n  ".($indexed ? "ADD CONSTRAINT {$retainedNotNull} CHECK (".SqlFragments::identifier($retainedKey).' IS NOT NULL) NOT VALID' : "ADD CONSTRAINT {$retainedPrimaryKey} PRIMARY KEY (".SqlFragments::identifier($retainedKey).')').',';
        $retainedPrimaryKeyExclusive = $indexed ? [
            "ALTER TABLE {$retainedTable} ADD CONSTRAINT {$retainedPrimaryKey} PRIMARY KEY USING INDEX {$retainedPrimaryKey}",
            "ALTER TABLE {$retainedTable} DROP CONSTRAINT {$retainedNotNull}",
        ] : [];
        $verifications = [
            ...($branched || $map->morphs !== [] ? [fn (ConnectionInterface $connection) => (new Backfiller($map, $connection))->refuseMisplaced()] : []),
            ...array_map(fn (string $name) => fn (ConnectionInterface $connection) => ExternalAttributes::verify($map, $connection, $name), $external),
        ];
        $mapped = array_values(array_diff(array_keys($legacy), $map->legacyOnly(array_keys($legacy)), $branched ? [$retainedKey] : []));
        usort($mapped, fn (string $first, string $second) => $legacy[$second]->alwaysGenerated <=> $legacy[$first]->alwaysGenerated);
        $defaults = array_filter($legacy, fn (Column $column, string $name) => $column->default !== null && ! ($map->attributes[$name] ?? null)?->isExternal(), ARRAY_FILTER_USE_BOTH);
        $hidden = array_values(array_filter(array_map(fn (ResolvedMap $branch) => self::reportHidden($branch, $key), $served), fn (null|string $hidden) => $hidden !== null));
        [$readGrants, $grant] = self::grants($map->table, $legacyTable, array_keys(array_filter($legacy, fn (Column $column, string $name) => ! ($map->attributes[$name] ?? null)?->isExternal(), ARRAY_FILTER_USE_BOTH)));

        $preparer = new Preparer($columnCatalog);
        $unfinished = array_merge(...array_map(fn (ResolvedMap $branch) => $preparer->unfinished($branch), $served));

        return new self('install', [
            'LOCK TABLE '.implode(', ', [$legacyTable, ...array_map(fn (ResolvedMap $branch) => SqlFragments::identifier($branch->primary->table), $served)]).' IN SHARE ROW EXCLUSIVE MODE',
            ...$unfinished,
            $branched ? self::refuseMissingBranches($map, $key) : self::refuseMissingPrimaries($map, $key),
            ...$refuseOutsiders,
            ...$hidden,
            ...($branched ? [] : [
                ...($prefilled ? [] : ["ALTER TABLE {$legacyTable} ADD COLUMN ".SqlFragments::identifier($retainedKey).' '.$columnCatalog->of($map->primary->table)[$map->primary->key]->type]),
                "UPDATE {$legacyTable} SET ".SqlFragments::identifier($retainedKey)." = {$primary}.{$primaryKey} FROM {$primary} WHERE {$primary}.".SqlFragments::identifier((string) $map->identifiers[$key]->column())." = {$legacyTable}.".SqlFragments::identifier($key)
                    .($membership === null ? '' : ' AND '.$membership->of($map->primary->table))
                    .($prefilled ? " AND {$legacyTable}.".SqlFragments::identifier($retainedKey)." IS DISTINCT FROM {$primary}.{$primaryKey}" : ''),
            ]),
            ...array_column($constants, 0),
            ...$repoints,
            ...$sequences,
            $readGrants,
            ...($mapped === [] ? [] : ["ALTER TABLE {$legacyTable}\n  ".implode(",\n  ", array_map(fn (string $column) => 'DROP COLUMN '.SqlFragments::identifier($column), $mapped))]),
            "ALTER TABLE {$legacyTable} RENAME TO {$retainedTable}",
            ...($branched ? [] : ["{$retainedConstraints}\n  ADD CONSTRAINT {$retainedForeignKey} FOREIGN KEY (".SqlFragments::identifier($retainedKey).") REFERENCES {$primary} ({$primaryKey}) ON DELETE CASCADE NOT VALID"]),
            "COMMENT ON TABLE {$retainedTable} IS ".SqlFragments::literal(self::capture($connection, $map, $legacy, $mapped, $moved, $notValid, array_column($constants, 1), $external)),
            $view,
            ...array_map(fn (Column $column, string $name) => "ALTER VIEW {$legacyTable} ALTER COLUMN ".SqlFragments::identifier($name).' SET DEFAULT '.$column->default, $defaults, array_keys($defaults)),
            $grant,
            ...$check,
        ], [
            ...$validations,
            ...($branched ? [] : [self::validate($retainedTable, ViewCompiler::retainedTable($map).'_'.$retainedKey.'_foreign'), ...($indexed ? [self::validate($retainedTable, $retainedNotNullName)] : [])]),
            ...$validateCheck,
        ], $verifications === [] ? [] : [1 + count($unfinished) => $verifications], $retainedPrimaryKeyExclusive);
    }

    public static function recompile(ResolvedMap $map, ConnectionInterface $connection): self
    {
        if (self::kind($connection, $map->table) !== 'v') {
            throw InvalidCompatibilityMap::because($map->table, "{$map->table} is not a view, so recompile() has nothing to replace; install() creates it");
        }

        $served = $map->branches === [] ? [$map] : array_values($map->branches);

        foreach ($served as $branch) {
            if ($branch->membership !== null) {
                self::refuseSharedAlias($connection, $map, $branch->membership);
            }
        }

        self::refuseChangedMembership($connection, $map);
        $view = (new ViewCompiler(new ColumnCatalog($connection), $connection))->replace($map);
        $retainedTable = SqlFragments::identifier(ViewCompiler::retainedTable($map));
        $identifiers = array_map(fn ($identifier) => (string) $identifier->column(), array_values($map->identifiers));
        $notValid = self::captured($connection, ViewCompiler::retainedTable($map))['notValid'] ?? [];
        $validations = [];

        foreach ($served as $branch) {
            foreach (self::foreignKeysTo($connection, $branch->primary->table) as $foreignKey) {
                $retained = $map->branches === [] && $foreignKey->qualifiedTable === $retainedTable && $foreignKey->column === $map->retainedKey && $foreignKey->referencedColumn === $map->primary->key;

                if (! $foreignKey->validated && ! in_array([$foreignKey->qualifiedTable, $foreignKey->name], $notValid, true) && ($retained || in_array($foreignKey->referencedColumn, $identifiers, true))) {
                    $validations[] = self::validate($foreignKey->qualifiedTable, $foreignKey->name);
                }
            }
        }

        $members = array_values(array_filter($served, fn (ResolvedMap $branch) => $branch->membership !== null));

        if ($members === []) {
            return new self('recompile', [$view], $validations);
        }

        $refusals = [];
        $checks = [];
        $validateChecks = [];

        foreach ($members as $branch) {
            $membership = $branch->membership ?? throw new LogicException("Compatibility map '{$map->table}' lost the membership of its {$branch->primary->table} branch.");
            [$refuseOutsiders, $check, $validateCheck] = self::membershipCheck($branch, $membership, 'recompile');
            $refusals = [...$refusals, ...$refuseOutsiders];
            $checks = [...$checks, ...($check === [] ? ['ALTER TABLE '.SqlFragments::identifier($branch->primary->table).' DROP CONSTRAINT IF EXISTS '.SqlFragments::identifier($membership->constraint())] : $check)];
            $validateChecks = [...$validateChecks, ...$validateCheck];
        }

        return new self('recompile', [
            'LOCK TABLE '.implode(', ', array_map(fn (ResolvedMap $branch) => SqlFragments::identifier($branch->primary->table), $members)).' IN SHARE ROW EXCLUSIVE MODE',
            ...$refusals,
            $view,
            ...$checks,
        ], [...$validations, ...$validateChecks]);
    }

    public static function uninstall(ResolvedMap $map, ConnectionInterface $connection): self
    {
        if (self::kind($connection, $map->table) !== 'v') {
            throw InvalidCompatibilityMap::because($map->table, "{$map->table} is not a view, so uninstall() has nothing to reverse");
        }

        if ($map->branches !== []) {
            return self::uninstallBranches($map, $connection);
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
        $locked = implode(', ', array_values(array_unique([$primary, ...array_map(fn ($related) => SqlFragments::identifier($related->table), $map->related)])));
        $collations = ViewCompiler::collations($connection, $map->table);

        $membership = $map->membership;
        $statements = [
            "LOCK TABLE {$locked} IN SHARE ROW EXCLUSIVE MODE",
            ...($membership === null ? [] : [self::refuseReclassified($map, $membership, $key)]),
            "DROP VIEW {$legacyTable}",
            "ALTER TABLE {$retainedTable} RENAME TO {$legacyTable}",
            "INSERT INTO {$legacyTable} ({$retainedKey}) SELECT {$primary}.{$primaryKey} FROM {$primary} WHERE "
                .($membership === null ? '' : $membership->of($map->primary->table).' AND ')
                ."NOT EXISTS (SELECT FROM {$legacyTable} WHERE {$legacyTable}.{$retainedKey} = {$primary}.{$primaryKey})",
            "ALTER TABLE {$legacyTable}\n  ".implode(",\n  ", [
                ...array_map(fn (string $column) => 'ADD COLUMN '.SqlFragments::identifier($column).' '.$shape[$column]->type.(($collations[$column]['declared'] ?? false) ? ' COLLATE '.$collations[$column]['name'] : ''), $columns),
                ...array_map(fn (array $column, string $name) => 'ADD COLUMN '.SqlFragments::identifier($name).' '.$column['type'].($column['collation'] === null ? '' : ' COLLATE '.$column['collation']), $external, array_keys($external)),
            ]),
            "UPDATE {$legacyTable} AS {$retainedTable} SET\n  ".implode(",\n  ", array_map(fn (string $column) => SqlFragments::identifier($column).' = '.$served[$column], $columns))
                ."\nFROM {$primary}".implode('', array_map(fn (string $lateral) => "\n".$lateral, ViewCompiler::laterals($map)))
                ."\nWHERE {$primary}.{$primaryKey} = {$retainedTable}.{$retainedKey}",
        ];
        $filled = count($statements) - 1;

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
        $statements = [...$statements, ...$replayed, ...($captured === null || $captured['backfill'] === null ? [] : Backfiller::refill($map->table, $key, $shape[$key]->type))];
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

                if ($captured === null || ! in_array([$foreignKey->qualifiedTable, $foreignKey->name], $captured['notValid'], true)) {
                    $repointed[] = self::validate($on, $foreignKey->name);
                }
            }
        }

        foreach ($constants as [$table, $constant]) {
            $statements[] = 'ALTER TABLE '.($table === $retainedTable ? $legacyTable : $table).' DROP COLUMN '.SqlFragments::identifier($constant);
        }

        if ($membership !== null) {
            $statements[] = "ALTER TABLE {$primary} DROP CONSTRAINT IF EXISTS ".SqlFragments::identifier($membership->constraint());
        }

        return new self('uninstall', $statements, [...$repointed, ...$validations], $external === [] ? [] : [$filled => array_map(fn (array $column, string $name) => fn (ConnectionInterface $connection) => ExternalAttributes::restore($map, $connection, $name, $column['type']), $external, array_keys($external))]);
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
        return [...$this->transaction, ...$this->afterwards, ...$this->exclusive];
    }

    private static function uninstallBranches(ResolvedMap $map, ConnectionInterface $connection): self
    {
        $columnCatalog = new ColumnCatalog($connection);
        $shape = $columnCatalog->of($map->table);
        $compiler = new ViewCompiler($columnCatalog, $connection);
        $legacyTable = SqlFragments::identifier($map->table);
        $retainedTable = SqlFragments::identifier(ViewCompiler::retainedTable($map));
        $key = (new $map->model)->getKeyName();
        $legacyKey = SqlFragments::identifier($key);
        $branches = array_values($map->branches);
        $captured = self::captured($connection, ViewCompiler::retainedTable($map));
        $served = array_map(fn (ResolvedMap $branch) => array_diff_key($compiler->served($branch, $shape, $map->externals()), [$key => true]), $branches);
        $generated = $captured === null ? [] : array_intersect_key($captured['generated'], $served[0]);
        $columns = array_keys(array_diff_key($served[0], $generated));
        $defaults = array_filter(array_intersect_key($shape, array_flip($columns)), fn (Column $column) => $column->default !== null);
        $external = $captured === null ? [] : array_intersect_key($captured['external'], array_flip($map->externals()));
        $held = fn (ResolvedMap $branch) => SqlFragments::identifier($branch->primary->table).'.'.SqlFragments::identifier((string) $branch->identifiers[$key]->column());
        $locked = implode(', ', array_values(array_unique([
            ...array_map(fn (ResolvedMap $branch) => SqlFragments::identifier($branch->primary->table), $branches),
            ...array_merge(...array_map(fn (ResolvedMap $branch) => array_map(fn ($related) => SqlFragments::identifier($related->table), $branch->related), $branches)),
        ])));
        $collations = ViewCompiler::collations($connection, $map->table);
        $statements = [
            "LOCK TABLE {$locked} IN SHARE ROW EXCLUSIVE MODE",
            "DROP VIEW {$legacyTable}",
            "ALTER TABLE {$retainedTable} RENAME TO {$legacyTable}",
            "DELETE FROM {$legacyTable} WHERE ".implode(' AND ', array_map(fn (ResolvedMap $branch) => 'NOT EXISTS (SELECT FROM '.SqlFragments::identifier($branch->primary->table)." WHERE {$held($branch)} = {$legacyTable}.{$legacyKey} AND ".(string) $branch->membership?->of($branch->primary->table).')', $branches)),
            ...array_map(fn (ResolvedMap $branch) => "INSERT INTO {$legacyTable} ({$legacyKey}) SELECT {$held($branch)} FROM ".SqlFragments::identifier($branch->primary->table)
                .' WHERE '.(string) $branch->membership?->of($branch->primary->table)
                ." AND NOT EXISTS (SELECT FROM {$legacyTable} WHERE {$legacyTable}.{$legacyKey} = {$held($branch)})", $branches),
            ...($columns === [] && $external === [] ? [] : ["ALTER TABLE {$legacyTable}\n  ".implode(",\n  ", [
                ...array_map(fn (string $column) => 'ADD COLUMN '.SqlFragments::identifier($column).' '.$shape[$column]->type.(($collations[$column]['declared'] ?? false) ? ' COLLATE '.$collations[$column]['name'] : ''), $columns),
                ...array_map(fn (array $column, string $name) => 'ADD COLUMN '.SqlFragments::identifier($name).' '.$column['type'].($column['collation'] === null ? '' : ' COLLATE '.$column['collation']), $external, array_keys($external)),
            ])]),
            ...array_map(fn (ResolvedMap $branch, array $reads) => "UPDATE {$legacyTable} AS {$retainedTable} SET\n  ".implode(",\n  ", array_map(fn (string $column) => SqlFragments::identifier($column).' = '.$reads[$column], $columns))
                ."\nFROM ".SqlFragments::identifier($branch->primary->table).implode('', array_map(fn (string $lateral) => "\n".$lateral, ViewCompiler::laterals($branch)))
                ."\nWHERE {$held($branch)} = {$retainedTable}.{$legacyKey} AND ".(string) $branch->membership?->of($branch->primary->table), $columns === [] ? [] : $branches, $columns === [] ? [] : $served),
        ];
        $restoredAt = count($statements) - 1;
        $restoredDefaults = [...array_map(fn (Column $column) => (string) $column->default, $defaults), ...array_filter(array_map(fn (array $column) => $column['default'], $external), is_string(...))];

        if ($restoredDefaults !== []) {
            $statements[] = "ALTER TABLE {$legacyTable}\n  ".implode(",\n  ", array_map(fn (string $default, string $name) => 'ALTER COLUMN '.SqlFragments::identifier($name).' SET DEFAULT '.$default, $restoredDefaults, array_keys($restoredDefaults)));
        }

        if ($generated !== []) {
            $statements[] = "ALTER TABLE {$legacyTable}\n  ".implode(",\n  ", array_map(fn (string $clause, string $name) => 'ADD COLUMN '.SqlFragments::identifier($name).' '.$shape[$name]->type.' '.$clause, $generated, array_keys($generated)));
        }

        $unique = array_map(fn (string $name) => 'ADD CONSTRAINT '.SqlFragments::identifier(SqlFragments::objectName($map->table, $map->table.'_'.$name.'_unique')).' UNIQUE ('.SqlFragments::identifier($name).')', array_values(array_diff(array_keys($map->identifiers), [$key])));

        if ($unique !== []) {
            $statements[] = "ALTER TABLE {$legacyTable}\n  ".implode(",\n  ", $unique);
        }

        [$replayed, $validations] = $captured === null ? [[], []] : self::replay($captured, $legacyTable, [...array_keys($served[0]), ...array_keys($external)]);

        foreach ($branches as $branch) {
            if ($branch->membership !== null) {
                $replayed[] = 'ALTER TABLE '.SqlFragments::identifier($branch->primary->table).' DROP CONSTRAINT IF EXISTS '.SqlFragments::identifier($branch->membership->constraint());
            }
        }

        return new self('uninstall', [...$statements, ...$replayed, ...($captured === null || $captured['backfill'] === null ? [] : Backfiller::refill($map->table, $key, $shape[$key]->type))], $validations, $external === [] ? [] : [$restoredAt => array_map(fn (array $column, string $name) => fn (ConnectionInterface $connection) => ExternalAttributes::restore($map, $connection, $name, $column['type']), $external, array_keys($external))]);
    }

    private static function successorNames(ResolvedMap $map): string
    {
        return implode(' and ', array_map(class_basename(...), array_keys($map->branches)));
    }

    /**
     * @param  list<ResolvedMap>  $served
     * @return array{list<string>, list<string>, list<string>}
     */
    private static function membershipChecks(array $served, string $operation): array
    {
        $checks = [[], [], []];

        foreach ($served as $branch) {
            if ($branch->membership !== null) {
                [$refuseOutsiders, $check, $validateCheck] = self::membershipCheck($branch, $branch->membership, $operation);
                $checks = [[...$checks[0], ...$refuseOutsiders], [...$checks[1], ...$check], [...$checks[2], ...$validateCheck]];
            }
        }

        return $checks;
    }

    private static function refuseMissingBranches(ResolvedMap $map, string $key): string
    {
        $legacyTable = SqlFragments::identifier($map->table);
        $held = [];

        foreach ($map->branches as $branch) {
            $primary = SqlFragments::identifier($branch->primary->table);
            $matches = [];

            foreach ($branch->identifiers as $name => $identifier) {
                $matches[] = "{$primary}.".SqlFragments::identifier((string) $identifier->column()).($name === $key ? ' = ' : ' IS NOT DISTINCT FROM ')."{$legacyTable}.".SqlFragments::identifier($name);
            }

            if ($branch->membership !== null) {
                $matches[] = $branch->membership->of($branch->primary->table);
            }

            $held[] = "CAST(EXISTS (SELECT FROM {$primary} WHERE ".implode(' AND ', $matches).') AS integer)';
        }

        $legacyKey = "{$legacyTable}.".SqlFragments::identifier($key);
        $holding = implode(' + ', $held);
        $tables = implode(' or ', array_map(fn (ResolvedMap $branch) => $branch->primary->table, $map->branches));
        $identifiers = implode(' and ', array_keys($map->identifiers));
        $refusal = fn (string $count, string $problem) => SqlFragments::literal("Compatibility map '{$map->table}' cannot install: ")
            ." || {$count} || ".SqlFragments::literal(" {$map->table} rows {$problem}; the first are {$key} ")
            .' || first_keys || '.SqlFragments::literal('. Backfill them before install().');
        $block = fn (string $count, string $condition, string $problem) => "  SELECT count(*) INTO {$count} FROM {$legacyTable} WHERE ({$holding}) {$condition};\n"
            ."  IF {$count} > 0 THEN\n"
            ."    SELECT string_agg(CAST(found_key AS text), ', ' ORDER BY found_key) INTO first_keys FROM (SELECT {$legacyKey} FROM {$legacyTable} WHERE ({$holding}) {$condition} ORDER BY {$legacyKey} LIMIT 5) AS first_found (found_key);\n"
            ."    RAISE EXCEPTION '%', {$refusal($count, $problem)};\n"
            ."  END IF;\n";

        return "DO \$compatibility\$\nDECLARE\n  missing bigint;\n  doubled bigint;\n  first_keys text;\nBEGIN\n"
            .$block('missing', '= 0', "have no {$tables} row with the same {$identifiers}")
            .$block('doubled', '> 1', "have a row with the same {$identifiers} in more than one of {$tables}")
            ."END\n\$compatibility\$";
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
        $claim = self::claim($membership);

        foreach (self::installed($connection) as $name => $captured) {
            if ($name !== ViewCompiler::retainedTable($map) && (($captured['membership'] ?? null) === $claim || in_array($claim, is_array($captured['memberships'] ?? null) ? $captured['memberships'] : [], true))) {
                throw InvalidCompatibilityMap::because($map->table, "the installed map retained in {$name} already serves the {$membership->table} rows whose {$membership->column} is '{$membership->alias}', and two installed maps cannot share a morph alias");
            }
        }
    }

    private static function refuseChangedMembership(ConnectionInterface $connection, ResolvedMap $map): void
    {
        $retainedTable = ViewCompiler::retainedTable($map);
        $captured = self::installed($connection)[$retainedTable] ?? null;
        $recorded = $map->branches === []
            ? (is_array($captured['membership'] ?? null) ? [$captured['membership']] : [])
            : (is_array($captured['memberships'] ?? null) ? array_values($captured['memberships']) : []);
        $claims = [];

        foreach ($map->branches === [] ? [$map] : $map->branches as $branch) {
            if ($branch->membership !== null) {
                $claims[] = self::claim($branch->membership);
            }
        }

        if ($captured === null || $recorded === $claims) {
            return;
        }

        $describe = fn (array $memberships): string => $memberships === [] ? 'every row of its successor' : implode(' and ', array_map(fn (array $membership) => 'the '.$membership['table'].' rows whose '.$membership['column']." is '".$membership['alias']."'", $memberships));

        throw InvalidCompatibilityMap::because($map->table, 'install() recorded that it serves '.$describe($recorded).', and the map now serves '.$describe($claims)."; recompile() cannot move the generated columns of repointed foreign keys or the record in {$retainedTable}, so uninstall() and install() the map to change it");
    }

    private static function refuseReclassified(ResolvedMap $map, Membership $membership, string $key): string
    {
        $retainedTable = SqlFragments::identifier(ViewCompiler::retainedTable($map));
        $primary = SqlFragments::identifier($map->primary->table);
        $legacyColumn = (string) $map->identifiers[$key]->column();
        $legacyKey = "{$primary}.".SqlFragments::identifier($legacyColumn);
        $reclassified = "FROM {$retainedTable} JOIN {$primary} ON {$primary}.".SqlFragments::identifier($map->primary->key)." = {$retainedTable}.".SqlFragments::identifier((string) $map->retainedKey)
            ." WHERE {$primary}.".SqlFragments::identifier($membership->column).' IS DISTINCT FROM '.SqlFragments::literal($membership->alias);
        $refusal = SqlFragments::literal("Compatibility map '{$map->table}' cannot uninstall: ")
            .' || reclassified || '.SqlFragments::literal(' '.ViewCompiler::retainedTable($map)." rows belong to {$map->primary->table} rows whose {$membership->column} is no longer '{$membership->alias}'; the first are {$legacyColumn} ")
            .' || first_keys || '.SqlFragments::literal(". Restore their {$membership->column}, or delete their ".ViewCompiler::retainedTable($map).' rows, before uninstall().');

        return "DO \$compatibility\$\nDECLARE\n  reclassified bigint;\n  first_keys text;\nBEGIN\n"
            ."  SELECT count(*) INTO reclassified {$reclassified};\n"
            ."  IF reclassified > 0 THEN\n"
            ."    SELECT string_agg(CAST(reclassified_key AS text), ', ' ORDER BY reclassified_key) INTO first_keys FROM (SELECT {$legacyKey} {$reclassified} ORDER BY {$legacyKey} LIMIT 5) AS first_reclassified (reclassified_key);\n"
            ."    RAISE EXCEPTION '%', {$refusal};\n"
            ."  END IF;\nEND\n\$compatibility\$";
    }

    /**
     * @return list<string>
     */
    private static function ownedConstants(ConnectionInterface $connection, ResolvedMap $map): array
    {
        $owned = [];

        foreach (self::installed($connection) as $captured) {
            foreach (self::pairs($captured['constants'] ?? null) as [$table, $constant]) {
                if ($table === SqlFragments::identifier($map->table)) {
                    $owned[] = $constant;
                }
            }
        }

        return array_values(array_unique($owned));
    }

    /**
     * @return array<string, array<array-key, mixed>>
     */
    private static function installed(ConnectionInterface $connection): array
    {
        $installed = [];

        foreach ($connection->select(
            "select relation.relname as name, obj_description(relation.oid, 'pg_class') as comment
             from pg_class relation
             where relation.relnamespace = (select oid from pg_namespace where nspname = current_schema()) and relation.relkind = 'r'
               and obj_description(relation.oid, 'pg_class') like ?
             order by relation.relname",
            ['{"captured":"'.self::CAPTURED.'"%'],
        ) as $row) {
            $captured = json_decode((string) $row->comment, true);

            if (is_array($captured)) {
                $installed[(string) $row->name] = $captured;
            }
        }

        return $installed;
    }

    /**
     * @return array{table: string, column: string, alias: string}
     */
    private static function claim(Membership $membership): array
    {
        return ['table' => $membership->table, 'column' => $membership->column, 'alias' => $membership->alias];
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
     * @param  list<array{string, string}>  $notValid
     * @param  list<array{string, string}>  $constants
     * @param  list<string>  $external
     */
    private static function capture(ConnectionInterface $connection, ResolvedMap $map, array $legacy, array $mapped, array $moved, array $notValid, array $constants, array $external): string
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
               and not (constraint_row.contype = 'u' and cardinality(constraint_row.conkey) = 1 and constraint_row.conkey[1] in (select attnum from pg_attribute where attrelid = constraint_row.conrelid and attname in (select jsonb_array_elements_text(cast(? as jsonb)))))
             order by constraint_row.conname",
            [$table, $columns, json_encode(array_keys($map->identifiers), JSON_THROW_ON_ERROR)],
        ));

        $backfill = (new Backfiller($map, $connection))->remembered();
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
            'comment' => Backfiller::comment($connection, $map->table),
            'notNull' => array_values(array_filter($mapped, fn (string $column) => $legacy[$column]->notNull)),
            'generated' => $generated,
            'constraints' => array_values($constraints),
            'indexes' => array_values($indexes),
            'moved' => $moved,
            'notValid' => $notValid,
            ...($map->membership === null ? [] : [
                'constants' => $constants,
                ...($map->branches === []
                    ? ['membership' => self::claim($map->membership)]
                    : ['memberships' => array_values(array_map(fn (ResolvedMap $branch) => ['table' => (string) $branch->membership?->table, 'column' => (string) $branch->membership?->column, 'alias' => (string) $branch->membership?->alias], $map->branches))]),
            ]),
            ...($backfill === null ? [] : ['backfill' => $backfill]),
            ...($external === [] ? [] : ['external' => array_combine($external, array_map(fn (string $name) => ['type' => $legacy[$name]->type, 'default' => $legacy[$name]->default, 'collation' => ($collations[$name]['declared'] ?? false) ? $collations[$name]['name'] : null], $external))]),
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
     * @return null|array{comment: null|string, notNull: list<string>, generated: array<string, string>, constraints: list<array{name: string, type: string, definition: string, validated: bool}>, indexes: list<string>, moved: list<array{string, string}>, notValid: list<array{string, string}>, constants: list<array{string, string}>, external: array<string, array{type: string, default: null|string, collation: null|string}>, backfill: null|array{key: null|int|string}}
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

        $backfill = $captured['backfill'] ?? null;
        $backfilledKey = is_array($backfill) ? $backfill['key'] ?? null : null;

        return [
            'comment' => is_string($captured['comment'] ?? null) ? $captured['comment'] : null,
            'notNull' => array_values(array_filter(is_array($captured['notNull'] ?? null) ? $captured['notNull'] : [], is_string(...))),
            'generated' => $generated,
            'constraints' => $constraints,
            'indexes' => array_values(array_filter(is_array($captured['indexes'] ?? null) ? $captured['indexes'] : [], is_string(...))),
            'moved' => self::pairs($captured['moved'] ?? null),
            'notValid' => self::pairs($captured['notValid'] ?? null),
            'constants' => self::pairs($captured['constants'] ?? null),
            'external' => $external,
            'backfill' => is_array($backfill) && is_string($backfill['updatedAt'] ?? null) ? ['key' => is_int($backfilledKey) || is_string($backfilledKey) ? $backfilledKey : null] : null,
        ];
    }

    /**
     * @param  array{comment: null|string, notNull: list<string>, generated: array<string, string>, constraints: list<array{name: string, type: string, definition: string, validated: bool}>, indexes: list<string>, moved: list<array{string, string}>, notValid: list<array{string, string}>, constants: list<array{string, string}>, external: array<string, array{type: string, default: null|string, collation: null|string}>, backfill: null|array{key: null|int|string}}  $captured
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
            [...$statements, ...$captured['indexes'], $captured['backfill'] === null
                ? "COMMENT ON TABLE {$legacyTable} IS ".($captured['comment'] === null ? 'NULL' : SqlFragments::literal($captured['comment']))
                : Backfiller::rememberNow($legacyTable, $captured['comment'], $captured['backfill']['key'])],
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

    /**
     * @return 'absent'|'ours'|'theirs'
     */
    private static function retainedKeyOf(ConnectionInterface $connection, ResolvedMap $map): string
    {
        $comment = $connection->selectOne(
            "select col_description(attribute.attrelid, attribute.attnum) as comment from pg_attribute attribute where attribute.attrelid = to_regclass(quote_ident(current_schema()) || '.' || ?) and attribute.attname = ? and attribute.attnum > 0 and not attribute.attisdropped",
            [SqlFragments::identifier($map->table), (string) $map->retainedKey],
        );

        return match (true) {
            $comment === null => 'absent',
            $comment->comment === self::RETAINED_KEY => 'ours',
            default => 'theirs',
        };
    }

    private static function retainedIndex(ResolvedMap $map): string
    {
        $legacy = self::relationIn($map->table);

        return 'SELECT FROM pg_index retained_index JOIN pg_class index_relation ON index_relation.oid = retained_index.indexrelid JOIN pg_am access_method ON access_method.oid = index_relation.relam'
            .' WHERE retained_index.indexrelid = '.self::relationIn(SqlFragments::objectName($map->table, ViewCompiler::retainedTable($map).'_pkey'))
            ." AND retained_index.indrelid = {$legacy} AND retained_index.indisunique AND retained_index.indimmediate AND access_method.amname = 'btree'"
            .' AND retained_index.indnatts = 1 AND retained_index.indoption[0] = 0 AND retained_index.indpred IS NULL AND retained_index.indexprs IS NULL'
            ." AND retained_index.indkey[0] = (SELECT attribute.attnum FROM pg_attribute attribute WHERE attribute.attrelid = {$legacy} AND attribute.attname = ".SqlFragments::literal((string) $map->retainedKey).' AND NOT attribute.attisdropped)';
    }

    private static function relationIn(string $name): string
    {
        return 'to_regclass(quote_ident(current_schema()) || '.SqlFragments::literal('.'.SqlFragments::identifier($name)).')';
    }

    private static function retainedIndexTaken(ResolvedMap $map): InvalidCompatibilityMap
    {
        $index = SqlFragments::objectName($map->table, ViewCompiler::retainedTable($map).'_pkey');

        return InvalidCompatibilityMap::cannot($map->table, 'install', "{$index} already exists and is not a valid unique B-tree index on {$map->table} ({$map->retainedKey}) over every row, so install() cannot make it the primary key of ".ViewCompiler::retainedTable($map).". Rename or drop {$index}, then install again");
    }

    private static function kind(ConnectionInterface $connection, string $table): null|string
    {
        $kind = $connection->scalar("select relkind::text from pg_class where oid = to_regclass(quote_ident(current_schema()) || '.' || ?)", [SqlFragments::identifier($table)]);

        return is_string($kind) ? $kind : null;
    }
}
