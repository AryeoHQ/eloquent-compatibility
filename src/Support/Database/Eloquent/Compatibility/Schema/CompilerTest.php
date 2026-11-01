<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Illuminate\Support\Facades\DB;
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
use Support\Database\Eloquent\Compatibility\Testing\InteractsWithCompatibilityMaps;
use Support\Database\Eloquent\Compatibility\Write\WriteRouter;
use Tests\Fixtures\Support\Workbench\CompatibilityMapEventMirror;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(Compiler::class)]
final class CompilerTest extends TestCase
{
    use InteractsWithCompatibilityMaps;

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
            "Compatibility map 'widgets' is declared wrongly: gadgets.code is NOT NULL without a default, and an insert through ".Widget::class.' leaves it empty; map an attribute to it or fill it with fillWhenCreatedFromPrevious()',
        );
        $this->assertNull(DB::scalar("select to_regclass('widgets')"));
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
    public function it_compiles_one_view_that_postgresql_cannot_write_and_nothing_else(): void
    {
        $statements = $this->compiler()->compile(new WidgetCompatibilityMap)->statements();

        $this->assertCount(1, $statements);
        $this->assertStringStartsWith("CREATE VIEW \"widgets\" AS SELECT\n  widget.\"id\" AS \"id\",", $statements[0]);
        $this->assertStringContainsString("\nFROM \"gadgets\" gadget\nJOIN \"retained_widgets\" widget ON widget.\"gadget_id\" = gadget.\"id\"\nWHERE NOT COALESCE((widget.gadget_id IS NULL AND widget.status = 'Retired'), false)", $statements[0]);
        $this->assertStringNotContainsStringIgnoringCase('trigger', $statements[0]);

        resolve(Installer::class)->install(new WidgetCompatibilityMap);

        $this->assertSame('NO', DB::scalar("select is_insertable_into from information_schema.tables where table_schema = current_schema() and table_name = 'widgets'"));
        $this->assertSame('NO', DB::scalar("select is_updatable from information_schema.views where table_schema = current_schema() and table_name = 'widgets'"));
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
    #[TestWith(['primary_fill_collision', "gadget.name is set both by attribute 'title' and by fillWhenCreatedFromPrevious()"])]
    #[TestWith(['dependent_fill_collision', "place.street is set both by attribute 'street' and by fillWhenCreatedFromPrevious()"])]
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
        $this->install($this->compatibilityMap(['attributes' => [...(new WidgetCompatibilityMap)->attributes(), Attribute::mapped('serial_number')->asType('integer')->storedAs('gadget', 'serial_number')]]));

        $numbered = Widget::query()->create(['title' => 'Numbered by the table'])->id;
        $id = Widget::query()->create(['title' => 'Numbered', 'serial_number' => 7])->id;
        Widget::query()->whereKey($id)->update(['serial_number' => 8]);

        $this->assertSame([1, 8], [DB::table('widgets')->where('id', $numbered)->value('serial_number'), DB::table('widgets')->where('id', $id)->value('serial_number')]);
    }

    #[Test]
    #[TestWith(['by default'])]
    #[TestWith(['always'])]
    public function it_lets_the_table_number_an_identity_retained_key_that_an_insert_omits(string $identity): void
    {
        DB::unprepared("alter table retained_widgets alter column id drop default; drop sequence retained_widgets_id_seq; alter table retained_widgets alter column id add generated {$identity} as identity");
        $this->install(new WidgetCompatibilityMap);

        $ids = [Widget::query()->create(['title' => 'First'])->id, Widget::query()->insertGetId(['title' => 'Second'])];

        $this->assertSame([[1, 2], [1, 2]], [$ids, DB::table('retained_widgets')->orderBy('id')->pluck('id')->all()]);
    }

    #[Test]
    public function it_writes_the_default_of_a_not_null_current_column_that_an_insert_leaves_empty(): void
    {
        DB::statement('alter table gadgets add column weight_grams integer not null default 0');
        DB::statement("alter table gadget_sites add column label text default 'Unlabelled'");
        $this->install($this->compatibilityMap(['attributes' => [
            ...(new WidgetCompatibilityMap)->attributes(),
            Attribute::mapped('longitude')->asType('numeric(24,12)')->storedAs('site', 'longitude'),
            Attribute::mapped('label')->asType('text')->storedAs('site', 'label'),
            Attribute::mapped('weight')->asType('numeric')->readAs('gadget', 'gadget.weight_grams / 1000.0')->writesTo('gadget', 'weight_grams', fn (array $widget) => $widget['weight'] === null ? null : (int) round((float) $widget['weight'] * 1000)),
        ]]));

        Widget::query()->insert(['title' => 'Inserted', 'latitude' => 1.5, 'weight' => null]);
        $updated = Widget::query()->insertGetId(['title' => 'Updated']);
        Widget::query()->whereKey($updated)->update(['latitude' => 2.5]);

        $this->assertSame(
            [['1.500000000000', '0.000000000000', 'Unlabelled'], ['2.500000000000', '0.000000000000', null]],
            DB::table('gadget_sites')->orderBy('latitude')->get()->map(fn (object $site) => [$site->latitude, $site->longitude, $site->label])->all(),
        );
        $this->assertSame([0, 0], DB::table('gadgets')->pluck('weight_grams')->all());
    }

    #[Test]
    public function it_stores_a_read_only_mapped_attribute_on_an_insert_and_still_refuses_a_change_to_it(): void
    {
        DB::statement('alter table gadgets add column grade integer not null default 3');
        DB::statement('alter table gadgets add column serial_number integer generated always as identity');
        DB::statement('alter table gadget_places add column kind text not null');
        $this->install($this->compatibilityMap(['attributes' => [
            ...(new WidgetCompatibilityMap(['created_at' => Attribute::mapped('created_at')->asType('timestamp(0) without time zone')->storedAs('gadget', 'created_at')->readOnlyBecause('A widget keeps the time it was created.')]))->attributes(),
            Attribute::mapped('grade')->asType('integer')->storedAs('gadget', 'grade')->readOnlyBecause('Grading assigns it.'),
            Attribute::mapped('serial_number')->asType('integer')->storedAs('gadget', 'serial_number')->readOnlyBecause('The database numbers it.'),
            Attribute::mapped('longitude')->asType('numeric(24,12)')->storedAs('site', 'longitude')->readOnlyBecause('A survey sets it.'),
            Attribute::mapped('kind')->asType('text')->storedAs('place', 'kind')->readOnlyBecause('A place keeps its kind.'),
        ]]));

        $widget = Widget::query()->create(['title' => 'Stamped', 'grade' => 5, 'latitude' => 1.5, 'longitude' => 2.5, 'street' => '1 Main St', 'kind' => 'Root']);
        Widget::query()->insert(['title' => 'Defaulted']);

        $this->assertSame(
            [[$widget->getAttributes()['created_at'], 5, 1], [3, 2]],
            [...DB::table('gadgets')->where('serial_number', 1)->get([DB::raw("to_char(created_at, 'YYYY-MM-DD HH24:MI:SS') as created"), 'grade', 'serial_number'])->map(fn (object $gadget) => [$gadget->created, $gadget->grade, $gadget->serial_number])->all(), [DB::table('gadgets')->where('serial_number', 2)->value('grade'), 2]],
        );
        $this->assertSame(['2.500000000000', 'Root'], [DB::table('gadget_sites')->value('longitude'), DB::table('gadget_places')->sole()->kind]);
        $this->assertWriteRefused(fn () => $widget->update(['grade' => 6]), 'refused a change to widgets.grade');
        $this->assertWriteRefused(fn () => $widget->update(['created_at' => '2020-01-01 00:00:00']), 'refused a change to widgets.created_at');
    }

    #[Test]
    public function it_stores_the_writable_attribute_when_a_read_only_attribute_is_stored_in_the_same_column(): void
    {
        $this->install($this->compatibilityMap(['attributes' => [
            ...(new WidgetCompatibilityMap)->attributes(),
            Attribute::mapped('title_copy')->asType('character varying(255)')->storedAs('gadget', 'name')->readOnlyBecause('A copy follows the title.'),
        ]]));

        $widget = Widget::query()->create(['title' => 'Lamp']);

        $this->assertSame(['Lamp', 'Lamp', 'Lamp'], [DB::table('gadgets')->value('name'), ...array_values($widget->fresh()?->only(['title', 'title_copy']) ?? [])]);
        $this->assertWriteRefused(fn () => $widget->update(['title_copy' => 'X']), 'refused a change to widgets.title_copy');
        Widget::query()->sole()->update(['title' => 'Desk']);

        $this->assertSame(['Desk', 'Desk'], array_values((array) DB::table('widgets')->sole(['title', 'title_copy'])));
    }

    /**
     * @param  array<string, string>  $previousFills
     */
    #[Test]
    #[TestWith(['alter table retained_widgets add column region text not null', false, 'retained_widgets.region is NOT NULL without a default, and a gadgets row created through its own model leaves it empty; fill it with fillWhenCreatedFromCurrent()'])]
    #[TestWith(['alter table retained_widgets add column region text not null', true, 'retained_widgets.region is NOT NULL without a default, and an insert through '.Widget::class.' leaves it empty; declare it as a retained attribute'])]
    #[TestWith(['alter table gadgets add column code text not null', false, 'gadgets.code is NOT NULL without a default, and an insert through '.Widget::class.' leaves it empty; map an attribute to it or fill it with fillWhenCreatedFromPrevious()'])]
    #[TestWith(['alter table gadget_places add column kind text not null', false, 'gadget_places.kind is NOT NULL without a default, and an insert through '.Widget::class.' leaves it empty; map an attribute to it or fill it with fillWhenCreatedFromPrevious()'])]
    public function it_refuses_a_not_null_column_without_a_default_that_an_insert_leaves_empty(string $column, bool $fillRegion, string $message): void
    {
        DB::statement($column);
        $previous = (new WidgetCompatibilityMap)->previousModel();
        $map = $this->compatibilityMap(['previousModel' => $previous->fillWhenCreatedFromCurrent([...$previous->fillWhenCreatedFromCurrent, ...($fillRegion ? ['region' => fn () => 'North'] : [])])]);

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
        $this->install($this->compatibilityMap(['dependentCurrentModels' => $this->dependents(place: fn (DependentCurrentModel $place) => $place->fillWhenCreatedFromPrevious(['kind' => fn () => 'Root']))]));

        $this->widget();
        Gadget::query()->create(['name' => 'Created through its own model']);

        $this->assertSame(
            [['North', 'DRAFT'], ['North', 'DRAFT']],
            DB::table('retained_widgets')->orderBy('id')->get(['region', 'code'])->map(fn (object $row) => [$row->region, $row->code])->all(),
        );
        $this->assertSame([1, 2], DB::table('gadgets')->orderBy('serial_number')->pluck('serial_number')->all());
        $this->assertSame('Root', DB::table('gadget_places')->value('kind'));
    }

    private function compiler(): Compiler
    {
        return new Compiler(DB::connection());
    }

    private function objects(string $catalog, string $column, string $prefix): int
    {
        return (int) DB::scalar("select count(*) from {$catalog} where {$column} like ".SqlFragments::like($prefix));
    }

    private function widget(): int
    {
        return Widget::query()->create(['title' => 'Blue gadget', 'price' => '12.50', 'street' => '1 Main St', 'latitude' => 47.5])->id;
    }

    private function install(CompatibilityMap $map): void
    {
        resolve(Installer::class)->install($map);
        resolve(WriteRouter::class)->register(new CompatibilityMapEventMirror($map));
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
            'missing_previous_fill_column' => ['previousModel' => $previous->fillWhenCreatedFromCurrent(['nope' => fn () => 'Filled'])],
            'missing_primary_fill_column' => ['primaryCurrentModel' => $gadget->fillWhenCreatedFromPrevious(['nope' => fn () => 'Filled'])],
            'missing_dependent_fill_column' => ['dependentCurrentModels' => $this->dependents(place: fn (DependentCurrentModel $place) => $place->fillWhenCreatedFromPrevious(['nope' => fn () => 'Filled']))],
            'primary_fill_collision' => ['primaryCurrentModel' => $gadget->fillWhenCreatedFromPrevious(['name' => fn () => 'Filled'])],
            'dependent_fill_collision' => ['dependentCurrentModels' => $this->dependents(place: fn (DependentCurrentModel $place) => $place->fillWhenCreatedFromPrevious(['street' => fn () => 'Filled']))],
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
