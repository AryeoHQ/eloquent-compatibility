<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\Attribute;
use Support\Database\Eloquent\Compatibility\Mapping\DependentCurrentModel;
use Support\Database\Eloquent\Compatibility\Mapping\PreviousModel;
use Support\Database\Eloquent\Compatibility\Mapping\PrimaryCurrentModel;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Mapping\UnrepresentableState;
use Support\Database\Eloquent\Compatibility\Testing\InteractsWithCompatibilityMaps;
use Tests\Fixtures\Support\Workbench\AccountCompatibilityMap;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\ProfileCompatibilityMap;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(Compiler::class)]
final class CompilerTest extends TestCase
{
    use InteractsWithCompatibilityMaps;

    private const string CONCURRENT_WRITER = <<<'PHP'
        $config = json_decode((string) getenv('CONCURRENT_CONNECTION'), true);
        $connection = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $connection->exec("set application_name = 'compatibility_concurrent_writer'");
        $connection->exec("set lock_timeout = '10s'");
        try {
            $connection->exec((string) getenv('CONCURRENT_STATEMENT'));
        } catch (PDOException $e) {
            echo json_encode($e->errorInfo);
            exit(1);
        }
        PHP;

    protected function setUp(): void
    {
        parent::setUp();

        Tables::drop();
        Tables::create();
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
    #[TestWith(['widgets', 6])]
    #[TestWith(['accounts', 5])]
    #[TestWith(['profiles', 5])]
    #[TestWith(['designated', 7])]
    public function it_compiles_the_view_before_its_trigger_functions_and_the_functions_before_their_triggers(string $map, int $functions): void
    {
        $compatibilityMap = $this->workbenchMap($map);
        $compiledSql = $this->compiler()->compile($compatibilityMap);
        $prefix = ResolvedMap::of($compatibilityMap)->objectPrefix();
        $installed = fn () => [
            DB::scalar('select to_regclass(?) is not null', [SqlFragments::identifier($compatibilityMap->previousModel()->view)]),
            $this->objects('pg_proc', 'proname', $prefix),
            $this->objects('pg_trigger', 'tgname', $prefix),
        ];

        $this->runInOrder($compiledSql->view);
        $afterView = $installed();
        $this->runInOrder($compiledSql->functions);
        $afterFunctions = $installed();
        $this->runInOrder($compiledSql->triggers);

        $this->assertSame([[true, 0, 0], [true, $functions, 0], [true, $functions, $functions]], [$afterView, $afterFunctions, $installed()]);
    }

    /**
     * @param  array<string, string>  $triggers
     */
    #[Test]
    #[TestWith(['widgets', [
        'compatibility__widgets__delete' => 'INSTEAD OF DELETE ON widgets EXECUTE FUNCTION compatibility__widgets__delete()',
        'compatibility__widgets__insert' => 'INSTEAD OF INSERT ON widgets EXECUTE FUNCTION compatibility__widgets__insert()',
        'compatibility__widgets__primary_delete' => 'BEFORE DELETE ON gadgets EXECUTE FUNCTION compatibility__widgets__primary_delete()',
        'compatibility__widgets__primary_insert' => 'AFTER INSERT ON gadgets EXECUTE FUNCTION compatibility__widgets__primary_insert()',
        'compatibility__widgets__refuse_second_root_place' => 'BEFORE INSERT OR UPDATE ON gadget_places EXECUTE FUNCTION compatibility__widgets__refuse_second_root_place()',
        'compatibility__widgets__update' => 'INSTEAD OF UPDATE ON widgets EXECUTE FUNCTION compatibility__widgets__update()',
    ]])]
    #[TestWith(['accounts', [
        'compatibility__accounts__delete' => 'INSTEAD OF DELETE ON accounts EXECUTE FUNCTION compatibility__accounts__delete()',
        'compatibility__accounts__insert' => 'INSTEAD OF INSERT ON accounts EXECUTE FUNCTION compatibility__accounts__insert()',
        'compatibility__accounts__primary_delete' => 'BEFORE DELETE ON people EXECUTE FUNCTION compatibility__accounts__primary_delete()',
        'compatibility__accounts__primary_insert' => 'AFTER INSERT ON people EXECUTE FUNCTION compatibility__accounts__primary_insert()',
        'compatibility__accounts__update' => 'INSTEAD OF UPDATE ON accounts EXECUTE FUNCTION compatibility__accounts__update()',
    ]])]
    #[TestWith(['designated', [
        'compatibility__widgets__delete' => 'INSTEAD OF DELETE ON widgets EXECUTE FUNCTION compatibility__widgets__delete()',
        'compatibility__widgets__designate_place' => 'AFTER INSERT ON gadget_places EXECUTE FUNCTION compatibility__widgets__designate_place()',
        'compatibility__widgets__insert' => 'INSTEAD OF INSERT ON widgets EXECUTE FUNCTION compatibility__widgets__insert()',
        'compatibility__widgets__primary_delete' => 'BEFORE DELETE ON gadgets EXECUTE FUNCTION compatibility__widgets__primary_delete()',
        'compatibility__widgets__primary_insert' => 'AFTER INSERT ON gadgets EXECUTE FUNCTION compatibility__widgets__primary_insert()',
        'compatibility__widgets__refuse_second_root_place' => 'BEFORE INSERT OR UPDATE ON gadget_places EXECUTE FUNCTION compatibility__widgets__refuse_second_root_place()',
        'compatibility__widgets__update' => 'INSTEAD OF UPDATE ON widgets EXECUTE FUNCTION compatibility__widgets__update()',
    ]])]
    public function it_attaches_each_trigger_function_to_its_table_and_events(string $map, array $triggers): void
    {
        $compatibilityMap = $this->workbenchMap($map);

        $this->runInOrder($this->compiler()->compile($compatibilityMap)->statements());

        $this->assertSame($triggers, $this->triggers(ResolvedMap::of($compatibilityMap)->objectPrefix()));
    }

    #[Test]
    public function it_defaults_a_view_column_to_its_declared_default_or_else_to_the_default_of_a_retained_or_primary_column_that_stores_it(): void
    {
        $this->runInOrder($this->compiler()->compile($this->compatibilityMap(['attributes' => [
            ...(new WidgetCompatibilityMap(['sku' => Attribute::retained('sku')->defaultsTo("'SKU-0'")]))->attributes(),
            Attribute::mapped('longitude')->asType('numeric(24,12)')->storedAs('site', 'longitude'),
        ]]))->statements());

        $defaults = DB::select(
            "select column_name as name, column_default as expression from information_schema.columns
             where table_schema = current_schema() and table_name = 'widgets' and column_default is not null order by ordinal_position",
        );
        DB::table('widgets')->insert(['title' => 'Defaults only']);

        $this->assertSame([
            'id' => "nextval('retained_widgets_id_seq'::regclass)",
            'uuid' => 'gen_random_uuid()',
            'sku' => "'SKU-0'::character varying",
            'status' => "'Draft'::character varying",
            'is_archived' => 'false',
        ], array_column(array_map(fn (object $default) => (array) $default, $defaults), 'expression', 'name'));
        $this->assertSame(['SKU-0', 'Draft'], [DB::table('retained_widgets')->value('sku'), DB::table('retained_widgets')->value('status')]);
        $this->assertSame(0, DB::table('gadget_sites')->count());
    }

    #[Test]
    public function it_runs_the_trigger_functions_on_the_schema_of_the_connection_it_was_built_on_whatever_the_writers_search_path(): void
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
            $this->runInOrder($this->compiler()->compile(new WidgetCompatibilityMap)->statements());
        } finally {
            DB::statement('set search_path to '.$searchPath);
        }

        DB::table('Tenant.widgets')->insert(['title' => 'Written from public']);

        $this->assertSame([1, 1, 0, 0], [
            DB::table('Tenant.gadgets')->count(),
            DB::table('Tenant.retained_widgets')->count(),
            DB::table('gadgets')->count(),
            DB::table('retained_widgets')->count(),
        ]);
    }

    #[Test]
    public function it_compiles_without_running_any_statement(): void
    {
        $this->compiler()->compile(new WidgetCompatibilityMap);

        $this->assertSame(
            [false, 0, 0],
            [DB::scalar("select to_regclass('widgets') is not null"), $this->objects('pg_proc', 'proname', 'compatibility__widgets__'), $this->objects('pg_trigger', 'tgname', 'compatibility__widgets__')],
        );
    }

    #[Test]
    public function it_compiles_the_same_sql_from_a_map_and_from_its_resolved_map(): void
    {
        $this->assertEquals($this->compiler()->compile(new WidgetCompatibilityMap), $this->compiler()->compile(ResolvedMap::of(new WidgetCompatibilityMap)));
    }

    #[Test]
    #[TestWith(['missing_retained_table', 'the retained table no_such_table does not exist'])]
    #[TestWith(['missing_key', 'retained_widgets has no column nope'])]
    #[TestWith(['missing_primary_foreign_key', 'retained_widgets has no column nope'])]
    #[TestWith(['missing_designation', 'retained_widgets has no column nope'])]
    #[TestWith(['missing_retained_attribute', 'retained attribute nope is not a column of retained_widgets'])]
    #[TestWith(['written_primary_foreign_key', 'attribute gadget_id writes the primary foreign key gadget_id; declare it read-only'])]
    #[TestWith(['written_designation', 'attribute place_id writes the designation column place_id; the compatibility map maintains it'])]
    #[TestWith(['missing_dependent_primary_foreign_key', 'gadget_places has no column nope'])]
    #[TestWith(['missing_mapped_column', 'gadgets has no column nope'])]
    #[TestWith(['missing_previous_fill_column', 'retained_widgets has no column nope'])]
    #[TestWith(['missing_primary_fill_column', 'gadgets has no column nope'])]
    #[TestWith(['missing_dependent_fill_column', 'gadget_places has no column nope'])]
    #[TestWith(['primary_fill_collision', 'gadget.name is set both by an attribute and by fillWhenCreatedFromPrevious()'])]
    #[TestWith(['dependent_fill_collision', 'place.street is set both by an attribute and by fillWhenCreatedFromPrevious()'])]
    #[TestWith(['long_refuse_function', 'the database object name compatibility__widgets__refuse_a_state_name_of_thirty_three_char exceeds 63 characters'])]
    #[TestWith(['long_designate_function', 'the database object name compatibility__widgets__designate_another_place_with_a_long_name exceeds 63 characters'])]
    #[TestWith(['delimiter_in_fill', 'the SQL of trigger function compatibility__widgets__insert contains $compatibility$, which would end its body early; remove it from the map'])]
    #[TestWith(['delimiter_in_condition', 'the SQL of trigger function compatibility__widgets__refuse_quoted contains $compatibility$, which would end its body early; remove it from the map'])]
    public function it_refuses_at_compile_time_a_map_the_tables_cannot_store(string $case, string $message): void
    {
        $this->assertThrows(fn () => $this->compiler()->compile($this->refusedMap($case)), InvalidCompatibilityMap::class, "Compatibility map 'widgets' is declared wrongly: {$message}");
    }

    #[Test]
    public function it_installs_an_object_name_of_exactly_63_characters_without_truncating_it(): void
    {
        $function = 'compatibility__widgets__refuse_a_state_name_of_thirty_two_chars';

        $this->runInOrder($this->compiler()->compile($this->compatibilityMap(['unrepresentableStates' => [
            ...(new WidgetCompatibilityMap)->unrepresentableStates(),
            UnrepresentableState::refuse('a_state_name_of_thirty_two_chars', 'a state', 'Because.', 'Instead.')->checkedOn('place', 'false'),
        ]]))->statements());

        $this->assertSame(63, strlen($function));
        $this->assertSame([1, 1], [$this->objects('pg_proc', 'proname', $function), $this->objects('pg_trigger', 'tgname', $function)]);
    }

    #[Test]
    public function it_compiles_every_trigger_function_of_a_map_whose_name_has_the_most_characters_allowed(): void
    {
        $compiledSql = $this->compiler()->compile($this->compatibilityMap(['name' => 'longest_allowed_map_name'], new AccountCompatibilityMap));

        preg_match_all('/^CREATE OR REPLACE FUNCTION "([a-z_]+)"\(\)/m', implode("\n", $compiledSql->functions), $functions);

        $this->assertSame([
            'compatibility__longest_allowed_map_name__insert',
            'compatibility__longest_allowed_map_name__update',
            'compatibility__longest_allowed_map_name__delete',
            'compatibility__longest_allowed_map_name__primary_insert',
            'compatibility__longest_allowed_map_name__primary_delete',
        ], $functions[1]);
    }

    #[Test]
    #[TestWith(["update gadgets set name = 'Renamed concurrently'"])]
    #[TestWith(["update retained_widgets set status = 'Live'"])]
    public function it_raises_a_serialization_failure_when_a_primary_or_retained_attribute_changed_after_the_statement_read_the_view(string $concurrentChange): void
    {
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        $id = $this->widget();

        DB::beginTransaction();
        DB::unprepared($concurrentChange);
        $writer = $this->writeConcurrently("update widgets set sku = 'SKU-1' where id = {$id}");
        DB::commit();
        [$state, $error] = $this->failure($writer);

        $this->assertSame('40001', $state);
        $this->assertStringContainsString("ERROR:  Compatibility map 'widgets' found a concurrent change to widgets record {$id} since this statement's snapshot", $error);
        $this->assertNull(DB::table('retained_widgets')->value('sku'));

        DB::table('widgets')->where('id', $id)->update(['sku' => 'SKU-1']);

        $this->assertSame('SKU-1', DB::table('retained_widgets')->value('sku'));
    }

    #[Test]
    #[TestWith(["update gadget_places set street = 'Concurrent St'", "update widgets set street = 'Stale St' where id = %d", 'street', 'Concurrent St'])]
    #[TestWith(["update gadget_places set street = 'Concurrent St'", "update widgets set sku = 'SKU-1' where id = %d", 'sku', null])]
    #[TestWith(['update gadget_sites set latitude = 1', 'update widgets set latitude = 2 where id = %d', 'latitude', 1.0])]
    public function it_raises_a_serialization_failure_when_a_dependent_attribute_changed_after_the_statement_read_the_view(string $concurrentChange, string $write, string $attribute, null|float|string $kept): void
    {
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        $id = $this->widget();

        DB::beginTransaction();
        DB::unprepared($concurrentChange);
        $writer = $this->writeConcurrently(sprintf($write, $id));
        DB::commit();
        [$state, $error] = $this->failure($writer);

        $this->assertSame('40001', $state);
        $this->assertStringContainsString("ERROR:  Compatibility map 'widgets' found a concurrent change to widgets record {$id} since this statement's snapshot", $error);
        $this->assertSame($kept, DB::table('widgets')->where('id', $id)->value($attribute));

        DB::unprepared(sprintf($write, $id));

        $this->assertNotSame($kept, DB::table('widgets')->where('id', $id)->value($attribute));
    }

    #[Test]
    public function it_lets_a_write_through_proceed_when_its_dependent_row_is_gone_after_the_statement_read_the_view(): void
    {
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        $id = $this->widget();

        DB::beginTransaction();
        DB::table('gadgets')->lockForUpdate()->sole();
        DB::table('gadget_places')->delete();
        $writer = $this->writeConcurrently("update widgets set sku = 'SKU-1' where id = {$id}");
        DB::commit();
        $result = $writer->wait();

        $this->assertTrue($result->successful(), $result->output().$result->errorOutput());
        $this->assertSame(['SKU-1', null], [DB::table('widgets')->where('id', $id)->value('sku'), DB::table('widgets')->where('id', $id)->value('street')]);
    }

    #[Test]
    public function it_compares_a_json_attribute_by_its_text_in_every_write_through_the_view(): void
    {
        Schema::table('retained_widgets', function (Blueprint $table): void {
            $table->json('settings')->nullable();
            $table->json('shipped_settings')->nullable();
        });
        Schema::table('gadgets', function (Blueprint $table): void {
            $table->json('specs')->nullable();
        });
        resolve(Installer::class)->install($this->compatibilityMap(['attributes' => [
            ...(new WidgetCompatibilityMap)->attributes(),
            Attribute::retained('settings'),
            Attribute::retained('shipped_settings')->readOnlyBecause('A widget keeps the settings it shipped with.'),
            Attribute::mapped('specs')->asType('json')->storedAs('gadget', 'specs'),
        ]]));
        $id = DB::table('widgets')->insertGetId(['title' => 'Blue gadget', 'settings' => '{"a":1}', 'shipped_settings' => '{"b":2}', 'specs' => '{"c":3}']);

        DB::table('widgets')->where('id', $id)->update(['sku' => 'SKU-1']);
        DB::table('widgets')->where('id', $id)->update(['settings' => '{"a": 1}', 'specs' => '{"c": 3}']);

        $this->assertSame(
            ['SKU-1', '{"a": 1}', '{"b":2}', '{"c": 3}'],
            [DB::table('retained_widgets')->value('sku'), DB::table('retained_widgets')->value('settings'), DB::table('retained_widgets')->value('shipped_settings'), DB::table('gadgets')->value('specs')],
        );
        $this->assertWriteRefused(fn () => DB::table('widgets')->where('id', $id)->update(['shipped_settings' => '{"b": 2}']), 'refused a change to widgets.shipped_settings');
    }

    #[Test]
    public function it_returns_every_attribute_of_an_insert_through_the_view_as_the_view_reads_it(): void
    {
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        DB::unprepared("create function fill_sku() returns trigger language plpgsql as \$fill\$ begin NEW.sku := coalesce(NEW.sku, 'SKU-from-a-trigger'); return NEW; end \$fill\$");
        DB::statement('create trigger fill_sku before insert on retained_widgets for each row execute function fill_sku()');
        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('drop function if exists fill_sku() cascade');
        });

        $returned = DB::selectOne("insert into widgets (title, price, street) values ('Blue gadget', '12.5', '1 Main St') returning *");

        $this->assertEquals(DB::table('widgets')->sole(), $returned);
        $this->assertSame(
            ['Draft', 'SKU-from-a-trigger', DB::table('gadgets')->value('id'), '12.50', false],
            [$returned->status, $returned->sku, $returned->gadget_id, $returned->price, $returned->is_archived],
        );
    }

    #[Test]
    #[TestWith(['insert'])]
    #[TestWith(['update'])]
    #[TestWith(['delete'])]
    #[TestWith(['primary_insert'])]
    #[TestWith(['primary_delete'])]
    #[TestWith(['refuse_second_root_place'])]
    public function it_refuses_to_run_a_trigger_function_fired_on_any_relation_but_its_own(string $function): void
    {
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        DB::statement('create table intruders (id uuid, gadget_id uuid)');
        DB::statement("create trigger intruder before insert on intruders for each row execute function compatibility__widgets__{$function}()");
        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('drop table if exists intruders');
        });

        $refusal = $this->assertWriteRefused(fn () => DB::table('intruders')->insert(['id' => DB::table('gadgets')->value('id')]));

        $this->assertSame("Compatibility map 'widgets' refused to run compatibility__widgets__{$function} on public.intruders", $refusal->refused);
    }

    #[Test]
    public function it_runs_its_trigger_functions_on_the_partitions_of_a_partitioned_current_table(): void
    {
        foreach (['gadgets', 'gadget_places'] as $table) {
            DB::unprepared("create table {$table}_partitioned (like {$table} including all) partition by hash (id); drop table {$table} cascade; alter table {$table}_partitioned rename to {$table}; create table {$table}_0 partition of {$table} for values with (modulus 1, remainder 0)");
        }
        resolve(Installer::class)->install(new WidgetCompatibilityMap);

        DB::table('widgets')->insert(['title' => 'Through the view', 'street' => '1 Main St']);
        $gadget = DB::table('gadgets')->insertGetId(['name' => 'Directly']);
        DB::table('gadget_places')->insert(['gadget_id' => $gadget, 'street' => '2 Direct St']);

        $this->assertWriteRefused(fn () => DB::table('gadget_places')->insert(['gadget_id' => $gadget, 'street' => '3 Second Root St']), 'refused a second root place for one gadget');
        $this->assertSame(['Directly' => '2 Direct St', 'Through the view' => '1 Main St'], DB::table('widgets')->orderBy('title')->pluck('street', 'title')->all());

        DB::table('widgets')->where('title', 'Through the view')->delete();
        DB::table('gadget_places')->where('gadget_id', $gadget)->delete();
        DB::table('gadgets')->where('id', $gadget)->delete();

        $this->assertSame([0, 0, 0], [DB::table('widgets')->count(), DB::table('retained_widgets')->count(), DB::table('gadgets')->count()]);
    }

    #[Test]
    public function it_refuses_the_primary_delete_function_fired_on_another_table_before_it_deletes_a_retained_row(): void
    {
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        $this->widget();
        DB::statement('create table intruders (id uuid)');
        DB::statement('create trigger intruder before delete on intruders for each row execute function compatibility__widgets__primary_delete()');
        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('drop table if exists intruders');
        });
        DB::table('intruders')->insert(['id' => DB::table('gadgets')->value('id')]);

        $this->assertWriteRefused(fn () => DB::table('intruders')->delete(), 'refused to run compatibility__widgets__primary_delete on public.intruders');

        $this->assertSame([1, 1], [DB::table('retained_widgets')->count(), DB::table('intruders')->count()]);
    }

    /**
     * @param  array<string, string>  $previousFills
     */
    #[Test]
    #[TestWith(['alter table retained_widgets add column region text not null', [], 'retained_widgets.region is NOT NULL without a default, and a gadgets row created through its own model leaves it empty; fill it with fillWhenCreatedFromCurrent()'])]
    #[TestWith(['alter table retained_widgets add column region text not null', ['region' => "'North'"], 'retained_widgets.region is NOT NULL without a default, and an insert through widgets leaves it empty; declare it as a retained attribute'])]
    #[TestWith(['alter table gadgets add column code text not null', [], 'gadgets.code is NOT NULL without a default, and an insert through widgets leaves it empty; map an attribute to it or fill it with fillWhenCreatedFromPrevious()'])]
    #[TestWith(['alter table gadget_places add column kind text not null', [], 'gadget_places.kind is NOT NULL without a default, and an insert through widgets leaves it empty; map an attribute to it or fill it with fillWhenCreatedFromPrevious()'])]
    public function it_refuses_a_not_null_column_without_a_default_that_an_insert_leaves_empty(string $column, array $previousFills, string $message): void
    {
        DB::statement($column);
        $previous = (new WidgetCompatibilityMap)->previousModel();
        $map = $this->compatibilityMap(['previousModel' => $previous->fillWhenCreatedFromCurrent([...$previous->fillWhenCreatedFromCurrent, ...$previousFills])]);

        $this->assertThrows(fn () => resolve(Installer::class)->install($map), InvalidCompatibilityMap::class, "Compatibility map 'widgets' is declared wrongly: {$message}");

        $this->assertNull(DB::scalar("select to_regclass('widgets')"));
    }

    #[Test]
    public function it_counts_a_filled_default_identity_or_generated_column_as_filled_on_every_insert(): void
    {
        DB::statement("alter table retained_widgets add column region text not null default 'North'");
        DB::statement('alter table retained_widgets add column code text generated always as (upper(status)) stored');
        DB::statement('alter table gadgets add column serial_number integer not null generated always as identity');
        DB::statement('alter table gadget_places add column kind text not null');
        resolve(Installer::class)->install($this->compatibilityMap(['dependentCurrentModels' => $this->dependents(place: fn (DependentCurrentModel $place) => $place->fillWhenCreatedFromPrevious(['kind' => "'Root'"]))]));

        $this->widget();
        DB::table('gadgets')->insert(['name' => 'Created through its own model']);

        $this->assertSame(
            [['North', 'DRAFT'], ['North', 'DRAFT']],
            DB::table('retained_widgets')->orderBy('id')->get(['region', 'code'])->map(fn (object $row) => [$row->region, $row->code])->all(),
        );
        $this->assertSame([1, 2], DB::table('gadgets')->orderBy('serial_number')->pluck('serial_number')->all());
        $this->assertSame('Root', DB::table('gadget_places')->value('kind'));
    }

    #[Test]
    #[TestWith(["update widgets set sku = 'SKU-1' where id = %d"])]
    #[TestWith(['delete from widgets where id = %d'])]
    public function it_refuses_a_write_through_the_view_whose_retained_row_is_gone(string $statement): void
    {
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        $id = $this->widget();

        DB::beginTransaction();
        DB::table('gadgets')->lockForUpdate()->sole();
        DB::table('retained_widgets')->where('id', $id)->delete();
        $writer = $this->writeConcurrently(sprintf($statement, $id));
        DB::commit();
        [$state, $error] = $this->failure($writer);

        $this->assertSame(SqlState::Refused->value, $state);
        $this->assertStringContainsString("ERROR:  Compatibility map 'widgets' refused a write to widgets record {$id}: its retained_widgets row is missing", $error);
        $this->assertStringContainsString('HINT:  Run php artisan compatibility:check --map=widgets and repair the record it names.', $error);
        $this->assertSame([1, 1], [DB::table('gadgets')->count(), DB::table('gadget_places')->count()]);
    }

    #[Test]
    public function it_refuses_a_write_through_the_view_that_updates_no_primary_row(): void
    {
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        $id = $this->widget();

        DB::beginTransaction();
        DB::table('gadgets')->lockForUpdate()->sole();
        DB::table('retained_widgets')->where('id', $id)->update(['gadget_id' => null]);
        $writer = $this->writeConcurrently("update widgets set title = 'Renamed' where id = {$id}");
        DB::commit();
        [$state, $error] = $this->failure($writer);

        $this->assertSame(SqlState::Refused->value, $state);
        $this->assertStringContainsString("ERROR:  Compatibility map 'widgets' refused a write to widgets record {$id}: its gadgets row is missing", $error);
        $this->assertSame('Blue gadget', DB::table('gadgets')->value('name'));
    }

    private function compiler(): Compiler
    {
        return new Compiler(DB::connection());
    }

    /**
     * @param  list<string>  $statements
     */
    private function runInOrder(array $statements): void
    {
        foreach ($statements as $statement) {
            DB::unprepared($statement);
        }
    }

    private function objects(string $catalog, string $column, string $prefix): int
    {
        return (int) DB::scalar("select count(*) from {$catalog} where {$column} like ".SqlFragments::like($prefix));
    }

    /**
     * @return array<string, string>
     */
    private function triggers(string $prefix): array
    {
        $triggers = DB::select(
            "select trigger_name as name, action_timing || ' ' || string_agg(event_manipulation, ' OR ' order by event_manipulation) || ' ON ' || event_object_table || ' ' || action_statement as fires
             from information_schema.triggers where trigger_schema = current_schema() and trigger_name like ".SqlFragments::like($prefix).'
             group by trigger_name, action_timing, event_object_table, action_statement order by trigger_name',
        );

        return array_column(array_map(fn (object $trigger) => (array) $trigger, $triggers), 'fires', 'name');
    }

    private function widget(): int
    {
        return DB::table('widgets')->insertGetId(['title' => 'Blue gadget', 'price' => '12.50', 'street' => '1 Main St', 'latitude' => 47.5]);
    }

    private function writeConcurrently(string $statement): InvokedProcess
    {
        config(['database.connections.concurrent' => config('database.connections.'.config('database.default'))]);
        $this->beforeApplicationDestroyed(fn () => DB::purge('concurrent'));
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
     * @return array{string, string}
     */
    private function failure(InvokedProcess $writer): array
    {
        $result = $writer->wait();

        $this->assertTrue($result->failed(), 'Expected the concurrent write to fail. '.$result->output());

        $errorInfo = json_decode($result->output(), true);
        $this->assertIsArray($errorInfo);

        return [(string) $errorInfo[0], (string) $errorInfo[2]];
    }

    private function workbenchMap(string $map): CompatibilityMap
    {
        return [
            'widgets' => new WidgetCompatibilityMap,
            'accounts' => new AccountCompatibilityMap,
            'profiles' => new ProfileCompatibilityMap,
            'designated' => $this->compatibilityMap(['dependentCurrentModels' => $this->dependents(place: fn (DependentCurrentModel $place) => $place->designatedBy('place_id'))]),
        ][$map];
    }

    private function refusedMap(string $case): CompatibilityMap
    {
        $widgets = new WidgetCompatibilityMap;
        $previous = $widgets->previousModel();
        $attributes = $widgets->attributes();
        $gadget = PrimaryCurrentModel::for(Gadget::class)->aliasedAs('gadget');

        return $this->compatibilityMap([
            'missing_retained_table' => ['previousModel' => $previous->retainedInTable('no_such_table')],
            'missing_key' => ['previousModel' => $previous->keyedBy('nope'), 'attributes' => [...$attributes, Attribute::retained('nope')->readOnlyBecause('Keyed.')]],
            'missing_primary_foreign_key' => ['previousModel' => $previous->primaryForeignKey('nope'), 'attributes' => [...$attributes, Attribute::retained('nope')->readOnlyBecause('Linked.')]],
            'missing_designation' => ['dependentCurrentModels' => $this->dependents(place: fn (DependentCurrentModel $place) => $place->designatedBy('nope'))],
            'missing_retained_attribute' => ['attributes' => [...$attributes, Attribute::retained('nope')]],
            'written_primary_foreign_key' => ['attributes' => (new WidgetCompatibilityMap(['gadget_id' => Attribute::retained('gadget_id')]))->attributes()],
            'written_designation' => [
                'dependentCurrentModels' => $this->dependents(place: fn (DependentCurrentModel $place) => $place->designatedBy('place_id')),
                'attributes' => [...$attributes, Attribute::retained('place_id')],
            ],
            'missing_dependent_primary_foreign_key' => ['dependentCurrentModels' => $this->dependents(place: fn (DependentCurrentModel $place) => $place->primaryForeignKey('nope'))],
            'missing_mapped_column' => ['attributes' => (new WidgetCompatibilityMap(['title' => Attribute::mapped('title')->asType('text')->storedAs('gadget', 'nope')]))->attributes()],
            'missing_previous_fill_column' => ['previousModel' => $previous->fillWhenCreatedFromCurrent(['nope' => "'Filled'"])],
            'missing_primary_fill_column' => ['primaryCurrentModel' => $gadget->fillWhenCreatedFromPrevious(['nope' => "'Filled'"])],
            'missing_dependent_fill_column' => ['dependentCurrentModels' => $this->dependents(place: fn (DependentCurrentModel $place) => $place->fillWhenCreatedFromPrevious(['nope' => "'Filled'"]))],
            'primary_fill_collision' => ['primaryCurrentModel' => $gadget->fillWhenCreatedFromPrevious(['name' => "'Filled'"])],
            'dependent_fill_collision' => ['dependentCurrentModels' => $this->dependents(place: fn (DependentCurrentModel $place) => $place->fillWhenCreatedFromPrevious(['street' => "'Filled'"]))],
            'delimiter_in_fill' => ['primaryCurrentModel' => $gadget->fillWhenCreatedFromPrevious(['id' => 'COALESCE(NEW.gadget_id, $compatibility$00000000-0000-0000-0000-000000000000$compatibility$::uuid)'])],
            'delimiter_in_condition' => ['unrepresentableStates' => [
                ...$widgets->unrepresentableStates(),
                UnrepresentableState::refuse('quoted', 'a quoted street', 'Because.', 'Instead.')->checkedOn('place', 'place.street = $compatibility$Quoted$compatibility$'),
            ]],
            'long_refuse_function' => ['unrepresentableStates' => [
                ...$widgets->unrepresentableStates(),
                UnrepresentableState::refuse('a_state_name_of_thirty_three_char', 'a state', 'Because.', 'Instead.')->checkedOn('place', 'false'),
            ]],
            'long_designate_function' => ['dependentCurrentModels' => [
                ...$widgets->dependentCurrentModels(),
                DependentCurrentModel::for((new class extends Model
                {
                    protected $table = 'gadget_places';
                })::class)->aliasedAs('another_place_with_a_long_name')->primaryForeignKey('gadget_id')->designatedBy('place_id'),
            ]],
        ][$case]);
    }

    /**
     * @param  callable(DependentCurrentModel): DependentCurrentModel  $place
     * @return list<DependentCurrentModel>
     */
    private function dependents(callable $place): array
    {
        [$gadgetPlace, $gadgetSite] = (new WidgetCompatibilityMap)->dependentCurrentModels();

        return [$place($gadgetPlace), $gadgetSite];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function compatibilityMap(array $overrides, CompatibilityMap $base = new WidgetCompatibilityMap): CompatibilityMap
    {
        return new readonly class($base, $overrides) implements CompatibilityMap
        {
            /**
             * @param  array<string, mixed>  $overrides
             */
            public function __construct(private CompatibilityMap $base, private array $overrides) {}

            public function name(): string
            {
                return $this->overrides['name'] ?? $this->base->name();
            }

            public function previousModel(): PreviousModel
            {
                return $this->overrides['previousModel'] ?? $this->base->previousModel();
            }

            public function primaryCurrentModel(): PrimaryCurrentModel
            {
                return $this->overrides['primaryCurrentModel'] ?? $this->base->primaryCurrentModel();
            }

            public function dependentCurrentModels(): array
            {
                return $this->overrides['dependentCurrentModels'] ?? $this->base->dependentCurrentModels();
            }

            public function attributes(): array
            {
                return $this->overrides['attributes'] ?? $this->base->attributes();
            }

            public function unrepresentableStates(): array
            {
                return $this->overrides['unrepresentableStates'] ?? $this->base->unrepresentableStates();
            }
        };
    }
}
