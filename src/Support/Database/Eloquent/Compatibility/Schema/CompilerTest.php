<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
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
use Support\Database\Eloquent\Compatibility\Mapping\PlannerIndex;
use Support\Database\Eloquent\Compatibility\Mapping\PreviousModel;
use Support\Database\Eloquent\Compatibility\Mapping\PrimaryCurrentModel;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Mapping\UnrepresentableState;
use Support\Database\Eloquent\Compatibility\Testing\InteractsWithCompatibilityMaps;
use Tests\Fixtures\Support\Workbench\AccountCompatibilityMap;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\Person;
use Tests\Fixtures\Support\Workbench\ProfileCompatibilityMap;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\Widget;
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
    #[TestWith(['widgets', 6, 1, 1])]
    #[TestWith(['accounts', 5, 0, 0])]
    #[TestWith(['profiles', 5, 0, 0])]
    #[TestWith(['designated', 7, 1, 1])]
    public function it_compiles_the_view_before_its_trigger_functions_the_functions_before_their_triggers_and_the_triggers_before_the_planner_statements(string $map, int $functions, int $indexes, int $statistics): void
    {
        $compatibilityMap = $this->workbenchMap($map);
        $compiledSql = $this->compiler()->compile($compatibilityMap);
        $prefix = ResolvedMap::of($compatibilityMap)->objectPrefix();
        $installed = fn () => [
            DB::scalar('select to_regclass(?) is not null', [SqlFragments::identifier($compatibilityMap->previousModel()->view)]),
            $this->objects('pg_proc', 'proname', $prefix),
            $this->objects('pg_trigger', 'tgname', $prefix),
            $this->objects('pg_indexes', 'indexname', $prefix),
            $this->objects('pg_statistic_ext', 'stxname', $prefix),
        ];

        $this->runInOrder($compiledSql->view);
        $afterView = $installed();
        $this->runInOrder($compiledSql->functions);
        $afterFunctions = $installed();
        $this->runInOrder($compiledSql->triggers);
        $afterTriggers = $installed();
        $this->runInOrder($compiledSql->planner);

        $this->assertSame(
            [[true, 0, 0, 0, 0], [true, $functions, 0, 0, 0], [true, $functions, $functions, 0, 0], [true, $functions, $functions, $indexes, $statistics]],
            [$afterView, $afterFunctions, $afterTriggers, $installed()],
        );
    }

    #[Test]
    public function it_compiles_the_index_builds_then_the_statistics_then_an_analyze_of_the_primary_table_then_the_markers_and_builds_the_indexes_concurrently_for_prepare(): void
    {
        $expressions = '(CAST(("created_at" AT TIME ZONE \'UTC\') AS timestamp(0) without time zone)), (CAST(("deleted_at" AT TIME ZONE \'UTC\') AS timestamp(0) without time zone)), '
            .'(CAST(("delivered_at" AT TIME ZONE \'UTC\') AS timestamp(0) without time zone)), (CAST(("updated_at" AT TIME ZONE \'UTC\') AS timestamp(0) without time zone))';
        $definition = 'ON "gadgets" (CAST(("archived") AS boolean), CAST(("created_at" AT TIME ZONE \'UTC\') AS timestamp(0) without time zone) DESC) '
            .'WHERE CAST(("deleted_at" AT TIME ZONE \'UTC\') AS timestamp(0) without time zone) IS NULL';
        $index = 'IF NOT EXISTS "compatibility__widgets__index_archived_newest" '.$definition;
        $planner = fn (string $build) => [
            $build.$index,
            'CREATE STATISTICS "compatibility__widgets__statistics" ON '.$expressions.' FROM "gadgets"',
            'ANALYZE "gadgets"',
            'COMMENT ON STATISTICS "compatibility__widgets__statistics" IS \''.str_replace("'", "''", $expressions).'\'',
            'COMMENT ON INDEX "compatibility__widgets__index_archived_newest" IS \''.str_replace("'", "''", $definition).'\'',
        ];

        $this->assertSame(
            [$planner('CREATE INDEX '), $planner('CREATE INDEX CONCURRENTLY ')],
            [$this->compiler()->compile(new WidgetCompatibilityMap)->planner, $this->compiler()->prepare(new WidgetCompatibilityMap)],
        );
    }

    #[Test]
    public function it_compiles_an_index_and_no_statistics_for_a_map_that_converts_no_time_zone(): void
    {
        $map = $this->compatibilityMap(['primaryCurrentModel' => PrimaryCurrentModel::for(Person::class)->aliasedAs('person')->indexedBy(PlannerIndex::named('with_email')->on('email')->whereNotNull('email'))], new AccountCompatibilityMap);

        $planner = $this->compiler()->compile($map)->planner;
        resolve(Installer::class)->install($map);

        $this->assertSame([
            'CREATE INDEX IF NOT EXISTS "compatibility__accounts__index_with_email" ON "people" (CAST(("email") AS character varying(255))) WHERE CAST(("email") AS character varying(255)) IS NOT NULL',
            'ANALYZE "people"',
            'COMMENT ON INDEX "compatibility__accounts__index_with_email" IS \'ON "people" (CAST(("email") AS character varying(255))) WHERE CAST(("email") AS character varying(255)) IS NOT NULL\'',
        ], $planner);
        $this->assertSame([1, 0], [$this->objects('pg_indexes', 'indexname', 'compatibility__accounts__'), $this->objects('pg_statistic_ext', 'stxname', 'compatibility__accounts__')]);
    }

    #[Test]
    public function it_compiles_no_planner_statement_for_a_map_that_declares_no_index_and_converts_no_time_zone(): void
    {
        $this->assertSame([[], []], [$this->compiler()->compile(new AccountCompatibilityMap)->planner, $this->compiler()->prepare(new AccountCompatibilityMap)]);
    }

    #[Test]
    public function it_stores_a_zoned_read_only_attribute_on_an_insert_through_the_view_in_its_time_zone_whatever_the_session_time_zone(): void
    {
        resolve(Installer::class)->install(new WidgetCompatibilityMap([
            'created_at' => Attribute::mapped('created_at')->asType('timestamp(0) without time zone')->storedAs('gadget', 'created_at')->inTimeZone('UTC')->readOnlyBecause('A widget keeps the time it was created.'),
        ]));
        DB::beginTransaction();
        DB::statement("set local time zone 'America/Chicago'");

        $id = DB::table('widgets')->insertGetId(['title' => 'Stamped', 'created_at' => '2026-10-28 12:00:00']);

        $this->assertSame(
            ['2026-10-28 12:00:00', '2026-10-28 12:00:00'],
            [DB::scalar("select to_char(created_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') from gadgets"), DB::table('widgets')->where('id', $id)->value('created_at')],
        );
    }

    #[Test]
    public function it_defaults_a_zoned_view_column_to_the_default_of_its_column_in_its_time_zone_whatever_the_session_time_zone(): void
    {
        DB::statement('alter table gadgets alter column created_at set default now()');
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        DB::beginTransaction();
        DB::statement("set local time zone 'America/Chicago'");

        DB::table('widgets')->insert(['title' => 'Defaulted']);

        $this->assertSame(
            ["(now() AT TIME ZONE 'UTC'::text)", true],
            [
                DB::scalar("select column_default from information_schema.columns where table_schema = current_schema() and table_name = 'widgets' and column_name = 'created_at'"),
                DB::scalar('select abs(extract(epoch from created_at - now())) < 1 from gadgets'),
            ],
        );
    }

    #[Test]
    #[TestWith(['install', 'alter table gadgets alter column delivered_at type timestamp(0) without time zone', 'attribute delivered_at says inTimeZone(), which needs gadgets.delivered_at to be a timestamp with time zone, not timestamp(0) without time zone'])]
    #[TestWith(['prepare', 'alter table gadgets alter column delivered_at type timestamp(0) without time zone', 'attribute delivered_at says inTimeZone(), which needs gadgets.delivered_at to be a timestamp with time zone, not timestamp(0) without time zone'])]
    #[TestWith(['install', 'alter table gadgets alter column deleted_at type text', 'attribute deleted_at says inTimeZone(), which needs gadgets.deleted_at to be a timestamp with time zone, not text'])]
    #[TestWith(['install', 'alter table gadgets drop column updated_at', 'gadgets has no column updated_at'])]
    #[TestWith(['prepare', 'alter table gadgets drop column updated_at', 'gadgets has no column updated_at'])]
    #[TestWith(['prepare', 'drop table gadgets cascade', 'the current table gadgets does not exist'])]
    public function it_refuses_while_it_lists_the_statements_a_zoned_attribute_whose_column_is_missing_or_not_a_timestamp_with_time_zone(string $action, string $change, string $message): void
    {
        DB::unprepared($change);

        $this->assertThrows(fn () => resolve(Installer::class)->{$action}(new WidgetCompatibilityMap), InvalidCompatibilityMap::class, "Compatibility map 'widgets' is declared wrongly: {$message}");

        $this->assertSame(
            [null, 0, 0],
            [DB::scalar("select to_regclass('widgets')"), $this->objects('pg_indexes', 'indexname', 'compatibility__widgets__'), $this->objects('pg_statistic_ext', 'stxname', 'compatibility__widgets__')],
        );
    }

    #[Test]
    public function it_converts_a_zoned_attribute_whose_column_is_a_domain_over_a_timestamp_with_time_zone(): void
    {
        DB::statement('drop domain if exists delivery_moment cascade');
        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('drop domain if exists delivery_moment cascade');
        });
        DB::statement('create domain delivery_moment as timestamp(0) with time zone');
        DB::statement('alter table gadgets alter column delivered_at type delivery_moment');
        resolve(Installer::class)->install(new WidgetCompatibilityMap);

        DB::table('widgets')->insert(['title' => 'Delivered', 'delivered_at' => '2026-10-28 12:00:00']);

        $this->assertSame('2026-10-28 12:00:00', DB::table('widgets')->value('delivered_at'));
    }

    #[Test]
    public function it_installs_one_statistics_expression_for_two_zoned_attributes_that_read_one_column(): void
    {
        resolve(Installer::class)->install($this->compatibilityMap(['attributes' => [
            ...(new WidgetCompatibilityMap)->attributes(),
            Attribute::mapped('created_on')->asType('timestamp(0) without time zone')->storedAs('gadget', 'created_at')->inTimeZone('UTC')->readOnlyBecause('A widget shows the time it was created under a second name.'),
        ]]));

        $this->assertSame(4, (int) DB::scalar("select count(*) from pg_stats_ext_exprs where statistics_schemaname = current_schema() and statistics_name = 'compatibility__widgets__statistics'"));
    }

    #[Test]
    #[TestWith(['install'])]
    #[TestWith(['recompile'])]
    #[TestWith(['prepare'])]
    public function it_refuses_while_it_lists_the_statements_a_time_zone_the_catalog_does_not_hold(string $action): void
    {
        if ($action === 'recompile') {
            resolve(Installer::class)->install(new WidgetCompatibilityMap);
        }

        $this->assertThrows(
            fn () => resolve(Installer::class)->{$action}(new WidgetCompatibilityMap(['delivered_at' => $this->deliveredIn('America/Chicgo')])),
            InvalidCompatibilityMap::class,
            "Compatibility map 'widgets' is declared wrongly: attribute delivered_at says inTimeZone('America/Chicgo'), which neither pg_timezone_names nor pg_timezone_abbrevs holds.",
        );

        $this->assertSame(
            $action === 'recompile' ? [true, 1, 1] : [false, 0, 0],
            [DB::scalar("select to_regclass('widgets') is not null"), $this->objects('pg_indexes', 'indexname', 'compatibility__widgets__'), $this->objects('pg_statistic_ext', 'stxname', 'compatibility__widgets__')],
        );

        if ($action === 'recompile') {
            DB::table('widgets')->insert(['title' => 'After a refused recompile', 'delivered_at' => '2026-10-28 12:00:00']);

            $this->assertSame('2026-10-28 12:00:00', DB::scalar("select to_char(delivered_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') from gadgets"));
        }
    }

    #[Test]
    #[TestWith(['america/chicago'])]
    #[TestWith(['cdt'])]
    public function it_converts_through_a_time_zone_name_or_abbreviation_the_catalog_holds_in_any_letter_case(string $zone): void
    {
        resolve(Installer::class)->install(new WidgetCompatibilityMap(['delivered_at' => $this->deliveredIn($zone)]));

        DB::table('widgets')->insert(['title' => 'Delivered', 'delivered_at' => '2026-10-28 12:00:00']);

        $this->assertSame('2026-10-28 17:00:00', DB::scalar("select to_char(delivered_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') from gadgets"));
    }

    #[Test]
    #[TestWith(['on', 'archived'])]
    #[TestWith(['whereNotNull', 'name'])]
    public function it_refuses_to_prepare_a_map_whose_indexed_or_filtered_column_is_missing_before_it_builds_anything(string $declaration, string $column): void
    {
        $map = $declaration === 'on' ? new WidgetCompatibilityMap : $this->compatibilityMap([
            'primaryCurrentModel' => PrimaryCurrentModel::for(Gadget::class)->aliasedAs('gadget')->fillWhenCreatedFromPrevious(['id' => 'COALESCE(NEW.gadget_id, gen_random_uuid())'])
                ->indexedBy(PlannerIndex::named('titled_newest')->on('created_at', descending: true)->whereNotNull('title')),
        ]);
        DB::statement("alter table gadgets drop column {$column}");

        $this->assertThrows(fn () => resolve(Installer::class)->prepare($map), InvalidCompatibilityMap::class, "Compatibility map 'widgets' is declared wrongly: gadgets has no column {$column}.");

        $this->assertSame([0, 0], [$this->objects('pg_indexes', 'indexname', 'compatibility__widgets__'), $this->objects('pg_statistic_ext', 'stxname', 'compatibility__widgets__')]);
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
        'compatibility__widgets__designate_place' => 'AFTER INSERT OR UPDATE ON gadget_places EXECUTE FUNCTION compatibility__widgets__designate_place()',
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
    public function it_refuses_a_map_whose_current_table_is_outside_the_schema_it_installs_into(): void
    {
        $searchPath = (string) DB::scalar('show search_path');
        DB::statement('drop schema if exists "Tenant" cascade');
        DB::statement('create schema "Tenant"');

        try {
            DB::statement('set search_path to "Tenant"');
            Tables::create();
            DB::statement('drop table gadget_sites, gadget_places, gadgets cascade');
            DB::statement('set search_path to "Tenant", public');

            $this->assertThrows(
                fn () => resolve(Installer::class)->install(new WidgetCompatibilityMap),
                InvalidCompatibilityMap::class,
                "Compatibility map 'widgets' is declared wrongly: the current table gadgets does not exist",
            );
            $this->assertSame([null, 0], [DB::scalar("select to_regclass('\"Tenant\".widgets')"), $this->objects('pg_trigger', 'tgname', 'compatibility__widgets__')]);
        } finally {
            DB::statement('set search_path to '.$searchPath);
            DB::statement('drop schema if exists "Tenant" cascade');
        }
    }

    #[Test]
    public function it_writes_a_column_whose_type_and_default_come_from_a_later_schema_on_the_search_path(): void
    {
        $searchPath = (string) DB::scalar('show search_path');
        DB::statement('drop schema if exists shared_types cascade');
        DB::statement('create schema shared_types');
        DB::statement("create type shared_types.phase as enum ('draft', 'active', 'retired')");
        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('drop schema if exists shared_types cascade');
        });

        try {
            DB::statement("set search_path to {$searchPath}, shared_types");
            DB::statement("alter table gadgets add column phase phase not null default 'draft'");
            resolve(Installer::class)->install($this->compatibilityMap(['attributes' => [
                ...(new WidgetCompatibilityMap)->attributes(),
                Attribute::mapped('phase')->asType('text')->storedAs('gadget', 'phase'),
            ]]));

            $this->assertSame("{$searchPath}, shared_types", DB::scalar('show search_path'));
        } finally {
            DB::statement('set search_path to '.$searchPath);
        }

        $retired = DB::table('widgets')->insertGetId(['title' => 'Retired', 'phase' => 'active']);
        DB::table('widgets')->insert(['title' => 'Defaulted', 'phase' => null]);
        DB::table('widgets')->where('id', $retired)->update(['phase' => 'retired']);

        $this->assertSame(['Defaulted' => 'draft', 'Retired' => 'retired'], DB::table('gadgets')->orderBy('name')->pluck('phase', 'name')->all());
    }

    #[Test]
    #[TestWith(["create domain widget_phase as text default 'Draft'", 'widget_phase', 'Draft'])]
    #[TestWith(["create domain widget_phase as text not null default 'Draft'", 'widget_phase', 'Draft'])]
    #[TestWith(["create domain widget_phase as text default 'Draft'; create domain widget_stage as widget_phase", 'widget_stage', 'Draft'])]
    #[TestWith(["create domain widget_phase as text default 'Draft'", "widget_phase default 'Active'", 'Active'])]
    #[TestWith(["create domain widget_phase as text default 'Draft'; create domain widget_stage as widget_phase default 'Active'", 'widget_stage', 'Active'])]
    public function it_writes_the_default_a_current_column_takes_from_its_domain_when_an_insert_through_the_view_omits_its_attribute(string $domains, string $column, string $stored): void
    {
        DB::statement('drop domain if exists widget_stage, widget_phase cascade');
        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('drop domain if exists widget_stage, widget_phase cascade');
        });
        DB::unprepared($domains);
        DB::statement("alter table gadgets add column phase {$column}");
        resolve(Installer::class)->install($this->compatibilityMap(['attributes' => [
            ...(new WidgetCompatibilityMap)->attributes(),
            Attribute::mapped('phase')->asType('text')->storedAs('gadget', 'phase'),
        ]]));

        DB::table('widgets')->insert(['title' => 'Defaulted']);

        $this->assertSame([$stored], DB::table('gadgets')->pluck('phase')->all());
    }

    #[Test]
    public function it_refuses_a_current_column_whose_domain_is_not_null_without_a_default_that_an_insert_leaves_empty(): void
    {
        DB::statement('drop domain if exists gadget_code cascade');
        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('drop domain if exists gadget_code cascade');
        });
        DB::statement('create domain gadget_code as text not null');
        DB::statement('alter table gadgets add column code gadget_code');

        $this->assertThrows(
            fn () => resolve(Installer::class)->install(new WidgetCompatibilityMap),
            InvalidCompatibilityMap::class,
            "Compatibility map 'widgets' is declared wrongly: gadgets.code is NOT NULL without a default, and an insert through widgets leaves it empty; map an attribute to it or fill it with fillWhenCreatedFromPrevious()",
        );
        $this->assertNull(DB::scalar("select to_regclass('widgets')"));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    #[Test]
    #[TestWith(["create domain widget_label as text not null default 'Unlabelled'", 'dependent', 'gadget_sites', ['title' => 'Defaulted', 'latitude' => 1.5]])]
    #[TestWith(["create domain widget_label as text not null default 'Unlabelled'", 'primary', 'gadgets', ['title' => 'Defaulted', 'label' => null]])]
    #[TestWith(["create domain widget_label as text not null default 'Unlabelled'", 'writesTo', 'gadgets', ['title' => 'Defaulted']])]
    #[TestWith(["create domain widget_label_base as text not null default 'Unlabelled'; create domain widget_label as widget_label_base", 'dependent', 'gadget_sites', ['title' => 'Defaulted', 'latitude' => 1.5]])]
    public function it_writes_the_default_of_a_not_null_domain_to_a_current_column_that_an_insert_through_the_view_leaves_null(string $domains, string $target, string $table, array $values): void
    {
        $this->installWithLabel($domains, $target, $table);

        DB::table('widgets')->insert($values);

        $this->assertSame(['Unlabelled'], DB::table($table)->pluck('label')->all());
    }

    /**
     * @param  array<string, mixed>  $values
     */
    #[Test]
    #[TestWith(['dependent', 'gadget_sites', ['title' => 'Refused', 'latitude' => 1.5]])]
    #[TestWith(['primary', 'gadgets', ['title' => 'Refused', 'label' => null]])]
    public function it_refuses_a_null_to_a_current_column_whose_domain_is_not_null_without_a_default(string $target, string $table, array $values): void
    {
        $this->installWithLabel('create domain widget_label as text not null', $target, $table);

        $this->assertThrows(fn () => DB::table('widgets')->insert($values), QueryException::class, 'domain widget_label does not allow null values');

        $this->assertSame([0, 0], [DB::table('gadgets')->count(), DB::table('retained_widgets')->count()]);
    }

    #[Test]
    public function it_compiles_without_running_any_statement(): void
    {
        $compiler = $this->compiler();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $compiler->compile(new WidgetCompatibilityMap);
        $compiler->prepare(new WidgetCompatibilityMap);

        $this->assertNotEmpty($queries);
        $this->assertSame([], array_values(array_filter($queries, fn (string $sql) => preg_match('/^(?:select|with recursive) /', $sql) !== 1)));
        $this->assertSame(
            [false, 0, 0, 0, 0],
            [
                DB::scalar("select to_regclass('widgets') is not null"),
                $this->objects('pg_proc', 'proname', 'compatibility__widgets__'),
                $this->objects('pg_trigger', 'tgname', 'compatibility__widgets__'),
                $this->objects('pg_indexes', 'indexname', 'compatibility__widgets__'),
                $this->objects('pg_statistic_ext', 'stxname', 'compatibility__widgets__'),
            ],
        );
    }

    #[Test]
    public function it_compiles_the_same_sql_from_a_map_and_from_its_resolved_map(): void
    {
        $this->assertEquals($this->compiler()->compile(new WidgetCompatibilityMap), $this->compiler()->compile(ResolvedMap::of(new WidgetCompatibilityMap)));
    }

    #[Test]
    public function it_compiles_the_retained_table_from_the_name_it_has_before_the_rename(): void
    {
        DB::statement('alter table retained_widgets rename to legacy_widgets');
        $beforeRename = (new Compiler(DB::connection(), ['retained_widgets' => 'legacy_widgets']))->compile(new WidgetCompatibilityMap);
        DB::statement('alter table legacy_widgets rename to retained_widgets');

        $this->assertEquals($this->compiler()->compile(new WidgetCompatibilityMap), $beforeRename);
    }

    #[Test]
    public function it_names_the_retained_table_by_the_name_it_was_read_from_when_that_table_does_not_exist(): void
    {
        $this->assertThrows(
            fn () => (new Compiler(DB::connection(), ['retained_widgets' => 'legacy_widgets']))->compile(new WidgetCompatibilityMap),
            InvalidCompatibilityMap::class,
            "Compatibility map 'widgets' is declared wrongly: the retained table legacy_widgets does not exist",
        );
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
    #[TestWith(['alter table gadgets add column shout text generated always as (upper(name)) stored', 'gadget', 'shout', 'text', 'gadgets.shout'])]
    #[TestWith(['alter table gadgets add column serial_number integer generated always as identity', 'gadget', 'serial_number', 'integer', 'gadgets.serial_number'])]
    #[TestWith(['alter table retained_widgets add column code text generated always as (upper(status)) stored', 'widget', 'code', 'text', 'retained_widgets.code'])]
    public function it_refuses_a_writable_attribute_whose_column_the_database_always_generates(string $column, string $alias, string $attribute, string $type, string $target): void
    {
        DB::statement($column);
        $attributes = [...(new WidgetCompatibilityMap)->attributes(), $alias === 'widget' ? Attribute::retained($attribute) : Attribute::mapped($attribute)->asType($type)->storedAs($alias, $attribute)];

        $this->assertThrows(
            fn () => resolve(Installer::class)->install($this->compatibilityMap(['attributes' => $attributes])),
            InvalidCompatibilityMap::class,
            "Compatibility map 'widgets' is declared wrongly: attribute {$attribute} writes {$target}, which the database always generates; declare it read-only",
        );

        $this->assertNull(DB::scalar("select to_regclass('widgets')"));
    }

    #[Test]
    public function it_compiles_and_writes_a_writable_attribute_whose_column_is_a_default_identity(): void
    {
        DB::statement('alter table gadgets add column serial_number integer generated by default as identity');
        resolve(Installer::class)->install($this->compatibilityMap(['attributes' => [...(new WidgetCompatibilityMap)->attributes(), Attribute::mapped('serial_number')->asType('integer')->storedAs('gadget', 'serial_number')]]));

        $numbered = DB::table('widgets')->insertGetId(['title' => 'Numbered by the table']);
        $id = DB::table('widgets')->insertGetId(['title' => 'Numbered', 'serial_number' => 7]);
        DB::table('widgets')->where('id', $id)->update(['serial_number' => 8]);

        $this->assertSame([1, 8], [DB::table('widgets')->where('id', $numbered)->value('serial_number'), DB::table('widgets')->where('id', $id)->value('serial_number')]);
    }

    #[Test]
    #[TestWith(['by default', "nextval((pg_get_serial_sequence('public.retained_widgets'::text, 'id'::text))::regclass)"])]
    #[TestWith(['always', null])]
    public function it_lets_the_table_number_an_identity_retained_key_that_an_insert_through_the_view_omits(string $identity, null|string $default): void
    {
        DB::unprepared("alter table retained_widgets alter column id drop default; drop sequence retained_widgets_id_seq; alter table retained_widgets alter column id add generated {$identity} as identity");
        resolve(Installer::class)->install(new WidgetCompatibilityMap);

        $ids = [DB::table('widgets')->insertGetId(['title' => 'First']), DB::table('widgets')->insertGetId(['title' => 'Second'])];

        $this->assertSame([[1, 2], [1, 2]], [$ids, DB::table('retained_widgets')->orderBy('id')->pluck('id')->all()]);
        $this->assertSame($default, DB::scalar("select column_default from information_schema.columns where table_schema = current_schema() and table_name = 'widgets' and column_name = 'id'"));
    }

    #[Test]
    public function it_writes_the_default_of_a_not_null_current_column_that_an_insert_through_the_view_leaves_empty(): void
    {
        DB::statement('alter table gadgets add column weight_grams integer not null default 0');
        DB::statement("alter table gadget_sites add column label text default 'Unlabelled'");
        resolve(Installer::class)->install($this->compatibilityMap(['attributes' => [
            ...(new WidgetCompatibilityMap)->attributes(),
            Attribute::mapped('longitude')->asType('numeric(24,12)')->storedAs('site', 'longitude'),
            Attribute::mapped('label')->asType('text')->storedAs('site', 'label'),
            Attribute::mapped('weight')->asType('numeric')->readAs('gadget', 'gadget.weight_grams / 1000.0')->writesTo('gadget', 'weight_grams', 'round(NEW.weight * 1000)'),
        ]]));

        DB::table('widgets')->insert(['title' => 'Inserted', 'latitude' => 1.5]);
        $updated = DB::table('widgets')->insertGetId(['title' => 'Updated']);
        DB::table('widgets')->where('id', $updated)->update(['latitude' => 2.5]);

        $this->assertSame(
            [['1.500000000000', '0.000000000000', null], ['2.500000000000', '0.000000000000', null]],
            DB::table('gadget_sites')->orderBy('latitude')->get()->map(fn (object $site) => [$site->latitude, $site->longitude, $site->label])->all(),
        );
        $this->assertSame([0, 0], DB::table('gadgets')->pluck('weight_grams')->all());
    }

    #[Test]
    public function it_stores_a_read_only_mapped_attribute_on_an_insert_through_the_view_and_still_refuses_a_change_to_it(): void
    {
        DB::statement('alter table gadgets add column grade integer not null default 3');
        DB::statement('alter table gadgets add column serial_number integer generated always as identity');
        DB::statement('alter table gadget_places add column kind text not null');
        resolve(Installer::class)->install($this->compatibilityMap(['attributes' => [
            ...(new WidgetCompatibilityMap(['created_at' => Attribute::mapped('created_at')->asType('timestamp(0) without time zone')->storedAs('gadget', 'created_at')->inTimeZone('UTC')->readOnlyBecause('A widget keeps the time it was created.')]))->attributes(),
            Attribute::mapped('grade')->asType('integer')->storedAs('gadget', 'grade')->readOnlyBecause('Grading assigns it.'),
            Attribute::mapped('serial_number')->asType('integer')->storedAs('gadget', 'serial_number')->readOnlyBecause('The database numbers it.'),
            Attribute::mapped('longitude')->asType('numeric(24,12)')->storedAs('site', 'longitude')->readOnlyBecause('A survey sets it.'),
            Attribute::mapped('kind')->asType('text')->storedAs('place', 'kind')->readOnlyBecause('A place keeps its kind.'),
        ]]));

        $widget = Widget::query()->create(['title' => 'Stamped', 'grade' => 5, 'latitude' => 1.5, 'longitude' => 2.5, 'street' => '1 Main St', 'kind' => 'Root']);
        DB::table('widgets')->insert(['title' => 'Defaulted']);

        $this->assertSame(
            [[$widget->getAttributes()['created_at'], 5, 1], [null, 3, 2]],
            DB::table('gadgets')->orderBy('serial_number')->get([DB::raw("to_char(created_at, 'YYYY-MM-DD HH24:MI:SS') as created"), 'grade', 'serial_number'])->map(fn (object $gadget) => [$gadget->created, $gadget->grade, $gadget->serial_number])->all(),
        );
        $this->assertSame(['2.500000000000', 'Root'], [DB::table('gadget_sites')->value('longitude'), DB::table('gadget_places')->sole()->kind]);
        $this->assertWriteRefused(fn () => $widget->update(['grade' => 6]), 'refused a change to widgets.grade');
        $this->assertWriteRefused(fn () => $widget->update(['created_at' => '2020-01-01 00:00:00']), 'refused a change to widgets.created_at');
    }

    #[Test]
    public function it_stores_the_writable_attribute_when_a_read_only_attribute_is_stored_in_the_same_column(): void
    {
        resolve(Installer::class)->install($this->compatibilityMap(['attributes' => [
            ...(new WidgetCompatibilityMap)->attributes(),
            Attribute::mapped('title_copy')->asType('character varying(255)')->storedAs('gadget', 'name')->readOnlyBecause('A copy follows the title.'),
        ]]));

        $widget = Widget::query()->create(['title' => 'Lamp']);

        $this->assertSame(['Lamp', 'Lamp', 'Lamp'], [DB::table('gadgets')->value('name'), ...array_values($widget->fresh()?->only(['title', 'title_copy']) ?? [])]);
        $this->assertWriteRefused(fn () => $widget->update(['title_copy' => 'X']), 'refused a change to widgets.title_copy');
        Widget::query()->sole()->update(['title' => 'Desk']);

        $this->assertSame(['Desk', 'Desk'], array_values((array) DB::table('widgets')->sole(['title', 'title_copy'])));
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
    public function it_raises_a_serialization_failure_when_the_dependent_row_it_waited_on_stops_being_readable_and_another_becomes_readable(): void
    {
        resolve(Installer::class)->install($this->compatibilityMap(['unrepresentableStates' => []]));
        $id = $this->widget();
        $root = (string) DB::table('gadget_places')->value('id');
        $child = (string) DB::table('gadget_places')->insertGetId(['gadget_id' => DB::table('gadgets')->value('id'), 'parent_id' => $root, 'street' => '2 Child St']);

        DB::beginTransaction();
        DB::table('gadget_places')->where('id', $root)->update(['deleted_at' => now()]);
        DB::table('gadget_places')->where('id', $child)->update(['parent_id' => null]);
        $writer = $this->writeConcurrently("update widgets set street = 'Stale St' where id = {$id}");
        DB::commit();
        [$state, $error] = $this->failure($writer);

        $this->assertSame('40001', $state);
        $this->assertStringContainsString("ERROR:  Compatibility map 'widgets' found a concurrent change to widgets record {$id} since this statement's snapshot", $error);
        $this->assertSame(['1 Main St', '2 Child St'], [DB::table('gadget_places')->where('id', $root)->value('street'), DB::table('gadget_places')->where('id', $child)->value('street')]);

        DB::table('widgets')->where('id', $id)->update(['street' => 'Stale St']);

        $this->assertSame('Stale St', DB::table('gadget_places')->where('id', $child)->value('street'));
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

    #[Test]
    public function it_calls_no_function_of_the_installation_schema_that_takes_the_name_of_a_built_in_function(): void
    {
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        DB::statement('create table intruders (id uuid)');
        DB::statement('create trigger intruder before insert on intruders for each row execute function compatibility__widgets__insert()');

        foreach (['hashtextextended(text, integer) returns bigint', 'concat(text, uuid) returns text', 'pg_partition_root(oid) returns regclass'] as $overload) {
            DB::unprepared("create function {$overload} language plpgsql as \$overload\$ begin raise exception 'The installation schema''s {$overload} ran'; end \$overload\$");
        }

        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('drop table if exists intruders');
            DB::statement('drop function if exists hashtextextended(text, integer), concat(text, uuid), pg_partition_root(oid)');
        });

        $id = $this->widget();
        DB::table('widgets')->where('id', $id)->update(['title' => 'Renamed', 'street' => '2 Main St']);
        $gadget = DB::table('gadgets')->insertGetId(['name' => 'Directly']);
        DB::table('gadget_places')->insert(['gadget_id' => $gadget, 'street' => '3 Direct St']);
        DB::table('widgets')->where('id', $id)->delete();
        DB::table('gadget_places')->where('gadget_id', $gadget)->delete();
        DB::table('gadgets')->where('id', $gadget)->delete();

        $this->assertWriteRefused(fn () => DB::table('intruders')->insert(['id' => $gadget]), 'refused to run compatibility__widgets__insert on public.intruders');
        $this->assertSame([0, 0, 0], [DB::table('widgets')->count(), DB::table('retained_widgets')->count(), DB::table('gadgets')->count()]);
    }

    #[Test]
    public function it_inserts_a_mirrored_primary_row_at_concurrency_revision_one_and_raises_it_only_when_a_stored_value_other_than_updated_at_changes(): void
    {
        $this->refuseUnraisedRevisions('gadgets');
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        $id = $this->widget();
        $revisions = [DB::table('gadgets')->value('concurrency_revision')];

        foreach ([['price' => '12.504'], ['updated_at' => '2026-10-29 12:00:00'], ['deleted_at' => '2026-10-29 12:00:00'], ['title' => 'Renamed']] as $change) {
            DB::table('widgets')->where('id', $id)->update($change);
            $revisions[] = DB::table('gadgets')->value('concurrency_revision');
        }

        $this->assertSame([1, 1, 1, 2, 3], $revisions);
    }

    #[Test]
    public function it_inserts_a_mirrored_dependent_row_at_concurrency_revision_one_and_raises_it_only_when_a_stored_value_changes(): void
    {
        $this->refuseUnraisedRevisions('gadget_sites');
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        $id = DB::table('widgets')->insertGetId(['title' => 'Blue gadget', 'price' => '12.50', 'street' => '1 Main St']);
        $revisions = [];

        foreach (['47.5', '47.5000000000001', '48.25'] as $latitude) {
            DB::table('widgets')->where('id', $id)->update(['latitude' => $latitude]);
            $revisions[] = DB::table('gadget_sites')->value('concurrency_revision');
        }

        $this->widget();
        $revisions[] = DB::table('gadget_sites')->where('latitude', 47.5)->value('concurrency_revision');

        $this->assertSame([1, 1, 2, 1], $revisions);
    }

    #[Test]
    public function it_inserts_a_mirrored_retained_row_at_concurrency_revision_one_from_either_side_and_raises_it_when_a_retained_value_changes(): void
    {
        $this->refuseUnraisedRevisions('retained_widgets');
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        $id = $this->widget();
        DB::table('widgets')->where('id', $id)->update(['sku' => 'SKU-1']);
        $gadget = DB::table('gadgets')->insertGetId(['name' => 'Created through its own model']);

        $this->assertSame(
            [2, 1],
            [DB::table('retained_widgets')->where('id', $id)->value('concurrency_revision'), DB::table('retained_widgets')->where('gadget_id', $gadget)->value('concurrency_revision')],
        );
    }

    #[Test]
    #[TestWith(['gadgets', 'mapped'])]
    #[TestWith(['retained_widgets', 'retained'])]
    public function it_sets_and_raises_a_concurrency_revision_the_map_exposes_whatever_value_the_write_gives_it(string $table, string $attribute): void
    {
        $this->refuseUnraisedRevisions($table);
        resolve(Installer::class)->install($this->compatibilityMap(['attributes' => [
            ...(new WidgetCompatibilityMap)->attributes(),
            $attribute === 'mapped' ? Attribute::mapped('concurrency_revision')->asType('bigint')->storedAs('gadget', 'concurrency_revision') : Attribute::retained('concurrency_revision'),
        ]]));
        $id = DB::table('widgets')->insertGetId(['title' => 'Blue gadget', 'price' => '12.50', 'street' => '1 Main St', 'latitude' => 47.5, 'concurrency_revision' => 7]);
        $revisions = [DB::table('widgets')->where('id', $id)->value('concurrency_revision')];

        foreach ([['sku' => 'SKU-1', 'title' => 'Renamed', 'concurrency_revision' => 9], ['concurrency_revision' => 5]] as $change) {
            DB::table('widgets')->where('id', $id)->update($change);
            $revisions[] = DB::table('widgets')->where('id', $id)->value('concurrency_revision');
        }

        $this->assertSame([1, 2, 2], $revisions);
        $this->assertSame(2, DB::table($table)->value('concurrency_revision'));
    }

    #[Test]
    #[TestWith(['an insert through the view'])]
    #[TestWith(['an update through the view'])]
    #[TestWith(['an insert through the current model'])]
    public function it_raises_the_concurrency_revision_of_the_retained_row_when_it_designates_a_dependent_row(string $write): void
    {
        $this->refuseUnraisedRevisions('retained_widgets');
        resolve(Installer::class)->install($this->workbenchMap('designated'));
        $row = ['title' => 'Blue gadget', 'price' => '12.50', 'latitude' => 47.5];

        if ($write === 'an insert through the view') {
            DB::table('widgets')->insert([...$row, 'street' => '1 Main St']);
        } elseif ($write === 'an update through the view') {
            DB::table('widgets')->where('id', DB::table('widgets')->insertGetId($row))->update(['street' => '1 Main St']);
        } else {
            DB::table('widgets')->insert($row);
            DB::table('gadget_places')->insert(['gadget_id' => DB::table('retained_widgets')->value('gadget_id'), 'street' => '1 Main St']);
        }

        $retained = DB::table('retained_widgets')->sole();

        $this->assertSame([DB::table('gadget_places')->value('id'), 2], [$retained->place_id, $retained->concurrency_revision]);
    }

    #[Test]
    public function it_raises_the_concurrency_revision_when_a_stored_value_changes_its_jsonb_form_although_sql_calls_the_two_values_equal(): void
    {
        DB::statement('alter table gadgets add column lead_time interval');
        $this->refuseUnraisedRevisions('gadgets');
        resolve(Installer::class)->install($this->compatibilityMap(['attributes' => [
            ...(new WidgetCompatibilityMap)->attributes(),
            Attribute::mapped('lead_time')->asType('text')->storedAs('gadget', 'lead_time'),
        ]]));
        $id = DB::table('widgets')->insertGetId(['title' => 'Blue gadget', 'lead_time' => '1 mon']);

        DB::table('widgets')->where('id', $id)->update(['lead_time' => '30 days']);

        $this->assertSame(['30 days', 2], array_values((array) DB::table('gadgets')->sole([DB::raw('lead_time::text as lead_time'), 'concurrency_revision'])));
    }

    #[Test]
    public function it_evaluates_a_volatile_mapping_once_per_mirrored_update_and_compares_the_value_it_stores(): void
    {
        DB::statement('create sequence gadget_serials');
        DB::statement('alter table gadgets add column serial bigint');
        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('drop sequence if exists gadget_serials cascade');
        });
        $this->refuseUnraisedRevisions('gadgets');
        resolve(Installer::class)->install($this->compatibilityMap(['attributes' => [
            ...(new WidgetCompatibilityMap)->attributes(),
            Attribute::mapped('serial')->asType('bigint')->readAs('gadget', 'gadget.serial')->writesTo('gadget', 'serial', "nextval('gadget_serials')"),
        ]]));
        $id = DB::table('widgets')->insertGetId(['title' => 'Blue gadget', 'serial' => 0]);
        $observed = [];

        foreach ([10, 20] as $serial) {
            DB::table('widgets')->where('id', $id)->update(['serial' => $serial]);
            $gadget = DB::table('gadgets')->sole(['serial', 'concurrency_revision']);
            $observed[] = [$gadget->serial, DB::scalar('select last_value from gadget_serials'), $gadget->concurrency_revision];
        }

        $this->assertSame([[2, 2, 2], [3, 3, 3]], $observed);
    }

    #[Test]
    public function it_counts_the_concurrency_revision_it_owns_as_filled_on_every_insert(): void
    {
        foreach (['retained_widgets', 'gadgets', 'gadget_places', 'gadget_sites'] as $table) {
            DB::statement("alter table {$table} add column concurrency_revision bigint not null");
        }

        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        $this->widget();

        $this->assertSame(
            [[1], [1], [1], [1]],
            array_map(fn (string $table) => DB::table($table)->pluck('concurrency_revision')->all(), ['retained_widgets', 'gadgets', 'gadget_places', 'gadget_sites']),
        );
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

    private function refuseUnraisedRevisions(string $table): void
    {
        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->bigInteger('concurrency_revision')->default(0);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuse_unraised_concurrency_revision() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                ignored text[];
                changed text;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.concurrency_revision IS DISTINCT FROM 1 THEN
                        RAISE EXCEPTION '[%] inserts a row at concurrency revision %, not 1.', TG_TABLE_NAME, NEW.concurrency_revision
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;

                IF NEW.concurrency_revision = OLD.concurrency_revision + 1 THEN
                    RETURN NEW;
                END IF;

                IF NEW.concurrency_revision IS DISTINCT FROM OLD.concurrency_revision THEN
                    RAISE EXCEPTION '[%] changes the concurrency revision from % to %, not by 1.', TG_TABLE_NAME, OLD.concurrency_revision, NEW.concurrency_revision
                        USING ERRCODE = '23514';
                END IF;

                ignored := COALESCE(TG_ARGV, '{}') || '{concurrency_revision,updated_at,deleted_at}' || ARRAY(
                    SELECT key FROM jsonb_object_keys(to_jsonb(NEW)) AS key WHERE key LIKE '%\_concurrency\_revision'
                ) || ARRAY(
                    SELECT attname::text FROM pg_attribute
                    WHERE attrelid = TG_RELID AND attnum > 0 AND NOT attisdropped AND attgenerated <> ''
                );

                SELECT string_agg(n.key, '], [' ORDER BY n.key) INTO changed
                FROM jsonb_each(to_jsonb(NEW) - ignored) AS n
                WHERE n.value IS DISTINCT FROM (to_jsonb(OLD) - ignored) -> n.key;

                IF changed IS NOT NULL THEN
                    RAISE EXCEPTION '[%] changes [%] without raising the concurrency revision.', TG_TABLE_NAME, changed
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END $$;
        SQL);

        DB::unprepared("CREATE TRIGGER refuse_unraised_revision BEFORE INSERT OR UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION refuse_unraised_concurrency_revision()");
        $this->beforeApplicationDestroyed(function (): void {
            DB::unprepared('DROP FUNCTION IF EXISTS refuse_unraised_concurrency_revision() CASCADE');
        });
    }

    private function compiler(): Compiler
    {
        return new Compiler(DB::connection());
    }

    private function deliveredIn(string $zone): Attribute
    {
        return Attribute::mapped('delivered_at')->asType('timestamp(0) without time zone')->storedAs('gadget', 'delivered_at')->inTimeZone($zone);
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

    private function installWithLabel(string $domains, string $target, string $table): void
    {
        DB::statement('drop domain if exists widget_label, widget_label_base cascade');
        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('drop domain if exists widget_label, widget_label_base cascade');
        });
        DB::unprepared($domains);
        DB::statement("alter table {$table} add column label widget_label");
        resolve(Installer::class)->install($this->compatibilityMap(['attributes' => [
            ...(new WidgetCompatibilityMap)->attributes(),
            match ($target) {
                'dependent' => Attribute::mapped('label')->asType('text')->storedAs('site', 'label'),
                'primary' => Attribute::mapped('label')->asType('text')->storedAs('gadget', 'label'),
                default => Attribute::mapped('label')->asType('text')->readAs('gadget', 'gadget.label')->writesTo('gadget', 'label', 'upper(NEW.label)'),
            },
        ]]));
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
