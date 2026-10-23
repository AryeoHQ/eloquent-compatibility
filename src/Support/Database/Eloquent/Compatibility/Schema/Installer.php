<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Illuminate\Database\ConnectionInterface;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\Declaration;

final readonly class Installer
{
    public function __construct(private ConnectionInterface $connection) {}

    public function install(CompatibilityMap $map): void
    {
        $declaration = Declaration::of($map);

        if ($this->viewExists($declaration->previousModel->view)) {
            throw InvalidCompatibilityMap::because($declaration->name, "a relation named {$declaration->previousModel->view} already exists; use recompile() to recompile the compatibility map");
        }

        $this->apply($declaration);
        $this->copyPrivileges($declaration);
    }

    public function recompile(CompatibilityMap $map): void
    {
        $this->apply(Declaration::of($map));
    }

    public function uninstall(CompatibilityMap $map): void
    {
        $declaration = Declaration::of($map);
        $this->dropObjects($declaration);
        $this->connection->unprepared('DROP VIEW IF EXISTS '.Sql::id($declaration->previousModel->view));
    }

    private function apply(Declaration $declaration): void
    {
        $compiled = (new Compiler($this->connection))->compile($declaration);
        $this->dropObjects($declaration);

        foreach ($compiled->statements() as $statement) {
            $this->connection->unprepared($statement);
        }
    }

    private function dropObjects(Declaration $declaration): void
    {
        $prefix = Sql::like($declaration->objectPrefix());

        $triggers = $this->connection->select(
            "select tgname as name, tgrelid::regclass::text as relation from pg_trigger where not tgisinternal and tgname like {$prefix}",
        );

        foreach ($triggers as $trigger) {
            $this->connection->unprepared('DROP TRIGGER IF EXISTS '.Sql::id((string) $trigger->name).' ON '.$trigger->relation);
        }

        $functions = $this->connection->select(
            "select oid::regprocedure::text as signature from pg_proc where proname like {$prefix} and pronamespace = current_schema()::regnamespace",
        );

        foreach ($functions as $function) {
            $this->connection->unprepared('DROP FUNCTION IF EXISTS '.$function->signature);
        }
    }

    private function copyPrivileges(Declaration $declaration): void
    {
        $grantee = "CASE WHEN privilege.grantee = 0 THEN 'PUBLIC' ELSE quote_ident(pg_get_userbyid(privilege.grantee)) END";
        $option = "CASE WHEN privilege.is_grantable THEN ' WITH GRANT OPTION' ELSE '' END";
        $view = Sql::id($declaration->previousModel->view);
        $table = Sql::id((string) $declaration->previousModel->table);

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
        return $this->connection->scalar('select to_regclass(?) is not null', [Sql::id($view)]) === true;
    }
}
