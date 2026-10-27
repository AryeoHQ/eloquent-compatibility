<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Support\Database\Eloquent\Compatibility\CompatibilityMaps;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\Attribute;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Tests\Fixtures\Support\Workbench\AccountCompatibilityMap;
use Tests\Fixtures\Support\Workbench\ItemCompatibilityMap;
use Tests\Fixtures\Support\Workbench\ProfileCompatibilityMap;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(CompileSql::class)]
#[CoversClass(Installer::class)]
final class CompileSqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Tables::create();
        Tables::createItems();

        foreach ([new WidgetCompatibilityMap, new AccountCompatibilityMap, new ProfileCompatibilityMap, new ItemCompatibilityMap] as $map) {
            resolve(CompatibilityMaps::class)->register($map);
        }

        DB::unprepared('GRANT SELECT ON retained_widgets TO PUBLIC');
        DB::unprepared('GRANT UPDATE (sku, status, place_id) ON retained_widgets TO PUBLIC');
        DB::unprepared('GRANT INSERT ON retained_widgets TO pg_monitor WITH GRANT OPTION');
    }

    /**
     * @param  class-string<CompatibilityMap>  $map
     */
    #[Test]
    #[TestWith([WidgetCompatibilityMap::class, 'install'])]
    #[TestWith([WidgetCompatibilityMap::class, 'recompile'])]
    #[TestWith([WidgetCompatibilityMap::class, 'uninstall'])]
    #[TestWith([AccountCompatibilityMap::class, 'install'])]
    #[TestWith([AccountCompatibilityMap::class, 'recompile'])]
    #[TestWith([AccountCompatibilityMap::class, 'uninstall'])]
    #[TestWith([ProfileCompatibilityMap::class, 'install'])]
    #[TestWith([ProfileCompatibilityMap::class, 'recompile'])]
    #[TestWith([ProfileCompatibilityMap::class, 'uninstall'])]
    #[TestWith([ItemCompatibilityMap::class, 'install'])]
    #[TestWith([ItemCompatibilityMap::class, 'recompile'])]
    #[TestWith([ItemCompatibilityMap::class, 'uninstall'])]
    public function it_prints_exactly_the_statements_the_installer_then_runs(string $map, string $for): void
    {
        $compatibilityMap = new $map;

        if ($for !== 'install') {
            resolve(Installer::class)->install($compatibilityMap);
        }

        $printed = $this->statements($this->compile(['map' => $compatibilityMap->name(), '--for' => $for]));
        $executed = $this->executed(fn () => resolve(Installer::class)->{$for}($compatibilityMap));

        $this->assertNotEmpty($executed);
        $this->assertSame($executed, $printed);
    }

    #[Test]
    #[TestWith(['install'])]
    #[TestWith(['recompile'])]
    #[TestWith(['uninstall'])]
    public function it_prints_only_the_statements_of_the_current_schema_ahead_of_a_later_schema_that_has_the_compatibility_map(string $for): void
    {
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        $searchPath = (string) DB::scalar('show search_path');
        DB::statement('create schema "Tenant"');

        try {
            DB::statement('set search_path to "Tenant", '.$searchPath);
            Tables::create();

            if ($for !== 'install') {
                resolve(Installer::class)->install(new WidgetCompatibilityMap);
            }

            $printed = $this->statements($this->compile(['--for' => $for]));
            $executed = $this->executed(fn () => resolve(Installer::class)->{$for}(new WidgetCompatibilityMap));
        } finally {
            DB::statement('set search_path to '.$searchPath);
        }

        $this->assertSame($executed, $printed);
        $this->assertSame(6, (int) DB::scalar("select count(*) from pg_trigger join pg_class on pg_class.oid = tgrelid where tgname like 'compatibility\\_\\_widgets\\_\\_%' and pg_class.relnamespace = 'public'::regnamespace"));
        $this->assertSame('widgets', DB::scalar("select to_regclass('public.widgets')::text"));
    }

    #[Test]
    public function it_prints_the_drop_default_of_a_default_the_compatibility_map_no_longer_declares(): void
    {
        resolve(Installer::class)->install(new WidgetCompatibilityMap(['sku' => Attribute::retained('sku')->defaultsTo("'SKU-0'")]));

        $this->assertContains('ALTER VIEW "widgets" ALTER COLUMN "sku" DROP DEFAULT', $this->statements($this->compile(['--for' => 'recompile'])));
    }

    #[Test]
    public function it_prints_the_grants_install_derives_from_the_retained_table(): void
    {
        $printed = $this->statements($this->compile(['--for' => 'install']));

        $this->assertSame([
            'GRANT INSERT ON "widgets" TO pg_monitor WITH GRANT OPTION',
            'GRANT SELECT ON "widgets" TO PUBLIC',
            'GRANT UPDATE (sku) ON "widgets" TO PUBLIC',
            'GRANT UPDATE (status) ON "widgets" TO PUBLIC',
        ], $this->sorted(array_values(array_filter($printed, fn (string $statement) => str_starts_with($statement, 'GRANT ')))));
    }

    #[Test]
    public function it_compiles_a_compatibility_map_before_its_retained_table_is_renamed(): void
    {
        DB::unprepared('ALTER TABLE retained_widgets RENAME TO widgets');

        $output = $this->compile(['--retained-table-is-currently' => 'widgets']);

        DB::unprepared('ALTER TABLE widgets RENAME TO retained_widgets');
        $executed = $this->executed(fn () => resolve(Installer::class)->install(new WidgetCompatibilityMap));

        $this->assertStringContainsString('-- The retained table retained_widgets was read from widgets, its name before the rename', $output);
        $this->assertSame($executed, $this->statements($output));
    }

    #[Test]
    public function it_writes_the_statements_to_the_output_file(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'compatibility');

        try {
            $this->artisan('compatibility:sql', ['map' => 'widgets', '--output' => $file])
                ->expectsOutputToContain("to {$file}.")
                ->assertSuccessful();

            $this->assertSame($this->statements($this->compile()), $this->statements((string) file_get_contents($file)));
        } finally {
            File::delete($file);
        }
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([1])]
    public function it_exits_non_zero_when_the_output_file_is_not_written_in_full(false|int $written): void
    {
        $this->app->instance(Filesystem::class, new class($written) extends Filesystem
        {
            public function __construct(private readonly false|int $written) {}

            public function put($path, $contents, $lock = false): false|int
            {
                return $this->written;
            }
        });

        $this->artisan('compatibility:sql', ['map' => 'widgets', '--output' => '/unwritable/widgets.sql'])
            ->expectsOutputToContain('Could not write the statements to /unwritable/widgets.sql.')
            ->assertFailed();
    }

    #[Test]
    public function it_heads_the_statements_with_the_map_the_database_the_schema_the_search_path_the_version_the_time_and_the_role(): void
    {
        [$header] = explode("\n\n", $this->compile(), 2);
        $database = DB::connection()->getDatabaseName();
        $searchPath = (string) DB::scalar("select current_setting('search_path')");
        $version = (string) DB::scalar("select current_setting('server_version')");
        $role = (string) DB::scalar('select current_user');

        $this->assertStringContainsString('compatibility map widgets', $header);
        $this->assertStringContainsString("Read from database {$database}, schema public (search path {$searchPath}), on PostgreSQL {$version}", $header);
        $this->assertMatchesRegularExpression('/^-- Compiled at \d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/m', $header);
        $this->assertStringContainsString("The grants are derived from the grants on retained_widgets and the map's attribute columns, because the view widgets does not exist yet, and they omit the grants to {$role}, the role this command ran as, as install() omits the role it runs as", $header);
    }

    /**
     * @param  array<string, string>  $options
     */
    #[Test]
    #[TestWith(['missing', [], "Compatibility map 'missing' is declared wrongly: no compatibility map with this name is registered."])]
    #[TestWith(['widgets', ['--retained-table-is-currently' => 'listings'], "Compatibility map 'widgets' is declared wrongly: the retained table listings does not exist."])]
    #[TestWith(['widgets', ['--for' => 'replace'], '--for must be install, recompile, uninstall, not replace.'])]
    public function it_exits_non_zero_with_the_reason_a_compatibility_map_cannot_compile(string $map, array $options, string $message): void
    {
        $this->artisan('compatibility:sql', ['map' => $map, ...$options])
            ->expectsOutputToContain($message)
            ->assertFailed();
    }

    #[Test]
    #[TestWith(['install', 'alter table retained_widgets enable row level security', 'retained_widgets has row-level security enabled'])]
    #[TestWith(['install', 'create view widget_skus as select sku from retained_widgets', 'install() cannot serve retained_widgets through the compatibility view while other views read it directly: widget_skus'])]
    #[TestWith(['recompile', 'alter table gadgets enable row level security', 'gadgets has row-level security enabled'])]
    #[TestWith(['recompile', 'create view widget_titles as select title from widgets', 'recompile() cannot replace the compatibility view widgets while other objects depend on it: widget_titles'])]
    public function it_refuses_to_print_the_statements_of_a_compatibility_map_the_installer_refuses(string $for, string $statement, string $message): void
    {
        if ($for === 'recompile') {
            resolve(Installer::class)->install(new WidgetCompatibilityMap);
        }

        DB::statement($statement);

        $this->artisan('compatibility:sql', ['map' => 'widgets', '--for' => $for])
            ->expectsOutputToContain($message)
            ->assertFailed();
        $this->assertThrows(fn () => resolve(Installer::class)->{$for}(new WidgetCompatibilityMap), InvalidCompatibilityMap::class, $message);
    }

    #[Test]
    #[TestWith(['alter table widgets enable row level security', 'widgets has row-level security enabled'])]
    #[TestWith(['create view widget_skus as select sku from widgets', 'install() cannot serve widgets through the compatibility view while other views read it directly: widget_skus'])]
    public function it_refuses_an_install_before_the_rename_from_the_name_the_retained_table_has_then(string $statement, string $message): void
    {
        DB::unprepared('ALTER TABLE retained_widgets RENAME TO widgets');
        DB::statement($statement);

        $this->artisan('compatibility:sql', ['map' => 'widgets', '--retained-table-is-currently' => 'widgets'])
            ->expectsOutputToContain($message)
            ->assertFailed();
    }

    #[Test]
    public function it_prints_the_grants_a_recompile_restores_on_the_view_it_creates_anew(): void
    {
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        DB::unprepared('GRANT DELETE ON widgets TO pg_monitor');

        $printed = $this->statements($this->compile(['--for' => 'recompile']));
        $created = array_search('CREATE OR REPLACE VIEW', array_map(fn (string $statement) => substr($statement, 0, 22), $printed), true);
        $granted = array_search('GRANT DELETE ON "widgets" TO pg_monitor', $printed, true);

        $this->assertContains('DROP VIEW widgets', $printed);
        $this->assertIsInt($created);
        $this->assertIsInt($granted);
        $this->assertGreaterThan($created, $granted);
    }

    #[Test]
    #[TestWith(['install', 'in schema public'])]
    #[TestWith(['install', ''])]
    #[TestWith(['recompile', 'in schema public'])]
    #[TestWith(['recompile', ''])]
    public function it_prints_the_revokes_of_the_grants_the_default_privileges_give_the_view(string $for, string $scope): void
    {
        if ($for === 'recompile') {
            resolve(Installer::class)->install(new WidgetCompatibilityMap);
        }

        DB::statement("alter default privileges {$scope} grant select on tables to public");
        DB::statement("alter default privileges {$scope} grant delete on tables to pg_monitor");

        try {
            $printed = $this->statements($this->compile(['--for' => $for]));
            $executed = $this->executed(fn () => resolve(Installer::class)->{$for}(new WidgetCompatibilityMap));
        } finally {
            DB::statement("alter default privileges {$scope} revoke select on tables from public");
            DB::statement("alter default privileges {$scope} revoke delete on tables from pg_monitor");
        }

        $revokes = array_keys(array_filter($printed, fn (string $statement) => str_starts_with($statement, 'REVOKE ALL ON "widgets" ')));
        $grants = array_keys(array_filter($printed, fn (string $statement) => str_starts_with($statement, 'GRANT ')));
        $created = array_search('CREATE OR REPLACE VIEW', array_map(fn (string $statement) => substr($statement, 0, 22), $printed), true);

        $this->assertSame($executed, $printed);
        $this->assertSame(['REVOKE ALL ON "widgets" FROM PUBLIC', 'REVOKE ALL ON "widgets" FROM pg_monitor'], $this->sorted(array_map(fn (int $index) => $printed[$index], $revokes)));
        $this->assertIsInt($created);
        $this->assertGreaterThan($created, min($revokes));
        $this->assertGreaterThan(max($revokes), min($grants));
        $this->assertFalse(DB::scalar("select has_table_privilege('pg_monitor', 'widgets', 'DELETE')"));
    }

    #[Test]
    public function it_compiles_an_identity_retained_key_before_the_rename_with_the_default_it_takes_after_it(): void
    {
        DB::unprepared('alter table retained_widgets alter column id drop default; drop sequence retained_widgets_id_seq; alter table retained_widgets alter column id add generated by default as identity');
        DB::unprepared('ALTER TABLE retained_widgets RENAME TO widgets');

        $output = $this->compile(['--retained-table-is-currently' => 'widgets']);

        DB::unprepared('ALTER TABLE widgets RENAME TO retained_widgets');

        foreach ($this->statements($output) as $statement) {
            DB::unprepared($statement);
        }

        $ids = [DB::table('widgets')->insertGetId(['title' => 'First']), DB::table('widgets')->insertGetId(['title' => 'Second'])];

        $this->assertSame([[1, 2], [1, 2]], [$ids, DB::table('retained_widgets')->orderBy('id')->pluck('id')->all()]);
        $this->assertSame("nextval((pg_get_serial_sequence('public.retained_widgets'::text, 'id'::text))::regclass)", DB::scalar("select column_default from information_schema.columns where table_schema = current_schema() and table_name = 'widgets' and column_name = 'id'"));
    }

    /**
     * @param  array<string, string>  $options
     */
    #[Test]
    #[TestWith([[]])]
    #[TestWith([['--retained-table-is-currently' => 'widgets']])]
    public function it_refuses_to_print_an_install_for_a_compatibility_map_already_installed(array $options): void
    {
        resolve(Installer::class)->install(new WidgetCompatibilityMap);

        $this->artisan('compatibility:sql', ['map' => 'widgets', ...$options])
            ->expectsOutputToContain("Compatibility map 'widgets' is declared wrongly: a relation named widgets already exists")
            ->assertFailed();
    }

    #[Test]
    #[TestWith(['install'])]
    #[TestWith(['recompile'])]
    #[TestWith(['uninstall'])]
    public function it_runs_only_catalog_selects(string $for): void
    {
        if ($for !== 'install') {
            resolve(Installer::class)->install(new WidgetCompatibilityMap);
        }

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->compile(['--for' => $for]);

        $this->assertNotEmpty($queries);

        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^(?:select|with recursive) /', $sql);
        }
    }

    /**
     * @param  array<string, string>  $options
     */
    private function compile(array $options = []): string
    {
        $this->assertSame(0, Artisan::call('compatibility:sql', ['map' => 'widgets', ...$options]));

        return Artisan::output();
    }

    /**
     * @return list<string>
     */
    private function statements(string $output): array
    {
        [$header, $body] = explode("\n\n", $output, 2);

        foreach (explode("\n", $header) as $line) {
            $this->assertStringStartsWith('-- ', $line);
        }

        $this->assertStringEndsWith(";\n", $body);

        return explode(";\n\n", substr($body, 0, -2));
    }

    /**
     * @param  Closure(): mixed  $run
     * @return list<string>
     */
    private function executed(Closure $run): array
    {
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $run();
        $executed = $queries;

        return array_values(array_filter($executed, fn (string $sql) => preg_match('/^(?:select|with recursive) /', $sql) !== 1));
    }

    /**
     * @param  list<string>  $statements
     * @return list<string>
     */
    private function sorted(array $statements): array
    {
        sort($statements);

        return $statements;
    }
}
