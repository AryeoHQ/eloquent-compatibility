<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Illuminate\Database\ConnectionInterface;

final class ColumnCatalog
{
    /** @var array<string, array<string, Column>> */
    private array $tables = [];

    /** @var array<string, bool> */
    private array $equatable = [];

    /**
     * @param  array<string, string>  $sources
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly null|string $searchPath = null,
        private readonly array $sources = [],
    ) {}

    /**
     * @return array<string, Column>
     */
    public function of(string $table): array
    {
        if (array_key_exists($table, $this->tables)) {
            return $this->tables[$table];
        }

        $rows = $this->searchPath === null ? $this->columns($table) : $this->underSearchPath($this->searchPath, fn () => $this->columns($table));
        $columns = [];

        foreach ($rows as $row) {
            $columns[(string) $row->name] = new Column(
                (string) $row->type,
                $row->default === null ? null : (string) $row->default,
                $row->not_null === true,
                $row->generated === true,
                $row->always_generated === true,
                $row->base_type === null ? null : (string) $row->base_type,
                $row->default_references_source === true,
            );
        }

        return $this->tables[$table] = $columns;
    }

    /**
     * @return array<int, object>
     */
    private function columns(string $table): array
    {
        return $this->connection->select(
            'select table_column.attname as name, format_type(table_column.atttypid, table_column.atttypmod) as type,
                    case when table_column.attidentity = \'d\' then format(\'pg_catalog.nextval(pg_catalog.pg_get_serial_sequence(%L, %L)::regclass)\', format(\'%I.%I\', table_schema.nspname, ?::text), table_column.attname)
                         when table_column.attgenerated = \'\' then coalesce(pg_get_expr(column_default.adbin, column_default.adrelid), column_domain.inherited_default) end as "default",
                    table_column.attnotnull or column_domain.inherited_not_null as not_null,
                    table_column.attidentity <> \'\' or table_column.attgenerated <> \'\' as generated,
                    table_column.attidentity = \'a\' or table_column.attgenerated <> \'\' as always_generated,
                    column_domain.base_type,
                    '.$this->referencesSource().' as default_references_source
             from pg_attribute table_column
             join pg_class table_relation on table_relation.oid = table_column.attrelid
             join pg_namespace table_schema on table_schema.oid = table_relation.relnamespace
             left join pg_attrdef column_default on column_default.adrelid = table_column.attrelid and column_default.adnum = table_column.attnum
             cross join lateral (
                 with recursive domain_chain(type, depth) as (
                     select table_column.atttypid, 0
                     union all
                     select declared.typbasetype, domain_chain.depth + 1
                     from domain_chain join pg_type declared on declared.oid = domain_chain.type
                     where declared.typtype = \'d\'
                 )
                 select (array_agg(pg_get_expr(declared.typdefaultbin, 0) order by domain_chain.depth) filter (where declared.typdefaultbin is not null))[1] as inherited_default,
                        coalesce(bool_or(declared.typnotnull), false) as inherited_not_null,
                        (array_agg(format_type(declared.typbasetype, declared.typtypmod) order by domain_chain.depth desc))[1] as base_type
                 from domain_chain join pg_type declared on declared.oid = domain_chain.type and declared.typtype = \'d\'
             ) column_domain
             where table_column.attrelid = to_regclass(quote_ident(current_schema()) || \'.\' || ?) and table_column.attnum > 0 and not table_column.attisdropped
             order by table_column.attnum',
            [$table, ...array_map(SqlFragments::identifier(...), array_values($this->sources)), SqlFragments::identifier($this->source($table))],
        );
    }

    private function referencesSource(): string
    {
        if ($this->sources === []) {
            return 'false';
        }

        $sources = implode(', ', array_fill(0, count($this->sources), "to_regclass(quote_ident(current_schema()) || '.' || ?)"));

        return "exists (select 1 from pg_depend default_dependency
                 where default_dependency.classid = 'pg_attrdef'::regclass and default_dependency.objid = column_default.oid and default_dependency.deptype = 'n'
                   and default_dependency.refclassid = 'pg_class'::regclass and default_dependency.refobjid in ({$sources}))";
    }

    /**
     * @param  callable(): array<int, object>  $read
     * @return array<int, object>
     */
    private function underSearchPath(string $searchPath, callable $read): array
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->statement("select pg_catalog.set_config('search_path', ?, true)", [$searchPath]);

            return $read();
        } finally {
            $this->connection->rollBack();
        }
    }

    public function source(string $table): string
    {
        return $this->sources[$table] ?? $table;
    }

    public function type(string $table, string $column): null|string
    {
        return $this->of($table)[$column]->type ?? null;
    }

    public function notNull(string $table, string $column): bool
    {
        return $this->of($table)[$column]->notNull ?? false;
    }

    public function equatable(string $type): bool
    {
        return $this->equatable[$type] ??= $this->connection->scalar(
            "with recursive underlying(type) as (
                 select to_regtype(?)::oid
                 union all
                 select case when declared.typtype = 'd' then declared.typbasetype else declared.typelem end
                 from underlying join pg_type declared on declared.oid = underlying.type
                 where declared.typtype = 'd' or (declared.typcategory = 'A' and declared.typelem <> 0)
             )
             select exists (
                 select 1
                 from underlying
                 join pg_type compared on compared.oid = underlying.type and compared.typtype <> 'd' and not (compared.typcategory = 'A' and compared.typelem <> 0)
                 join pg_opclass operator_class on operator_class.opcdefault
                 join pg_am access_method on access_method.oid = operator_class.opcmethod and access_method.amname = 'btree'
                 where operator_class.opcintype = compared.oid
                    or exists (select 1 from pg_cast coercion where coercion.castsource = compared.oid and coercion.casttarget = operator_class.opcintype and coercion.castmethod = 'b' and coercion.castcontext = 'i')
                    or (operator_class.opcintype = 'anyenum'::regtype and compared.typtype = 'e')
                    or (operator_class.opcintype = 'anyrange'::regtype and compared.typtype = 'r')
                    or (operator_class.opcintype = 'anymultirange'::regtype and compared.typtype = 'm')
             )",
            [$type],
        ) === true;
    }
}
