<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Illuminate\Database\ConnectionInterface;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;

final readonly class Installer
{
    public function __construct(private ConnectionInterface $connection) {}

    public function install(CompatibilityMap $map): void
    {
        $resolvedMap = ResolvedMap::of($map);

        if ($this->relation($resolvedMap->previousModel->view) !== null) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, "a relation named {$resolvedMap->previousModel->view} already exists; use recompile() to recompile the compatibility map");
        }

        $readers = $this->connection->select(
            "select distinct rule.ev_class::regclass::text as name
             from pg_depend dependency join pg_rewrite rule on rule.oid = dependency.objid
             where dependency.classid = 'pg_rewrite'::regclass and dependency.refclassid = 'pg_class'::regclass
               and dependency.refobjid = to_regclass(?) and rule.ev_class <> dependency.refobjid
             order by 1",
            [SqlFragments::identifier((string) $resolvedMap->previousModel->table)],
        );

        if ($readers !== []) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, "install() cannot serve {$resolvedMap->previousModel->table} through the compatibility view while other views read it directly: "
                .implode(', ', array_map(fn (object $reader) => (string) $reader->name, $readers)).'; drop them first and create them again over the compatibility view after the install');
        }

        $this->connection->transaction(function () use ($resolvedMap): void {
            $this->apply($resolvedMap);
            $this->copyPrivileges($resolvedMap);
        });
    }

    public function recompile(CompatibilityMap $map): void
    {
        $resolvedMap = ResolvedMap::of($map);

        $this->connection->transaction(function () use ($resolvedMap): void {
            $grants = $this->viewGrants($resolvedMap);
            $this->apply($resolvedMap);
            $columns = array_map(fn (object $column) => (string) $column->name, $this->connection->select(
                'select attname as name from pg_attribute where attrelid = to_regclass(?) and attnum > 0 and not attisdropped',
                [SqlFragments::identifier($resolvedMap->previousModel->view)],
            ));

            foreach ($grants as $grant) {
                if ($grant->column === null || in_array($grant->column, $columns, true)) {
                    $this->connection->unprepared('GRANT '.$grant->statement);
                }
            }
        });
    }

    public function uninstall(CompatibilityMap $map): void
    {
        $resolvedMap = ResolvedMap::of($map);

        $this->connection->transaction(function () use ($resolvedMap): void {
            $this->dropObjects($resolvedMap);
            $view = $this->relation($resolvedMap->previousModel->view);

            if ($view !== null) {
                $this->connection->unprepared('DROP VIEW '.$view);
            }
        });
    }

    private function apply(ResolvedMap $resolvedMap): void
    {
        foreach (array_unique([(string) $resolvedMap->previousModel->table, ...array_map($resolvedMap->table(...), array_values($resolvedMap->currentModels()))]) as $table) {
            if ($this->connection->scalar('select relrowsecurity from pg_class where oid = to_regclass(?)', [SqlFragments::identifier($table)]) === true) {
                throw InvalidCompatibilityMap::because($resolvedMap->name, "{$table} has row-level security enabled, and the compatibility view and its SECURITY DEFINER triggers would bypass its policies; map only tables without row-level security");
            }
        }

        $compiledSql = (new Compiler($this->connection))->compile($resolvedMap);
        $view = $this->relation($resolvedMap->previousModel->view);

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

        $this->dropObjects($resolvedMap);

        if ($view !== null) {
            $this->connection->unprepared('DROP VIEW '.$view);
        }

        foreach ($compiledSql->statements() as $statement) {
            $this->connection->unprepared($statement);
        }
    }

    private function dropObjects(ResolvedMap $resolvedMap): void
    {
        $prefix = SqlFragments::like($resolvedMap->objectPrefix());

        $triggers = $this->connection->select(
            "select table_trigger.tgname as name, table_trigger.tgrelid::regclass::text as relation
             from pg_trigger table_trigger join pg_proc trigger_function on trigger_function.oid = table_trigger.tgfoid
             join pg_class trigger_relation on trigger_relation.oid = table_trigger.tgrelid
             where not table_trigger.tgisinternal and table_trigger.tgname like {$prefix}
               and trigger_function.pronamespace = to_regnamespace(quote_ident(current_schema()))
               and trigger_relation.relnamespace = to_regnamespace(quote_ident(current_schema()))",
        );

        foreach ($triggers as $trigger) {
            $this->connection->unprepared('DROP TRIGGER IF EXISTS '.SqlFragments::identifier((string) $trigger->name).' ON '.$trigger->relation);
        }

        $functions = $this->connection->select(
            "select oid::regprocedure::text as signature from pg_proc where proname like {$prefix} and pronamespace = to_regnamespace(quote_ident(current_schema()))",
        );

        foreach ($functions as $function) {
            $this->connection->unprepared('DROP FUNCTION IF EXISTS '.$function->signature);
        }
    }

    private function copyPrivileges(ResolvedMap $resolvedMap): void
    {
        $grantee = "CASE WHEN privilege.grantee = 0 THEN 'PUBLIC' ELSE quote_ident(pg_get_userbyid(privilege.grantee)) END";
        $option = "CASE WHEN privilege.is_grantable THEN ' WITH GRANT OPTION' ELSE '' END";
        $view = SqlFragments::identifier($resolvedMap->previousModel->view);
        $table = SqlFragments::identifier((string) $resolvedMap->previousModel->table);

        $grants = $this->connection->select(
            "select privilege.privilege_type || ' ON ' || ? || ' TO ' || {$grantee} || {$option} as statement
             from pg_class relation, aclexplode(coalesce(relation.relacl, acldefault('r', relation.relowner))) privilege
             where relation.oid = to_regclass(?) and privilege.grantee <> (select relowner from pg_class where oid = to_regclass(?))
             union all
             select privilege.privilege_type || ' (' || quote_ident(table_column.attname) || ') ON ' || ? || ' TO ' || {$grantee} || {$option}
             from pg_attribute table_column
             join pg_attribute view_column on view_column.attrelid = to_regclass(?) and view_column.attname = table_column.attname and not view_column.attisdropped,
             aclexplode(table_column.attacl) privilege
             where table_column.attrelid = to_regclass(?) and table_column.attnum > 0 and not table_column.attisdropped
               and privilege.grantee <> (select relowner from pg_class where oid = to_regclass(?))",
            [$view, $table, $view, $view, $view, $table, $view],
        );

        foreach ($grants as $grant) {
            $this->connection->unprepared('GRANT '.$grant->statement);
        }
    }

    /**
     * @return list<object{statement: string, column: null|string}>
     */
    private function viewGrants(ResolvedMap $resolvedMap): array
    {
        $view = $this->relation($resolvedMap->previousModel->view);

        if ($view === null) {
            return [];
        }

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
}
