<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\Attribute;
use Support\Database\Eloquent\Compatibility\Mapping\PreviousModel;
use Support\Database\Eloquent\Compatibility\Mapping\PrimaryCurrentModel;
use Support\Database\Eloquent\Compatibility\Testing\InteractsWithCompatibilityMaps;
use Support\Database\Eloquent\Compatibility\Write\WriteRouter;
use Tests\Fixtures\Support\Workbench\CompatibilityMapEventMirror;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(Installer::class)]
#[CoversClass(Compiler::class)]
final class InstallerTest extends TestCase
{
    use InteractsWithCompatibilityMaps;

    private const array KEYWORDS = ['as', 'create', 'for', 'join', 'left', 'limit', 'on', 'order', 'returning', 'set', 'where'];

    private const string CONCURRENT_WRITER = <<<'PHP'
        $config = json_decode((string) getenv('CONCURRENT_CONNECTION'), true);
        $connection = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $connection->exec("set application_name = 'compatibility_concurrent_writer'");
        $connection->exec("set lock_timeout = '10s'");
        $connection->beginTransaction();
        $connection->exec((string) getenv('CONCURRENT_STATEMENT'));
        $connection->commit();
        PHP;

    protected function setUp(): void
    {
        parent::setUp();

        Tables::drop();
        Tables::create();
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        resolve(WriteRouter::class)->register(new CompatibilityMapEventMirror(new WidgetCompatibilityMap));
    }

    protected function tearDown(): void
    {
        DB::rollBack(0);
        Tables::drop();

        parent::tearDown();
    }

    /** @return list<string> */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function it_exposes_every_attribute_with_its_declared_previous_model_type(): void
    {
        $columns = DB::select(
            "select view_column.attname as name, format_type(view_column.atttypid, view_column.atttypmod) as type
             from pg_attribute view_column where view_column.attrelid = 'widgets'::regclass and view_column.attnum > 0 order by view_column.attnum",
        );

        $this->assertSame([
            'id' => 'integer',
            'uuid' => 'uuid',
            'sku' => 'character varying(255)',
            'status' => 'character varying(255)',
            'title' => 'character varying(255)',
            'price' => 'character varying(255)',
            'delivered_at' => 'timestamp(0) without time zone',
            'is_archived' => 'boolean',
            'street' => 'character varying(255)',
            'latitude' => 'double precision',
            'gadget_id' => 'uuid',
            'created_at' => 'timestamp(0) without time zone',
            'updated_at' => 'timestamp(0) without time zone',
            'deleted_at' => 'timestamp(0) without time zone',
        ], array_column(array_map(fn (object $column) => (array) $column, $columns), 'type', 'name'));
    }

    #[Test]
    public function it_shows_a_record_without_a_dependent_row_through_the_declared_lossy_rule(): void
    {
        $id = Widget::query()->create(['title' => 'No site'])->id;

        $this->assertNull(DB::table('widgets')->where('id', $id)->sole()->latitude);
        $this->assertSame(0, DB::table('gadget_sites')->count());
    }

    #[Test]
    public function it_hides_the_records_a_hidden_state_names(): void
    {
        DB::table('retained_widgets')->insert(['status' => 'Retired']);

        $this->assertSame(1, DB::table('retained_widgets')->count());
        $this->assertSame(0, DB::table('widgets')->count());
    }

    #[Test]
    public function it_locks_a_record_through_the_compatibility_view(): void
    {
        $id = $this->widget();

        $this->assertSame($id, DB::table('widgets')->where('id', $id)->lockForUpdate()->sole()->id);
    }

    #[Test]
    public function it_locks_the_retained_and_primary_rows_and_no_dependent_row_through_the_compatibility_view(): void
    {
        $id = $this->widget();
        config(['database.connections.concurrent' => config('database.connections.'.config('database.default'))]);
        $this->beforeApplicationDestroyed(fn () => DB::purge('concurrent'));
        $concurrent = DB::connection('concurrent');
        $tables = ['retained_widgets', 'gadgets', 'gadget_places', 'gadget_sites'];

        $locked = DB::transaction(function () use ($id, $concurrent, $tables): array {
            Widget::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            return array_combine($tables, array_map(fn (string $table) => $this->lockOutcome($concurrent, $table), $tables));
        });

        $this->assertSame(
            ['retained_widgets' => '55P03', 'gadgets' => '55P03', 'gadget_places' => 'free', 'gadget_sites' => 'free'],
            $locked,
            'Declared limit: the view reads each dependent through a scalar subquery, so FOR UPDATE locks only the rows of its FROM list.',
        );
    }

    #[Test]
    public function it_refuses_every_write_that_reaches_the_view_without_the_write_router(): void
    {
        $id = $this->widget();

        $this->assertStatementFails(fn () => DB::table('widgets')->insert(['title' => 'x']));
        $this->assertStatementFails(fn () => DB::table('widgets')->where('id', $id)->update(['title' => 'x']));
        $this->assertStatementFails(fn () => DB::table('widgets')->where('id', $id)->delete());
        $this->assertStatementFails(fn () => DB::table('widgets')->upsert([['id' => $id, 'title' => 'x']], ['id'], ['title']));
        $this->assertStatementFails(fn () => DB::table('widgets')->where('id', $id)->limit(1)->update(['title' => 'x']));
        $this->assertStatementFails(fn () => DB::table('widgets')->truncate());

        $this->assertSame('Blue gadget', DB::table('widgets')->where('id', $id)->sole()->title);
    }

    #[Test]
    public function it_refuses_to_install_a_compatibility_map_twice(): void
    {
        $this->assertThrows(
            fn () => resolve(Installer::class)->install(new WidgetCompatibilityMap),
            InvalidCompatibilityMap::class,
            'use recompile() to recompile the compatibility map',
        );
    }

    #[Test]
    public function it_installs_and_recompiles_a_compatibility_map_without_a_trigger_or_a_function(): void
    {
        resolve(Installer::class)->recompile(new WidgetCompatibilityMap);
        $id = $this->widget();

        $this->assertSame(0, $this->objects('pg_trigger', 'tgname'));
        $this->assertSame(0, $this->objects('pg_proc', 'proname'));
        $this->assertSame(0, (int) DB::scalar("select count(*) from pg_trigger where not tgisinternal and tgrelid in ('widgets'::regclass, 'gadgets'::regclass, 'gadget_places'::regclass, 'gadget_sites'::regclass, 'retained_widgets'::regclass)"));
        $this->assertSame(1, DB::table('gadgets')->count());
        $this->assertSame('Blue gadget', DB::table('widgets')->where('id', $id)->sole()->title);
    }

    #[Test]
    public function it_drops_the_triggers_and_functions_an_earlier_version_installed_when_it_recompiles(): void
    {
        DB::unprepared(<<<'SQL'
            create function compatibility__widgets__insert() returns trigger language plpgsql as $$ begin return new; end $$;
            create function compatibility__widgets__primary_insert() returns trigger language plpgsql as $$ begin return new; end $$;
            create trigger compatibility__widgets__insert instead of insert on widgets for each row execute function compatibility__widgets__insert();
            create trigger compatibility__widgets__primary_insert after insert on gadgets for each row execute function compatibility__widgets__primary_insert();
            SQL);
        $this->assertSame([2, 2], [$this->objects('pg_trigger', 'tgname'), $this->objects('pg_proc', 'proname')]);

        resolve(Installer::class)->recompile(new WidgetCompatibilityMap);

        $this->assertSame([0, 0], [$this->objects('pg_trigger', 'tgname'), $this->objects('pg_proc', 'proname')]);
        $this->assertSame('Blue gadget', DB::table('widgets')->where('id', $this->widget())->sole()->title);
    }

    #[Test]
    public function it_keeps_the_installed_compatibility_map_when_a_recompile_fails(): void
    {
        $broken = new WidgetCompatibilityMap(['title' => Attribute::mapped('title')->asType('no_such_type')->storedAs('gadget', 'name')]);

        $this->assertThrows(fn () => resolve(Installer::class)->recompile($broken), QueryException::class, 'no_such_type');

        Gadget::query()->create(['name' => 'After a failed recompile']);

        $this->assertSame(1, DB::table('retained_widgets')->count());
        $this->assertSame('After a failed recompile', DB::table('widgets')->value('title'));
    }

    /**
     * @param  list<string>  $columns
     */
    #[Test]
    #[TestWith(['removed', ['id', 'uuid', 'sku', 'status', 'title', 'price', 'delivered_at', 'is_archived', 'street', 'gadget_id', 'created_at', 'updated_at', 'deleted_at'], 'character varying(255)'])]
    #[TestWith(['retyped', ['id', 'uuid', 'sku', 'status', 'title', 'price', 'delivered_at', 'is_archived', 'street', 'latitude', 'gadget_id', 'created_at', 'updated_at', 'deleted_at'], 'text'])]
    #[TestWith(['reordered', ['id', 'uuid', 'status', 'sku', 'title', 'price', 'delivered_at', 'is_archived', 'street', 'latitude', 'gadget_id', 'created_at', 'updated_at', 'deleted_at'], 'character varying(255)'])]
    public function it_recompiles_a_map_that_removes_retypes_or_reorders_an_attribute(string $change, array $columns, string $titleType): void
    {
        $id = $this->widget();
        $attributes = array_column(array_map(fn (Attribute $attribute) => [$attribute->column, $attribute], (new WidgetCompatibilityMap)->attributes()), 1, 0);
        $changed = match ($change) {
            'removed' => array_values(array_filter($attributes, fn (Attribute $attribute) => $attribute->column !== 'latitude')),
            'retyped' => array_values([...$attributes, 'title' => Attribute::mapped('title')->asType('text')->storedAs('gadget', 'name')]),
            'reordered' => array_values([...array_slice($attributes, 0, 2), 'status' => $attributes['status'], 'sku' => $attributes['sku'], ...array_slice($attributes, 4)]),
            default => [],
        };

        resolve(Installer::class)->recompile($this->withAttributes($changed));

        $this->assertSame($columns, array_column($this->viewColumns(), 'name'));
        $this->assertSame($titleType, array_column($this->viewColumns(), 'type', 'name')['title']);
        Widget::query()->whereKey($id)->update(['title' => 'After the recompile']);
        $this->assertSame('After the recompile', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_keeps_every_grant_on_the_view_when_it_recompiles_the_view_anew(): void
    {
        $reader = $this->role('compatibility_reader');
        $editor = $this->role('compatibility_editor');
        DB::statement('grant select on widgets to '.SqlFragments::identifier($reader).' with grant option');
        DB::statement('grant update (sku, title) on widgets to '.SqlFragments::identifier($editor));

        resolve(Installer::class)->recompile(new WidgetCompatibilityMap(['title' => Attribute::mapped('title')->asType('text')->storedAs('gadget', 'name')]));

        $this->assertSame(
            ['reader selects with grant option' => true, 'editor updates sku' => true, 'editor updates title' => true, 'editor updates status' => false],
            [
                'reader selects with grant option' => $this->viewPrivilege($reader, 'SELECT WITH GRANT OPTION'),
                'editor updates sku' => $this->viewPrivilege($editor, 'UPDATE', 'sku'),
                'editor updates title' => $this->viewPrivilege($editor, 'UPDATE', 'title'),
                'editor updates status' => $this->viewPrivilege($editor, 'UPDATE', 'status'),
            ],
        );
    }

    #[Test]
    public function it_refuses_to_recompile_while_another_object_depends_on_the_view_and_names_it(): void
    {
        DB::statement('create view widget_titles as select title from widgets');

        $this->assertThrows(
            fn () => resolve(Installer::class)->recompile(new WidgetCompatibilityMap),
            InvalidCompatibilityMap::class,
            "Compatibility map 'widgets' is declared wrongly: recompile() cannot replace the compatibility view widgets while other objects depend on it: widget_titles; drop them first and create them again after the recompile",
        );

        DB::statement('drop view widget_titles');
        Gadget::query()->create(['name' => 'After a refused recompile']);
        $this->assertSame(1, DB::table('retained_widgets')->count());
    }

    #[Test]
    #[TestWith(['create function widget_label(widgets) returns text language sql as $label$ select $1.title $label$', 'drop function widget_label(widgets)', 'function widget_label(widgets)'])]
    #[TestWith(['create table widget_snapshots (snapshot widgets)', 'drop table widget_snapshots', 'column snapshot of table widget_snapshots'])]
    #[TestWith(['create or replace function widget_audit() returns trigger language plpgsql as $audit$ begin return null; end $audit$; create trigger widget_audit after update on widgets for each statement execute function widget_audit()', 'drop function widget_audit() cascade', 'trigger widget_audit on view widgets'])]
    #[TestWith(['create rule widget_notice as on delete to widgets do also notify widget_notice', 'drop rule widget_notice on widgets', 'rule widget_notice on view widgets'])]
    public function it_refuses_to_recompile_while_an_object_uses_the_views_row_type_or_sits_on_the_view_and_names_it(string $create, string $drop, string $name): void
    {
        DB::unprepared($create);
        $this->beforeApplicationDestroyed(function (): void {
            DB::unprepared('drop table if exists widget_snapshots; drop function if exists widget_audit() cascade');
        });

        $this->assertThrows(
            fn () => resolve(Installer::class)->recompile(new WidgetCompatibilityMap),
            InvalidCompatibilityMap::class,
            "Compatibility map 'widgets' is declared wrongly: recompile() cannot replace the compatibility view widgets while other objects depend on it: {$name}; drop them first and create them again after the recompile",
        );

        DB::unprepared($drop);
        resolve(Installer::class)->recompile(new WidgetCompatibilityMap);
        Gadget::query()->create(['name' => 'After a refused recompile']);
        $this->assertSame(1, DB::table('retained_widgets')->count());
    }

    #[Test]
    public function it_refuses_to_install_while_another_view_reads_the_retained_table_and_names_it(): void
    {
        resolve(Installer::class)->uninstall(new WidgetCompatibilityMap);
        DB::statement('create view widget_skus as select sku from retained_widgets');
        DB::statement('create materialized view widget_statuses as select status from retained_widgets');

        try {
            $this->assertThrows(
                fn () => resolve(Installer::class)->install(new WidgetCompatibilityMap),
                InvalidCompatibilityMap::class,
                "Compatibility map 'widgets' is declared wrongly: install() cannot serve retained_widgets through the compatibility view while other views read it directly: widget_skus, widget_statuses; drop them first and create them again over the compatibility view after the install",
            );

            $this->assertNull(DB::scalar("select to_regclass('widgets')"));
        } finally {
            DB::statement('drop view widget_skus');
            DB::statement('drop materialized view widget_statuses');
        }
    }

    #[Test]
    #[TestWith(['retained_widgets', 'install'])]
    #[TestWith(['retained_widgets', 'recompile'])]
    #[TestWith(['gadgets', 'install'])]
    #[TestWith(['gadget_places', 'recompile'])]
    #[TestWith(['gadget_sites', 'install'])]
    public function it_refuses_to_install_or_recompile_over_a_table_with_row_level_security(string $table, string $action): void
    {
        if ($action === 'install') {
            resolve(Installer::class)->uninstall(new WidgetCompatibilityMap);
        }

        DB::statement("alter table {$table} enable row level security");

        $this->assertThrows(
            fn () => resolve(Installer::class)->{$action}(new WidgetCompatibilityMap),
            InvalidCompatibilityMap::class,
            "Compatibility map 'widgets' is declared wrongly: {$table} has row-level security enabled, and the compatibility view reads it with its owner's rights and would bypass its policies",
        );
    }

    #[Test]
    #[TestWith(['install'])]
    #[TestWith(['recompile'])]
    public function it_keeps_every_grant_the_default_privileges_add_off_the_view_it_creates(string $method): void
    {
        $reader = $this->role('compatibility_reader');
        DB::statement('alter default privileges in schema public grant select, update on tables to public');
        DB::statement('alter default privileges in schema public grant delete on tables to '.SqlFragments::identifier($reader).' with grant option');

        try {
            if ($method === 'install') {
                resolve(Installer::class)->uninstall(new WidgetCompatibilityMap);
                resolve(Installer::class)->install(new WidgetCompatibilityMap);
            } else {
                resolve(Installer::class)->recompile(new WidgetCompatibilityMap);
            }
        } finally {
            DB::statement('alter default privileges in schema public revoke select, update on tables from public');
            DB::statement('alter default privileges in schema public revoke delete on tables from '.SqlFragments::identifier($reader));
        }

        $this->assertSame(
            ['public selects' => false, 'public updates' => false, 'reader deletes' => false],
            ['public selects' => $this->viewPrivilege('public', 'SELECT'), 'public updates' => $this->viewPrivilege('public', 'UPDATE'), 'reader deletes' => $this->viewPrivilege($reader, 'DELETE')],
        );
    }

    #[Test]
    public function it_copies_the_owners_select_privilege_to_the_view_when_the_retained_table_was_never_granted(): void
    {
        $owner = $this->role('compatibility_owner');
        resolve(Installer::class)->uninstall(new WidgetCompatibilityMap);
        DB::statement('alter table retained_widgets owner to '.SqlFragments::identifier($owner));

        resolve(Installer::class)->install(new WidgetCompatibilityMap);

        $this->assertSame(
            ['select' => true, 'insert' => false, 'update' => false, 'delete' => false],
            ['select' => $this->viewPrivilege($owner, 'SELECT'), 'insert' => $this->viewPrivilege($owner, 'INSERT'), 'update' => $this->viewPrivilege($owner, 'UPDATE'), 'delete' => $this->viewPrivilege($owner, 'DELETE')],
        );
    }

    #[Test]
    public function it_leaves_nothing_behind_when_an_install_fails(): void
    {
        resolve(Installer::class)->uninstall(new WidgetCompatibilityMap);
        $broken = new WidgetCompatibilityMap(['title' => Attribute::mapped('title')->asType('no_such_type')->storedAs('gadget', 'name')]);

        $this->assertThrows(fn () => resolve(Installer::class)->install($broken), QueryException::class, 'no_such_type');

        resolve(Installer::class)->install(new WidgetCompatibilityMap);

        $this->assertSame('Blue gadget', DB::table('widgets')->where('id', $this->widget())->sole()->title);
    }

    #[Test]
    public function it_keeps_the_installed_compatibility_map_when_an_uninstall_fails(): void
    {
        DB::statement('create view widget_titles as select title from widgets');

        $this->assertThrows(fn () => resolve(Installer::class)->uninstall(new WidgetCompatibilityMap), QueryException::class, 'other objects depend on it');

        Gadget::query()->create(['name' => 'After a failed uninstall']);

        $this->assertSame(1, DB::table('retained_widgets')->count());
    }

    #[Test]
    public function it_leaves_the_same_compatibility_map_in_another_schema_installed(): void
    {
        $searchPath = (string) DB::scalar('show search_path');
        DB::statement('drop schema if exists elsewhere cascade');
        DB::statement('create schema elsewhere');
        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('drop schema if exists elsewhere cascade');
        });

        try {
            DB::statement('set search_path to elsewhere');
            Tables::create();
            resolve(Installer::class)->install(new WidgetCompatibilityMap);
        } finally {
            DB::statement('set search_path to '.$searchPath);
        }

        resolve(Installer::class)->recompile(new WidgetCompatibilityMap);
        resolve(Installer::class)->uninstall(new WidgetCompatibilityMap);

        $this->assertSame([null, 'elsewhere.widgets'], [DB::scalar("select to_regclass('public.widgets')::text"), DB::scalar("select to_regclass('elsewhere.widgets')::text")]);
    }

    #[Test]
    public function it_installs_recompiles_and_uninstalls_in_a_schema_whose_name_needs_quoting(): void
    {
        $searchPath = (string) DB::scalar('show search_path');
        DB::statement('drop schema if exists "Tenant" cascade');
        DB::statement('create schema "Tenant"');
        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('drop schema if exists "Tenant" cascade');
        });

        try {
            DB::statement('set search_path to "Tenant"');
            Tables::create();
            resolve(Installer::class)->install(new WidgetCompatibilityMap);
            resolve(Installer::class)->recompile(new WidgetCompatibilityMap);
            $title = DB::table('widgets')->where('id', $this->widget())->sole()->title;
            resolve(Installer::class)->uninstall(new WidgetCompatibilityMap);
            DB::table('gadgets')->insert(['name' => 'After retirement']);
            $retained = DB::table('retained_widgets')->count();
            $view = DB::scalar("select to_regclass('widgets')");
        } finally {
            DB::statement('set search_path to '.$searchPath);
        }

        $this->assertSame(['Blue gadget', 1, null], [$title, $retained, $view]);
    }

    #[Test]
    public function it_installs_recompiles_and_uninstalls_ahead_of_a_later_schema_that_has_the_compatibility_map(): void
    {
        $searchPath = (string) DB::scalar('show search_path');
        DB::statement('drop schema if exists "Tenant" cascade');
        DB::statement('create schema "Tenant"');
        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('drop schema if exists "Tenant" cascade');
        });

        try {
            DB::statement('set search_path to "Tenant", '.$searchPath);
            Tables::create();
            resolve(Installer::class)->install(new WidgetCompatibilityMap);
            resolve(Installer::class)->recompile(new WidgetCompatibilityMap);
            $title = DB::table('widgets')->where('id', $this->widget())->sole()->title;
            resolve(Installer::class)->uninstall(new WidgetCompatibilityMap);
            $view = DB::scalar("select to_regclass('\"Tenant\".widgets')");
        } finally {
            DB::statement('set search_path to '.$searchPath);
        }

        $this->assertSame(['Blue gadget', 1, null], [$title, DB::table('Tenant.retained_widgets')->count(), $view]);
        $this->assertSame('Blue gadget', DB::table('widgets')->where('id', $this->widget())->sole()->title);
    }

    #[Test]
    public function it_uninstalls_nothing_of_a_later_schema_when_the_current_schema_has_no_compatibility_view(): void
    {
        $searchPath = (string) DB::scalar('show search_path');
        DB::statement('drop schema if exists "Tenant" cascade');
        DB::statement('create schema "Tenant"');
        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('drop schema if exists "Tenant" cascade');
        });

        try {
            DB::statement('set search_path to "Tenant", '.$searchPath);
            resolve(Installer::class)->uninstall(new WidgetCompatibilityMap);
        } finally {
            DB::statement('set search_path to '.$searchPath);
        }

        $this->assertSame('widgets', DB::scalar("select to_regclass('widgets')::text"));
        $this->assertSame('Blue gadget', DB::table('widgets')->where('id', $this->widget())->sole()->title);
    }

    #[Test]
    #[TestWith(['install'])]
    #[TestWith(['recompile'])]
    public function it_installs_and_recompiles_a_map_that_exposes_a_generated_column_read_only(string $method): void
    {
        DB::statement('alter table gadgets add column shout text generated always as (upper(name)) stored');
        $installer = resolve(Installer::class);
        $map = $this->withAttributes([...(new WidgetCompatibilityMap)->attributes(), Attribute::mapped('shout')->asType('text')->storedAs('gadget', 'shout')->readOnlyBecause('The database generates it.')]);

        if ($method === 'install') {
            $installer->uninstall(new WidgetCompatibilityMap);
        }

        $installer->{$method}($map);
        $id = $this->widget();

        $this->assertNull(DB::scalar("select column_default from information_schema.columns where table_name = 'widgets' and column_name = 'shout'"));
        $this->assertSame('BLUE GADGET', DB::table('widgets')->where('id', $id)->value('shout'));
    }

    #[Test]
    public function it_uninstalls_the_compatibility_view_and_leaves_the_current_models_untouched(): void
    {
        $this->widget();

        resolve(Installer::class)->uninstall(new WidgetCompatibilityMap);

        $this->assertNull(DB::scalar("select to_regclass('widgets')"));
        $this->assertSame(0, $this->objects('pg_trigger', 'tgname'));
        $this->assertSame(0, $this->objects('pg_proc', 'proname'));
        $this->assertSame('Blue gadget', DB::table('gadgets')->value('name'));

        DB::table('gadgets')->insert(['name' => 'After retirement']);

        $this->assertSame(1, DB::table('retained_widgets')->count());
    }

    #[Test]
    public function it_copies_only_the_retained_tables_select_grants_to_the_read_only_view_when_it_installs(): void
    {
        $reader = $this->role('Compatibility Reader');
        $editor = $this->role('compatibility_editor');
        resolve(Installer::class)->uninstall(new WidgetCompatibilityMap);
        DB::statement('grant select on retained_widgets to '.SqlFragments::identifier($reader).' with grant option');
        DB::statement('grant insert, update (sku), select (place_id) on retained_widgets to '.SqlFragments::identifier($editor));
        DB::statement('grant select on retained_widgets to public');

        resolve(Installer::class)->install(new WidgetCompatibilityMap);

        $this->assertSame([
            'reader selects with grant option' => true,
            'reader inserts' => false,
            'editor inserts' => false,
            'editor updates every column' => false,
            'editor updates sku' => false,
            'editor updates status' => false,
            'public selects' => true,
        ], [
            'reader selects with grant option' => $this->viewPrivilege($reader, 'SELECT WITH GRANT OPTION'),
            'reader inserts' => $this->viewPrivilege($reader, 'INSERT'),
            'editor inserts' => $this->viewPrivilege($editor, 'INSERT'),
            'editor updates every column' => $this->viewPrivilege($editor, 'UPDATE'),
            'editor updates sku' => $this->viewPrivilege($editor, 'UPDATE', 'sku'),
            'editor updates status' => $this->viewPrivilege($editor, 'UPDATE', 'status'),
            'public selects' => $this->viewPrivilege('public', 'SELECT'),
        ]);
    }

    #[Test]
    public function it_leaves_the_views_grants_as_they_are_when_it_recompiles(): void
    {
        $reader = $this->role('compatibility_reader');
        resolve(Installer::class)->uninstall(new WidgetCompatibilityMap);
        DB::statement('grant select, insert on retained_widgets to '.SqlFragments::identifier($reader));
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        DB::statement('revoke insert on widgets from '.SqlFragments::identifier($reader));
        DB::statement('grant delete on retained_widgets to '.SqlFragments::identifier($reader));

        resolve(Installer::class)->recompile(new WidgetCompatibilityMap);

        $this->assertSame(
            ['select' => true, 'insert' => false, 'delete' => false],
            ['select' => $this->viewPrivilege($reader, 'SELECT'), 'insert' => $this->viewPrivilege($reader, 'INSERT'), 'delete' => $this->viewPrivilege($reader, 'DELETE')],
        );
    }

    private function widget(): int
    {
        return Widget::query()->create(['title' => 'Blue gadget', 'price' => '12.50', 'street' => '1 Main St', 'latitude' => 47.5])->id;
    }

    /**
     * @return list<array{name: string, type: string}>
     */
    private function viewColumns(): array
    {
        $columns = DB::select(
            "select view_column.attname as name, format_type(view_column.atttypid, view_column.atttypmod) as type
             from pg_attribute view_column where view_column.attrelid = 'widgets'::regclass and view_column.attnum > 0 and not view_column.attisdropped order by view_column.attnum",
        );

        return array_map(fn (object $column) => ['name' => (string) $column->name, 'type' => (string) $column->type], $columns);
    }

    /**
     * @param  list<Attribute>  $attributes
     */
    private function withAttributes(array $attributes): CompatibilityMap
    {
        return new readonly class(new WidgetCompatibilityMap, $attributes) implements CompatibilityMap
        {
            /**
             * @param  list<Attribute>  $attributes
             */
            public function __construct(private WidgetCompatibilityMap $base, private array $attributes) {}

            public function name(): string
            {
                return $this->base->name();
            }

            public function previousModel(): PreviousModel
            {
                return $this->base->previousModel();
            }

            public function primaryCurrentModel(): PrimaryCurrentModel
            {
                return $this->base->primaryCurrentModel();
            }

            public function dependentCurrentModels(): array
            {
                return $this->base->dependentCurrentModels();
            }

            public function attributes(): array
            {
                return $this->attributes;
            }

            public function unrepresentableStates(): array
            {
                return $this->base->unrepresentableStates();
            }
        };
    }

    private function role(string $name): string
    {
        $role = $name.'_'.Str::lower(Str::random(8));
        DB::statement('create role '.SqlFragments::identifier($role));
        $this->beforeApplicationDestroyed(function () use ($role): void {
            DB::statement('drop role if exists '.SqlFragments::identifier($role));
        });

        return $role;
    }

    private function viewPrivilege(string $role, string $privilege, null|string $column = null): bool
    {
        return $column === null
            ? DB::scalar('select has_table_privilege(cast(? as name), cast(? as text), cast(? as text))', [$role, 'widgets', $privilege]) === true
            : DB::scalar('select has_column_privilege(cast(? as name), cast(? as text), cast(? as text), cast(? as text))', [$role, 'widgets', $column, $privilege]) === true;
    }

    private function objects(string $catalog, string $column): int
    {
        return (int) DB::scalar("select count(*) from {$catalog} where {$column} like 'compatibility\\_\\_widgets\\_\\_%'");
    }

    private function lockOutcome(Connection $concurrent, string $table): string
    {
        try {
            $concurrent->transaction(fn () => $concurrent->select('select 1 from '.SqlFragments::identifier($table).' for update nowait'));

            return 'free';
        } catch (QueryException $e) {
            return (string) $e->getCode();
        }
    }

    private function writeConcurrently(string $statement): InvokedProcess
    {
        $writer = Process::env([
            'CONCURRENT_CONNECTION' => (string) json_encode(config('database.connections.concurrent')),
            'CONCURRENT_STATEMENT' => $statement,
        ])->start([PHP_BINARY, '-r', self::CONCURRENT_WRITER]);

        for ($attempt = 0; $attempt < 250 && $writer->running(); $attempt++) {
            if (DB::connection('concurrent')->scalar("select exists (select 1 from pg_stat_activity where datname = current_database() and application_name = 'compatibility_concurrent_writer' and wait_event_type = 'Lock')") === true) {
                return $writer;
            }

            usleep(20_000);
        }

        $result = $writer->wait();

        $this->fail('The concurrent writer never waited on a lock. '.$result->output().$result->errorOutput());
    }

    /**
     * @param  Closure(): mixed  $statement
     */
    private function assertStatementFails(Closure $statement): void
    {
        try {
            DB::transaction($statement);
        } catch (QueryException) {
            return;
        }

        $this->fail('Expected the statement to fail.');
    }
}
