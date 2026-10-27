<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Illuminate\Database\ConnectionInterface;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\Attribute;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;

final readonly class Installer
{
    public function __construct(private ConnectionInterface $connection) {}

    public function install(CompatibilityMap $map): void
    {
        $this->run($map, InstallerAction::Install);
    }

    public function recompile(CompatibilityMap $map): void
    {
        $this->run($map, InstallerAction::Recompile);
    }

    public function uninstall(CompatibilityMap $map): void
    {
        $this->run($map, InstallerAction::Uninstall);
    }

    /**
     * @return list<string>
     */
    public function statements(CompatibilityMap|ResolvedMap $map, InstallerAction $for, null|string $retainedTableIsCurrently = null): array
    {
        $resolvedMap = $map instanceof ResolvedMap ? $map : ResolvedMap::of($map);

        return match ($for) {
            InstallerAction::Install => $this->installStatements($resolvedMap, $retainedTableIsCurrently),
            InstallerAction::Recompile => $this->recompileStatements($resolvedMap, $retainedTableIsCurrently),
            InstallerAction::Uninstall => $this->uninstallStatements($resolvedMap),
        };
    }

    private function run(CompatibilityMap $map, InstallerAction $for): void
    {
        $this->connection->transaction(function () use ($map, $for): void {
            foreach ($this->statements($map, $for) as $statement) {
                $this->connection->unprepared($statement);
            }
        });
    }

    /**
     * @return list<string>
     */
    private function installStatements(ResolvedMap $resolvedMap, null|string $retainedTableIsCurrently): array
    {
        $view = $resolvedMap->previousModel->view;
        $kind = $this->relationKind($view);

        if ($kind !== null && ($view !== $retainedTableIsCurrently || ! in_array($kind, ['r', 'p'], true))) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, "a relation named {$view} already exists; use recompile() to recompile the compatibility map");
        }

        $table = $retainedTableIsCurrently ?? (string) $resolvedMap->previousModel->table;
        $readers = $this->connection->select(
            "select distinct rule.ev_class::regclass::text as name
             from pg_depend dependency join pg_rewrite rule on rule.oid = dependency.objid
             where dependency.classid = 'pg_rewrite'::regclass and dependency.refclassid = 'pg_class'::regclass
               and dependency.refobjid = to_regclass(quote_ident(current_schema()) || '.' || ?) and rule.ev_class <> dependency.refobjid
             order by 1",
            [SqlFragments::identifier($table)],
        );

        if ($readers !== []) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, "install() cannot serve {$table} through the compatibility view while other views read it directly: "
                .implode(', ', array_map(fn (object $reader) => (string) $reader->name, $readers)).'; drop them first and create them again over the compatibility view after the install');
        }

        return [...$this->applyStatements($resolvedMap, $retainedTableIsCurrently, null), ...$this->grantStatements($resolvedMap, $retainedTableIsCurrently)];
    }

    /**
     * @return list<string>
     */
    private function recompileStatements(ResolvedMap $resolvedMap, null|string $retainedTableIsCurrently): array
    {
        $view = $this->relation($resolvedMap->previousModel->view);
        $grants = $view === null ? [] : $this->viewGrants($resolvedMap, $view);
        $columns = array_map(fn (Attribute $attribute) => $attribute->column, $resolvedMap->attributes);
        $regrants = array_filter($grants, fn (object $grant) => $grant->column === null || in_array($grant->column, $columns, true));

        return [...$this->applyStatements($resolvedMap, $retainedTableIsCurrently, $view), ...array_map(fn (object $grant) => 'GRANT '.$grant->statement, array_values($regrants))];
    }

    /**
     * @return list<string>
     */
    private function applyStatements(ResolvedMap $resolvedMap, null|string $retainedTableIsCurrently, null|string $view): array
    {
        $sources = $retainedTableIsCurrently === null ? [] : [(string) $resolvedMap->previousModel->table => $retainedTableIsCurrently];

        foreach (array_unique([$retainedTableIsCurrently ?? (string) $resolvedMap->previousModel->table, ...array_map($resolvedMap->table(...), array_values($resolvedMap->currentModels()))]) as $table) {
            if ($this->connection->scalar("select relrowsecurity from pg_class where oid = to_regclass(quote_ident(current_schema()) || '.' || ?)", [SqlFragments::identifier($table)]) === true) {
                throw InvalidCompatibilityMap::because($resolvedMap->name, "{$table} has row-level security enabled, and the compatibility view and its SECURITY DEFINER triggers would bypass its policies; map only tables without row-level security");
            }
        }

        $compiledSql = (new Compiler($this->connection, $sources))->compile($resolvedMap);

        if ($view !== null) {
            $dependents = $this->connection->select(
                "select distinct case when rule.ev_class = compatibility_view.oid then pg_describe_object(dependency.classid, dependency.objid, dependency.objsubid)
                   else coalesce(rule.ev_class::regclass::text, pg_describe_object(dependency.classid, dependency.objid, dependency.objsubid)) end as name
                 from pg_class compatibility_view
                 join pg_depend dependency on (dependency.refclassid = 'pg_class'::regclass and dependency.refobjid = compatibility_view.oid)
                   or (dependency.refclassid = 'pg_type'::regclass and dependency.refobjid = compatibility_view.reltype)
                 left join pg_rewrite rule on dependency.classid = 'pg_rewrite'::regclass and rule.oid = dependency.objid
                 left join pg_trigger view_trigger on dependency.classid = 'pg_trigger'::regclass and view_trigger.oid = dependency.objid
                 where compatibility_view.oid = ?::regclass
                   and ((dependency.deptype = 'n' and rule.ev_class is distinct from compatibility_view.oid)
                     or (dependency.deptype = 'a' and (rule.oid is not null or view_trigger.tgname not like ".SqlFragments::like($resolvedMap->objectPrefix()).')))
                 order by 1',
                [$view],
            );

            if ($dependents !== []) {
                throw InvalidCompatibilityMap::because($resolvedMap->name, "recompile() cannot replace the compatibility view {$resolvedMap->previousModel->view} while other objects depend on it: "
                    .implode(', ', array_map(fn (object $dependent) => (string) $dependent->name, $dependents)).'; drop them first and create them again after the recompile');
            }
        }

        return [...$this->dropStatements($resolvedMap), ...($view === null ? [] : ['DROP VIEW '.$view]), ...$compiledSql->statements(), ...$this->revokeStatements($resolvedMap)];
    }

    /**
     * @return list<string>
     */
    private function uninstallStatements(ResolvedMap $resolvedMap): array
    {
        $view = $this->relation($resolvedMap->previousModel->view);

        return $view === null ? $this->dropStatements($resolvedMap) : [...$this->dropStatements($resolvedMap), 'DROP VIEW '.$view];
    }

    /**
     * @return list<string>
     */
    private function dropStatements(ResolvedMap $resolvedMap): array
    {
        $prefix = SqlFragments::like($resolvedMap->objectPrefix());
        $statements = [];

        $triggers = $this->connection->select(
            "select table_trigger.tgname as name, table_trigger.tgrelid::regclass::text as relation
             from pg_trigger table_trigger join pg_proc trigger_function on trigger_function.oid = table_trigger.tgfoid
             join pg_class trigger_relation on trigger_relation.oid = table_trigger.tgrelid
             where not table_trigger.tgisinternal and table_trigger.tgname like {$prefix}
               and trigger_function.pronamespace = to_regnamespace(quote_ident(current_schema()))
               and trigger_relation.relnamespace = to_regnamespace(quote_ident(current_schema()))",
        );

        foreach ($triggers as $trigger) {
            $statements[] = 'DROP TRIGGER IF EXISTS '.SqlFragments::identifier((string) $trigger->name).' ON '.$trigger->relation;
        }

        $functions = $this->connection->select(
            "select oid::regprocedure::text as signature from pg_proc where proname like {$prefix} and pronamespace = to_regnamespace(quote_ident(current_schema()))",
        );

        foreach ($functions as $function) {
            $statements[] = 'DROP FUNCTION IF EXISTS '.$function->signature;
        }

        return $statements;
    }

    /**
     * @return list<string>
     */
    private function revokeStatements(ResolvedMap $resolvedMap): array
    {
        $view = SqlFragments::identifier($resolvedMap->previousModel->view);

        $grantees = $this->connection->select(
            "select distinct CASE WHEN privilege.grantee = 0 THEN 'PUBLIC' ELSE quote_ident(pg_get_userbyid(privilege.grantee)) END as grantee
             from pg_default_acl default_privilege, aclexplode(default_privilege.defaclacl) privilege
             where default_privilege.defaclrole = (select oid from pg_roles where rolname = current_user) and default_privilege.defaclobjtype = 'r'
               and default_privilege.defaclnamespace in (0::oid, to_regnamespace(quote_ident(current_schema()))::oid) and privilege.grantee <> default_privilege.defaclrole
             order by 1",
        );

        return array_map(fn (object $grantee) => 'REVOKE ALL ON '.$view.' FROM '.$grantee->grantee, array_values($grantees));
    }

    /**
     * @return list<string>
     */
    private function grantStatements(ResolvedMap $resolvedMap, null|string $retainedTableIsCurrently): array
    {
        $grantee = "CASE WHEN privilege.grantee = 0 THEN 'PUBLIC' ELSE quote_ident(pg_get_userbyid(privilege.grantee)) END";
        $option = "CASE WHEN privilege.is_grantable THEN ' WITH GRANT OPTION' ELSE '' END";
        $installer = '(select oid from pg_roles where rolname = current_user)';
        $view = SqlFragments::identifier($resolvedMap->previousModel->view);
        $table = SqlFragments::identifier($retainedTableIsCurrently ?? (string) $resolvedMap->previousModel->table);
        $columns = array_map(fn (Attribute $attribute) => $attribute->column, $resolvedMap->attributes);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $query = "select privilege.privilege_type || ' ON ' || ? || ' TO ' || {$grantee} || {$option} as statement
             from pg_class relation, aclexplode(coalesce(relation.relacl, acldefault('r', relation.relowner))) privilege
             where relation.oid = to_regclass(quote_ident(current_schema()) || '.' || ?) and privilege.grantee <> {$installer}";
        $bindings = [$view, $table];

        if ($columns !== []) {
            $query .= "
             union all
             select privilege.privilege_type || ' (' || quote_ident(table_column.attname) || ') ON ' || ? || ' TO ' || {$grantee} || {$option}
             from pg_attribute table_column, aclexplode(table_column.attacl) privilege
             where table_column.attrelid = to_regclass(quote_ident(current_schema()) || '.' || ?) and table_column.attnum > 0 and not table_column.attisdropped
               and table_column.attname in ({$placeholders}) and privilege.grantee <> {$installer}";
            $bindings = [...$bindings, $view, $table, ...$columns];
        }

        $grants = $this->connection->select($query, $bindings);

        return array_map(fn (object $grant) => 'GRANT '.$grant->statement, array_values($grants));
    }

    /**
     * @return list<object{statement: string, column: null|string}>
     */
    private function viewGrants(ResolvedMap $resolvedMap, string $view): array
    {
        $grantee = "CASE WHEN privilege.grantee = 0 THEN 'PUBLIC' ELSE quote_ident(pg_get_userbyid(privilege.grantee)) END";
        $option = "CASE WHEN privilege.is_grantable THEN ' WITH GRANT OPTION' ELSE '' END";
        $recompiler = '(select oid from pg_roles where rolname = current_user)';
        $name = SqlFragments::identifier($resolvedMap->previousModel->view);

        $grants = $this->connection->select(
            "select privilege.privilege_type || ' ON ' || ? || ' TO ' || {$grantee} || {$option} as statement, null::text as column
             from pg_class relation, aclexplode(coalesce(relation.relacl, acldefault('r', relation.relowner))) privilege
             where relation.oid = ?::regclass and privilege.grantee <> {$recompiler}
             union all
             select privilege.privilege_type || ' (' || quote_ident(view_column.attname) || ') ON ' || ? || ' TO ' || {$grantee} || {$option}, view_column.attname::text
             from pg_attribute view_column, aclexplode(view_column.attacl) privilege
             where view_column.attrelid = ?::regclass and view_column.attnum > 0 and not view_column.attisdropped and privilege.grantee <> {$recompiler}",
            [$name, $view, $name, $view],
        );

        return array_map(fn (object $grant) => (object) ['statement' => (string) $grant->statement, 'column' => $grant->column === null ? null : (string) $grant->column], array_values($grants));
    }

    private function relation(string $name): null|string
    {
        $relation = $this->connection->scalar("select to_regclass(quote_ident(current_schema()) || '.' || ?)::text", [SqlFragments::identifier($name)]);

        return is_string($relation) ? $relation : null;
    }

    private function relationKind(string $name): null|string
    {
        $kind = $this->connection->scalar("select relkind::text from pg_class where oid = to_regclass(quote_ident(current_schema()) || '.' || ?)", [SqlFragments::identifier($name)]);

        return is_string($kind) ? $kind : null;
    }
}
