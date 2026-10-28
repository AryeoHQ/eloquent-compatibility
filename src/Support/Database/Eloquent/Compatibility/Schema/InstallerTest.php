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
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\Attribute;
use Support\Database\Eloquent\Compatibility\Mapping\PreviousModel;
use Support\Database\Eloquent\Compatibility\Mapping\PrimaryCurrentModel;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Testing\InteractsWithCompatibilityMaps;
use Tests\Fixtures\Support\Workbench\AccountCompatibilityMap;
use Tests\Fixtures\Support\Workbench\ProfileCompatibilityMap;
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
    public function it_inserts_one_record_into_every_table_and_returns_the_previous_model_key(): void
    {
        $id = DB::table('widgets')->insertGetId(['title' => 'Blue gadget', 'price' => '12.50', 'street' => '1 Main St', 'latitude' => 47.5]);

        $retained = DB::table('retained_widgets')->sole();
        $gadget = DB::table('gadgets')->sole();

        $this->assertSame($retained->id, $id);
        $this->assertSame($gadget->id, $retained->gadget_id);
        $this->assertSame('Draft', $retained->status);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $retained->uuid);
        $this->assertSame(['Blue gadget', 1250, false], [$gadget->name, $gadget->price_cents, $gadget->archived]);
        $this->assertSame('1 Main St', DB::table('gadget_places')->where('gadget_id', $gadget->id)->sole()->street);
        $this->assertSame('47.500000000000', DB::table('gadget_sites')->where('gadget_id', $gadget->id)->sole()->latitude);
    }

    #[Test]
    public function it_stores_a_renamed_and_retyped_attribute_once_in_its_owner(): void
    {
        $id = $this->widget();

        DB::table('widgets')->where('id', $id)->update([
            'title' => 'Renamed',
            'price' => '7.25',
            'delivered_at' => '2026-10-22 18:40:00',
            'latitude' => 12.25,
        ]);

        $gadget = DB::table('gadgets')->sole();
        $widget = DB::table('widgets')->where('id', $id)->sole();

        $this->assertSame(['Renamed', 725], [$gadget->name, $gadget->price_cents]);
        $this->assertSame(['Renamed', '7.25', '2026-10-22 18:40:00', 12.25], [$widget->title, $widget->price, $widget->delivered_at, $widget->latitude]);
    }

    #[Test]
    public function it_writes_only_the_tables_whose_attributes_changed(): void
    {
        $id = $this->widget();
        $version = fn (string $table) => DB::scalar("select xmin::text from {$table}");
        $gadget = $version('gadgets');
        $site = $version('gadget_sites');

        DB::table('widgets')->where('id', $id)->update(['sku' => 'SKU-1']);

        $this->assertSame('SKU-1', DB::table('retained_widgets')->value('sku'));
        $this->assertSame($gadget, $version('gadgets'));
        $this->assertSame($site, $version('gadget_sites'));
    }

    #[Test]
    public function it_creates_a_missing_dependent_row_when_a_previous_model_write_sets_its_attribute(): void
    {
        $id = DB::table('widgets')->insertGetId(['title' => 'No place yet']);

        $this->assertNull(DB::table('widgets')->where('id', $id)->sole()->street);
        $this->assertSame(0, DB::table('gadget_places')->count());

        DB::table('widgets')->where('id', $id)->update(['street' => '2 Side St']);

        $this->assertSame('2 Side St', DB::table('gadget_places')->sole()->street);
        $this->assertSame('2 Side St', DB::table('widgets')->where('id', $id)->sole()->street);
    }

    #[Test]
    public function it_refuses_a_change_to_a_read_only_attribute_with_what_why_and_what_to_do(): void
    {
        $id = $this->widget();

        $refusal = $this->assertWriteRefused(fn () => DB::table('widgets')->where('id', $id)->update(['uuid' => '00000000-0000-0000-0000-000000000000']));

        $this->assertSame("Compatibility map 'widgets' refused a change to widgets.uuid on record {$id}", $refusal->refused);
        $this->assertSame('A widget keeps the public id partners hold.', $refusal->because);
        $this->assertSame('Leave uuid unchanged in writes through widgets.', $refusal->instead);
    }

    #[Test]
    public function it_applies_a_refused_state_to_every_write_that_reaches_it(): void
    {
        $id = $this->widget();
        $gadget = DB::table('gadgets')->value('id');

        $refusal = $this->assertWriteRefused(
            fn () => DB::table('gadget_places')->insert(['gadget_id' => $gadget, 'street' => 'Second root']),
            'refused a second root place for one gadget',
        );

        $this->assertSame('Give the new place a parent_id, or soft-delete the current root place first.', $refusal->instead);

        DB::table('gadget_places')->insert([
            'gadget_id' => $gadget,
            'parent_id' => DB::table('gadget_places')->value('id'),
            'street' => 'Unit B',
        ]);

        $this->assertSame('1 Main St', DB::table('widgets')->where('id', $id)->sole()->street);
    }

    #[Test]
    public function it_makes_a_concurrent_write_wait_for_the_refused_state_check_of_the_same_record(): void
    {
        DB::table('widgets')->insert(['title' => 'No place yet']);
        $gadget = DB::table('gadgets')->value('id');
        config(['database.connections.concurrent' => config('database.connections.'.config('database.default'))]);
        $this->beforeApplicationDestroyed(fn () => DB::purge('concurrent'));
        $concurrent = DB::connection('concurrent');
        $concurrent->statement("set lock_timeout = '200ms'");

        DB::beginTransaction();
        DB::table('gadget_places')->insert(['gadget_id' => $gadget, 'street' => 'First root']);

        try {
            $concurrent->table('gadget_places')->insert(['gadget_id' => $gadget, 'street' => 'Concurrent root']);
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->getCode());
        }

        DB::commit();

        $this->assertSame(['First root'], DB::table('gadget_places')->orderBy('street')->pluck('street')->all());
        $this->assertWriteRefused(
            fn () => $concurrent->table('gadget_places')->insert(['gadget_id' => $gadget, 'street' => 'Concurrent root']),
            'refused a second root place for one gadget',
        );
    }

    #[Test]
    public function it_does_not_deadlock_a_write_through_the_view_against_a_writer_holding_the_refused_state_lock(): void
    {
        $id = $this->widget();
        $gadget = DB::table('gadgets')->value('id');
        $root = DB::table('gadget_places')->value('id');
        $child = DB::table('gadget_places')->insertGetId(['gadget_id' => $gadget, 'parent_id' => $root, 'street' => 'Unit B']);
        config(['database.connections.concurrent' => config('database.connections.'.config('database.default'))]);
        $this->beforeApplicationDestroyed(fn () => DB::purge('concurrent'));

        DB::beginTransaction();
        DB::table('gadget_places')->where('id', $child)->update(['street' => 'Unit C']);
        $viewWriter = $this->writeConcurrently("update widgets set street = 'New St' where id = {$id}");

        try {
            DB::table('gadget_places')->insert(['gadget_id' => $gadget, 'parent_id' => $root, 'street' => 'Unit D']);
            DB::commit();
        } finally {
            DB::rollBack(0);
            $result = $viewWriter->wait();
        }

        $this->assertTrue($result->successful(), $result->output().$result->errorOutput());
        $this->assertSame(['New St', 'Unit C', 'Unit D'], DB::table('gadget_places')->orderBy('street')->pluck('street')->all());
        $this->assertSame('New St', DB::table('widgets')->where('id', $id)->sole()->street);
    }

    #[Test]
    public function it_does_not_deadlock_a_lock_through_the_view_against_an_update_through_the_view_that_holds_the_primary_row(): void
    {
        $id = $this->widget();
        config(['database.connections.concurrent' => config('database.connections.'.config('database.default'))]);
        $this->beforeApplicationDestroyed(fn () => DB::purge('concurrent'));

        DB::beginTransaction();
        DB::table('gadgets')->lockForUpdate()->sole();
        $reader = $this->writeConcurrently(Widget::query()->whereKey($id)->lockForUpdate()->toRawSql());

        try {
            DB::table('widgets')->where('id', $id)->update(['title' => 'Renamed']);
            DB::commit();
        } finally {
            DB::rollBack(0);
            $result = $reader->wait();
        }

        $this->assertTrue($result->successful(), $result->output().$result->errorOutput());
        $this->assertSame('Renamed', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_refuses_a_previous_model_update_that_reaches_a_declared_state(): void
    {
        $id = $this->widget();
        DB::table('gadgets')->update(['archived' => true]);

        $this->assertWriteRefused(
            fn () => DB::table('widgets')->where('id', $id)->update(['status' => 'Live']),
            'refused a status change on an archived gadget',
        );

        DB::table('widgets')->where('id', $id)->update(['sku' => 'still allowed']);

        $this->assertSame('still allowed', DB::table('widgets')->where('id', $id)->sole()->sku);
    }

    #[Test]
    public function it_shows_a_record_without_a_dependent_row_through_the_declared_lossy_rule(): void
    {
        $id = DB::table('widgets')->insertGetId(['title' => 'No site']);

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
    public function it_creates_the_previous_model_record_when_the_primary_current_model_is_created(): void
    {
        $gadget = DB::table('gadgets')->insertGetId(['name' => 'Made by the current model', 'archived' => true]);

        $widget = DB::table('widgets')->sole();

        $this->assertSame(['Made by the current model', 'Archived', $gadget], [$widget->title, $widget->status, $widget->gadget_id]);
    }

    #[Test]
    public function it_deletes_every_row_of_a_record_deleted_through_the_previous_model(): void
    {
        $id = $this->widget();

        DB::table('widgets')->where('id', $id)->delete();

        foreach (['retained_widgets', 'gadgets', 'gadget_places', 'gadget_sites'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    #[Test]
    public function it_deletes_the_previous_model_record_when_the_primary_current_model_is_deleted(): void
    {
        $this->widget();
        DB::table('gadget_places')->delete();
        DB::table('gadget_sites')->delete();

        DB::table('gadgets')->delete();

        $this->assertSame(0, DB::table('retained_widgets')->count());
    }

    #[Test]
    public function it_soft_deletes_and_restores_through_the_attribute_the_primary_current_model_owns(): void
    {
        $id = $this->widget();

        DB::table('widgets')->where('id', $id)->update(['deleted_at' => '2026-10-22 18:40:00']);

        $this->assertNotNull(DB::table('gadgets')->value('deleted_at'));

        DB::table('widgets')->where('id', $id)->update(['deleted_at' => null]);

        $this->assertNull(DB::table('gadgets')->value('deleted_at'));
    }

    #[Test]
    public function it_keeps_two_previous_models_over_one_current_record_in_step(): void
    {
        resolve(Installer::class)->install(new AccountCompatibilityMap);
        resolve(Installer::class)->install(new ProfileCompatibilityMap);

        $account = DB::table('accounts')->insertGetId(['email' => 'one@example.test', 'password_hint' => 'a pet']);
        $profile = DB::table('profiles')->sole();

        $this->assertDatabaseCount('people', 1);
        $this->assertSame('one@example.test', $profile->email);

        DB::table('profiles')->where('id', $profile->id)->update(['email' => 'two@example.test', 'display_name' => 'Two']);

        $this->assertSame('two@example.test', DB::table('accounts')->where('id', $account)->sole()->email);
        $this->assertSame('a pet', DB::table('accounts')->where('id', $account)->sole()->password_hint);

        DB::table('accounts')->where('id', $account)->delete();

        $this->assertSame([0, 0, 0], [DB::table('people')->count(), DB::table('retained_profiles')->count(), DB::table('retained_accounts')->count()]);
    }

    #[Test]
    public function it_keeps_one_retained_row_per_primary_row_when_a_trigger_inside_a_write_through_the_view_writes_through_another_compatibility_view(): void
    {
        resolve(Installer::class)->install(new AccountCompatibilityMap);
        DB::unprepared("create function insert_account() returns trigger language plpgsql as \$nested\$ begin insert into accounts (email) values (NEW.name || '@example.test'); return NEW; end \$nested\$");
        DB::statement('create trigger a_insert_account after insert on gadgets for each row execute function insert_account()');
        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('drop function if exists insert_account() cascade');
        });

        DB::table('widgets')->insert(['title' => 'Through the view']);
        DB::table('gadgets')->insert(['name' => 'Directly']);

        $this->assertSame(
            ['Directly' => 1, 'Through the view' => 1],
            DB::table('gadgets')->leftJoin('retained_widgets', 'retained_widgets.gadget_id', '=', 'gadgets.id')->groupBy('gadgets.name')->orderBy('gadgets.name')
                ->selectRaw('gadgets.name, count(retained_widgets.id) as retained')->pluck('retained', 'name')->all(),
        );
        $this->assertSame(
            ['Directly@example.test' => 1, 'Through the view@example.test' => 1],
            DB::table('people')->leftJoin('retained_accounts', 'retained_accounts.person_id', '=', 'people.id')->groupBy('people.email')->orderBy('people.email')
                ->selectRaw('people.email, count(retained_accounts.id) as retained')->pluck('retained', 'email')->all(),
        );
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
    public function it_writes_the_current_tables_through_a_simple_view_over_the_compatibility_view(): void
    {
        $id = $this->widget();
        DB::statement("create view widget_listings as select id, title, street, latitude, sku from widgets where status = 'Draft'");

        $this->assertSame(
            ['is_updatable' => 'NO', 'is_insertable_into' => 'NO'],
            (array) DB::selectOne("select is_updatable, is_insertable_into from information_schema.views where table_name = 'widget_listings'"),
            'Declared limit: the catalog reports no view over a view with INSTEAD OF triggers as updatable, though PostgreSQL rewrites its writes onto that view.',
        );

        $this->assertSame(1, DB::update("update widget_listings set title = 'Outer title', street = '2 Outer St', latitude = 12.5, sku = 'Outer sku' where id = ?", [$id]));

        $this->assertSame(
            ['Outer title', '2 Outer St', '12.500000000000', 'Outer sku'],
            [DB::table('gadgets')->value('name'), DB::table('gadget_places')->value('street'), DB::table('gadget_sites')->value('latitude'), DB::table('retained_widgets')->value('sku')],
        );
        $this->assertWriteRefused(
            fn () => DB::update('update widget_listings set id = 99 where id = ?', [$id]),
            'refused a change to widgets.id',
        );
    }

    #[Test]
    public function it_writes_each_joined_row_with_its_own_values_through_an_update_from_values(): void
    {
        [$first, $second, $third] = [$this->widget(), $this->widget(), $this->widget()];

        $updated = DB::update(
            'update widgets set title = ordering.title, latitude = ordering.latitude, sku = ordering.sku
             from (values (?::integer, ?, ?::double precision, ?), (?::integer, ?, ?::double precision, ?)) as ordering(id, title, latitude, sku)
             where widgets.id = ordering.id',
            [$first, 'First', 10.5, 'First sku', $second, 'Second', 20.5, 'Second sku'],
        );

        $this->assertSame(2, $updated);
        $this->assertSame(
            [[$first, 'First', 10.5, 'First sku'], [$second, 'Second', 20.5, 'Second sku'], [$third, 'Blue gadget', 47.5, null]],
            DB::table('widgets')->orderBy('id')->get()->map(fn (object $widget) => [$widget->id, $widget->title, $widget->latitude, $widget->sku])->all(),
        );
        $this->assertSame(['Blue gadget', 'First', 'Second'], DB::table('gadgets')->orderBy('name')->pluck('name')->all());
    }

    #[Test]
    public function it_raises_a_serialization_failure_when_an_update_from_values_joins_one_record_twice(): void
    {
        $id = $this->widget();

        try {
            DB::update(
                'update widgets set title = ordering.title from (values (?::integer, ?), (?::integer, ?)) as ordering(id, title) where widgets.id = ordering.id',
                [$id, 'First', $id, 'Second'],
            );
            $this->fail('Expected the second firing of the update trigger on one record to fail.');
        } catch (QueryException $e) {
            $this->assertSame('40001', $e->getCode(), 'Declared limit: the trigger fires once per joined row, and the second firing finds the record changed since its OLD row.');
        }

        $this->assertSame('Blue gadget', DB::table('widgets')->where('id', $id)->value('title'));
    }

    #[Test]
    public function it_fails_loudly_on_writes_a_view_with_triggers_cannot_take(): void
    {
        $id = $this->widget();

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
    public function it_recompiles_a_compatibility_map_without_duplicating_its_triggers(): void
    {
        resolve(Installer::class)->recompile(new WidgetCompatibilityMap);
        $id = $this->widget();

        $this->assertSame(6, $this->objects('pg_trigger', 'tgname'));
        $this->assertSame(6, $this->objects('pg_proc', 'proname'));
        $this->assertSame(1, DB::table('gadgets')->count());
        $this->assertSame('Blue gadget', DB::table('widgets')->where('id', $id)->sole()->title);
    }

    #[Test]
    public function it_keeps_the_installed_compatibility_map_when_a_recompile_fails(): void
    {
        $broken = new WidgetCompatibilityMap(['sku' => Attribute::retained('sku')->defaultsTo('no_such_function()')]);

        $this->assertThrows(fn () => resolve(Installer::class)->recompile($broken), QueryException::class, 'no_such_function');

        DB::table('gadgets')->insert(['name' => 'After a failed recompile']);

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
        DB::table('widgets')->where('id', $id)->update(['title' => 'After the recompile']);
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
        DB::table('gadgets')->insert(['name' => 'After a refused recompile']);
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
        DB::table('gadgets')->insert(['name' => 'After a refused recompile']);
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
            "Compatibility map 'widgets' is declared wrongly: {$table} has row-level security enabled, and the compatibility view and its SECURITY DEFINER triggers would bypass its policies",
        );
    }

    #[Test]
    public function it_lets_no_other_role_execute_a_trigger_function_after_an_install_or_a_recompile(): void
    {
        $intruder = $this->role('compatibility_intruder');
        $executable = fn () => (int) DB::scalar(
            "select count(*) from pg_proc where proname like 'compatibility\\_\\_widgets\\_\\_%' and (has_function_privilege('public', oid, 'execute') or has_function_privilege(?, oid, 'execute'))",
            [$intruder],
        );
        $installed = $executable();
        resolve(Installer::class)->recompile(new WidgetCompatibilityMap);

        $this->assertSame([0, 0], [$installed, $executable()]);

        DB::statement('set role '.SqlFragments::identifier($intruder));

        try {
            DB::statement('create temporary table intruders (id uuid)');
            $this->assertThrows(
                fn () => DB::statement('create trigger intruder before delete on intruders for each row execute function compatibility__widgets__primary_delete()'),
                QueryException::class,
                'permission denied for function compatibility__widgets__primary_delete',
            );
        } finally {
            DB::statement('reset role');
            DB::statement('drop table if exists pg_temp.intruders');
        }
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
    public function it_copies_the_owners_privileges_to_the_view_when_the_retained_table_was_never_granted(): void
    {
        $owner = $this->role('compatibility_owner');
        resolve(Installer::class)->uninstall(new WidgetCompatibilityMap);
        DB::statement('alter table retained_widgets owner to '.SqlFragments::identifier($owner));

        resolve(Installer::class)->install(new WidgetCompatibilityMap);

        $this->assertSame(
            ['select' => true, 'insert' => true, 'update' => true, 'delete' => true],
            ['select' => $this->viewPrivilege($owner, 'SELECT'), 'insert' => $this->viewPrivilege($owner, 'INSERT'), 'update' => $this->viewPrivilege($owner, 'UPDATE'), 'delete' => $this->viewPrivilege($owner, 'DELETE')],
        );
    }

    #[Test]
    public function it_leaves_nothing_behind_when_an_install_fails(): void
    {
        resolve(Installer::class)->uninstall(new WidgetCompatibilityMap);
        $broken = new WidgetCompatibilityMap(['sku' => Attribute::retained('sku')->defaultsTo('no_such_function()')]);

        $this->assertThrows(fn () => resolve(Installer::class)->install($broken), QueryException::class, 'no_such_function');

        resolve(Installer::class)->install(new WidgetCompatibilityMap);

        $this->assertSame('Blue gadget', DB::table('widgets')->where('id', $this->widget())->sole()->title);
    }

    #[Test]
    public function it_keeps_the_installed_compatibility_map_when_an_uninstall_fails(): void
    {
        DB::statement('create view widget_titles as select title from widgets');

        $this->assertThrows(fn () => resolve(Installer::class)->uninstall(new WidgetCompatibilityMap), QueryException::class, 'other objects depend on it');

        DB::table('gadgets')->insert(['name' => 'After a failed uninstall']);

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

        DB::table('gadgets')->insert(['name' => 'Here']);
        $here = DB::table('retained_widgets')->count();
        resolve(Installer::class)->recompile(new WidgetCompatibilityMap);
        resolve(Installer::class)->uninstall(new WidgetCompatibilityMap);
        DB::table('elsewhere.gadgets')->insert(['name' => 'Elsewhere']);

        $this->assertSame([1, 1], [$here, DB::table('elsewhere.retained_widgets')->count()]);
    }

    #[Test]
    public function it_keeps_a_prefixed_trigger_on_another_schemas_table_that_calls_a_function_of_this_schema(): void
    {
        DB::statement('drop schema if exists elsewhere cascade');
        DB::statement('create schema elsewhere');
        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('drop schema if exists elsewhere cascade');
            DB::statement('drop function if exists audit_row()');
        });
        DB::statement('create table elsewhere.audited (id integer)');
        DB::unprepared('create function audit_row() returns trigger language plpgsql as $audit$ begin return new; end; $audit$');
        DB::statement('create trigger compatibility__widgets__audit before insert on elsewhere.audited for each row execute function audit_row()');

        resolve(Installer::class)->recompile(new WidgetCompatibilityMap);
        resolve(Installer::class)->uninstall(new WidgetCompatibilityMap);

        $this->assertSame(1, DB::scalar("select count(*) from pg_trigger where tgname = 'compatibility__widgets__audit' and tgrelid = 'elsewhere.audited'::regclass"));
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
    public function it_drops_a_view_default_the_compatibility_map_no_longer_declares(): void
    {
        resolve(Installer::class)->recompile(new WidgetCompatibilityMap(['sku' => Attribute::retained('sku')->defaultsTo("'SKU-0'")]));
        $declared = $this->widget();

        resolve(Installer::class)->recompile(new WidgetCompatibilityMap);
        $removed = $this->widget();

        $this->assertSame(
            ['SKU-0', null],
            [DB::table('widgets')->where('id', $declared)->value('sku'), DB::table('widgets')->where('id', $removed)->value('sku')],
        );
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
    public function it_compiles_only_full_word_aliases_variables_and_object_names(): void
    {
        foreach ([new WidgetCompatibilityMap, new AccountCompatibilityMap, new ProfileCompatibilityMap] as $map) {
            $sql = implode("\n", (new Compiler(DB::connection()))->compile($map)->statements());
            preg_match_all('/\b(?:FROM|JOIN|UPDATE)\s+"?[a-z_][a-z0-9_]*"?\s+(?:AS\s+)?([a-z_][a-z0-9_]*)/i', $sql, $relations);
            preg_match_all('/\)\s+AS\s+([a-z_][a-z0-9_]*)\s+WHERE/', $sql, $rows);
            preg_match_all('/^\s+([a-z_][a-z0-9_]*)\s+(?:\S+%(?:ROW)?TYPE|integer);$/m', $sql, $variables);
            preg_match_all('/"(compatibility__[a-z0-9_]+)"/', $sql, $objects);

            $identifiers = [
                ...ResolvedMap::of($map)->aliases(),
                ...array_filter($relations[1], fn (string $alias) => ! in_array(strtolower($alias), self::KEYWORDS, true)),
                ...$rows[1],
                ...$variables[1],
                ...$objects[1],
            ];

            $this->assertNotEmpty($variables[1]);

            foreach (array_unique($identifiers) as $identifier) {
                $this->assertMatchesRegularExpression('/^[a-z]{3,}(?:_+[a-z]{3,})*$/', $identifier, "Compatibility map '{$map->name()}' compiles a short-hand identifier: {$identifier}");
            }
        }
    }

    #[Test]
    public function it_copies_the_retained_tables_grants_to_the_view_when_it_installs(): void
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
            'editor inserts' => true,
            'editor updates every column' => false,
            'editor updates sku' => true,
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
    public function it_lets_a_role_granted_a_retained_column_write_that_column_through_the_view(): void
    {
        $editor = $this->role('compatibility_editor');
        resolve(Installer::class)->uninstall(new WidgetCompatibilityMap);
        DB::statement('grant select, update (sku) on retained_widgets to '.SqlFragments::identifier($editor));
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        $id = $this->widget();

        DB::statement('set role '.SqlFragments::identifier($editor));

        try {
            DB::table('widgets')->where('id', $id)->update(['sku' => 'Set by the editor']);
        } finally {
            DB::statement('reset role');
        }

        $this->assertSame('Set by the editor', DB::table('retained_widgets')->value('sku'));
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
        return DB::table('widgets')->insertGetId(['title' => 'Blue gadget', 'price' => '12.50', 'street' => '1 Main St', 'latitude' => 47.5]);
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
        } catch (QueryException $e) {
            $this->assertFalse(CompatibilityWriteRefused::isRefusal($e));

            return;
        }

        $this->fail('Expected the statement to fail.');
    }
}
