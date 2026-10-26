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

        if ($this->viewExists($resolvedMap->previousModel->view)) {
            throw InvalidCompatibilityMap::because($resolvedMap->name, "a relation named {$resolvedMap->previousModel->view} already exists; use recompile() to recompile the compatibility map");
        }

        $this->connection->transaction(function () use ($resolvedMap): void {
            $this->apply($resolvedMap);
            $this->copyPrivileges($resolvedMap);
        });
    }

    public function recompile(CompatibilityMap $map): void
    {
        $resolvedMap = ResolvedMap::of($map);
        $this->connection->transaction(fn () => $this->apply($resolvedMap));
    }

    public function uninstall(CompatibilityMap $map): void
    {
        $resolvedMap = ResolvedMap::of($map);

        $this->connection->transaction(function () use ($resolvedMap): void {
            $this->dropObjects($resolvedMap);
            $this->connection->unprepared('DROP VIEW IF EXISTS '.SqlFragments::id($resolvedMap->previousModel->view));
        });
    }

    private function apply(ResolvedMap $resolvedMap): void
    {
        $compiledSql = (new Compiler($this->connection))->compile($resolvedMap);
        $this->dropObjects($resolvedMap);

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
            $this->connection->unprepared('DROP TRIGGER IF EXISTS '.SqlFragments::id((string) $trigger->name).' ON '.$trigger->relation);
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
        $view = SqlFragments::id($resolvedMap->previousModel->view);
        $table = SqlFragments::id((string) $resolvedMap->previousModel->table);

        $grants = $this->connection->select(
            "select privilege.privilege_type || ' ON ' || ? || ' TO ' || {$grantee} || {$option} as statement
             from pg_class relation, aclexplode(relation.relacl) privilege
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

    private function viewExists(string $view): bool
    {
        return $this->connection->scalar('select to_regclass(?) is not null', [SqlFragments::id($view)]) === true;
    }
}
