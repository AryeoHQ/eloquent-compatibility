<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Testing\InteractsWithCompatibilityMaps;
use Tests\Fixtures\Support\Workbench\AccountCompatibilityMap;
use Tests\Fixtures\Support\Workbench\ProfileCompatibilityMap;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(Installer::class)]
#[CoversClass(Compiler::class)]
final class InstallerTest extends TestCase
{
    use InteractsWithCompatibilityMaps;

    private const array KEYWORDS = ['as', 'for', 'join', 'left', 'limit', 'on', 'order', 'returning', 'set', 'where'];

    protected function setUp(): void
    {
        parent::setUp();

        Tables::create();
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
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
    public function it_locks_a_record_through_the_compatibility_view(): void
    {
        $id = $this->widget();

        $this->assertSame($id, DB::table('widgets')->where('id', $id)->lockForUpdate()->sole()->id);
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

    private function widget(): int
    {
        return DB::table('widgets')->insertGetId(['title' => 'Blue gadget', 'price' => '12.50', 'street' => '1 Main St', 'latitude' => 47.5]);
    }

    private function objects(string $catalog, string $column): int
    {
        return (int) DB::scalar("select count(*) from {$catalog} where {$column} like 'compatibility\\_\\_widgets\\_\\_%'");
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
