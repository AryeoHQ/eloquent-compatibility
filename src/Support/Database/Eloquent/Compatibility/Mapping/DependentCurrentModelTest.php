<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Bridge\EventBridge;
use Support\Database\Eloquent\Compatibility\Bridge\KeyTranslator;
use Support\Database\Eloquent\Compatibility\CompatibilityMaps;
use Support\Database\Eloquent\Compatibility\Console\DriftCheck;
use Support\Database\Eloquent\Compatibility\Console\DriftFinding;
use Support\Database\Eloquent\Compatibility\Console\DriftQueries;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\Compiler;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Support\Database\Eloquent\Compatibility\Testing\InteractsWithCompatibilityMaps;
use Tests\Fixtures\Support\Workbench\CompatibilityMapEventMirror;
use Tests\Fixtures\Support\Workbench\GadgetPlace;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(DependentCurrentModel::class)]
#[CoversClass(Compiler::class)]
#[CoversClass(KeyTranslator::class)]
#[CoversClass(DriftQueries::class)]
final class DependentCurrentModelTest extends TestCase
{
    use InteractsWithCompatibilityMaps;

    private const string FIRST = 'ffffffff-ffff-4fff-bfff-ffffffffffff';

    private const string LOWER = '00000000-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        Tables::create();
        resolve(Installer::class)->install($this->designated());
    }

    #[Test]
    public function a_create_through_the_previous_model_designates_the_dependent_row_it_inserts(): void
    {
        $id = DB::table('widgets')->insertGetId(['title' => 'Blue gadget', 'street' => '1 Main St']);

        $this->assertSame(DB::table('gadget_places')->sole()->id, DB::table('retained_widgets')->where('id', $id)->value('place_id'));
        $this->assertSame('1 Main St', DB::table('widgets')->where('id', $id)->value('street'));
    }

    #[Test]
    public function a_current_record_designates_the_first_dependent_row_its_readable_condition_selects_and_keeps_it(): void
    {
        $elsewhere = $this->place($this->gadget(), 'Elsewhere');
        $gadget = $this->gadget();
        $this->place($gadget, 'Under another place', parent: $elsewhere);
        $this->assertNull($this->designation($gadget));

        [$gadget, $first, $lower] = $this->gadgetWithTwoRoots($gadget);

        $this->assertSame($first, $this->designation($gadget));
        $this->assertSame('1 First St', $this->widget($gadget)->street);
        $this->assertSame(1, DB::table('gadget_places')->where('id', $lower)->whereNull('deleted_at')->count());
    }

    #[Test]
    public function the_previous_model_reads_no_dependent_row_once_its_designated_row_is_no_longer_readable_and_reads_no_other(): void
    {
        [$gadget, $first, $lower] = $this->gadgetWithTwoRoots();

        DB::table('gadget_places')->where('id', $first)->update(['deleted_at' => now()]);

        $this->assertNull($this->widget($gadget)->street);
        $this->assertSame($first, $this->designation($gadget));

        DB::table('gadget_places')->where('id', $first)->update(['deleted_at' => null, 'parent_id' => $lower]);

        $this->assertNull($this->widget($gadget)->street);
        $this->assertSame($first, $this->designation($gadget));
    }

    #[Test]
    public function a_previous_model_write_to_a_dependent_attribute_is_refused_while_the_designated_row_is_not_readable(): void
    {
        [$gadget, $first, $lower] = $this->gadgetWithTwoRoots();
        DB::table('gadget_places')->where('id', $first)->update(['parent_id' => $lower]);
        $retained = DB::table('retained_widgets')->where('gadget_id', $gadget)->value('id');

        $refusal = $this->assertWriteRefused(
            fn () => DB::table('widgets')->where('gadget_id', $gadget)->update(['street' => '3 New St']),
            "refused a change to street on widgets record {$retained}: its designated gadget_places row is not one the previous model reads",
        );

        $this->assertSame('The record reads only the gadget_places row that retained_widgets.place_id names, and that row no longer matches (place.parent_id IS NULL AND place.deleted_at IS NULL) or belongs to another record.', $refusal->because);
        $this->assertSame('Make that gadget_places row match again through its own model, or repair retained_widgets.place_id by hand.', $refusal->instead);
        $this->assertSame(['1 First St', '2 Lower St'], DB::table('gadget_places')->where('gadget_id', $gadget)->orderBy('street')->pluck('street')->all());
    }

    #[Test]
    public function a_previous_model_write_creates_and_designates_a_dependent_row_when_the_designation_names_no_row(): void
    {
        $gadget = $this->gadget();
        DB::table('retained_widgets')->where('gadget_id', $gadget)->update(['place_id' => Str::uuid()->toString()]);

        DB::table('widgets')->where('gadget_id', $gadget)->update(['street' => '4 Return St']);

        $this->assertSame(DB::table('gadget_places')->where('gadget_id', $gadget)->sole()->id, $this->designation($gadget));
        $this->assertSame('4 Return St', $this->widget($gadget)->street);
    }

    #[Test]
    public function a_previous_model_write_mirrors_only_the_designated_dependent_row(): void
    {
        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror($this->designated()));
        [$gadget, $first, $lower] = $this->gadgetWithTwoRoots();
        $this->recordMirroredEvents();

        Widget::query()->where('gadget_id', $gadget)->sole()->update(['street' => '5 Mirror St']);

        $this->assertMirrored(GadgetPlace::class, ModelEvent::Updated, fn (GadgetPlace $place) => $place->id === $first);
        $this->assertMirrored(GadgetPlace::class, ModelEvent::Updated, fn (GadgetPlace $place) => $place->id === $lower, 0);
        $this->assertSame('5 Mirror St', DB::table('gadget_places')->where('id', $first)->value('street'));
    }

    #[Test]
    public function the_check_names_a_designation_of_a_missing_row_or_of_a_row_of_another_record(): void
    {
        resolve(CompatibilityMaps::class)->register($this->designated());
        [, $first] = $this->gadgetWithTwoRoots();
        $this->artisan('compatibility:check', ['--map' => ['widgets']])->assertSuccessful();
        $stray = $this->gadget();
        $missing = $this->gadget();
        DB::table('retained_widgets')->where('gadget_id', $stray)->update(['place_id' => $first]);
        DB::table('retained_widgets')->where('gadget_id', $missing)->update(['place_id' => Str::uuid()->toString()]);

        $this->artisan('compatibility:check', ['--map' => ['widgets']])->expectsOutputToContain('designation')->assertFailed();
        $this->assertSame([
            [DriftCheck::Designation, 'retained_widgets#'.$this->retainedKey($stray), 'retained_widgets.place_id', 'it names a gadget_places row of another gadgets row'],
            [DriftCheck::Designation, 'retained_widgets#'.$this->retainedKey($missing), 'retained_widgets.place_id', 'it names a gadget_places row that does not exist'],
        ], array_map(
            fn (DriftFinding $finding) => [$finding->check, $finding->record, $finding->attribute, $finding->detail],
            resolve(DriftQueries::class)->find(ResolvedMap::of($this->designated()), 100),
        ));
    }

    #[Test]
    public function it_refuses_a_designation_the_retained_table_does_not_have(): void
    {
        $this->assertThrows(
            fn () => (new Compiler(app('db.connection')))->compile($this->designated('nope')),
            InvalidCompatibilityMap::class,
            'retained_widgets has no column nope',
        );
    }

    #[Test]
    public function it_refuses_an_attribute_that_writes_the_designation(): void
    {
        $this->assertThrows(
            fn () => (new Compiler(app('db.connection')))->compile($this->designated(attributes: [Attribute::retained('place_id')])),
            InvalidCompatibilityMap::class,
            'attribute place_id writes the designation column place_id; the compatibility map maintains it',
        );
    }

    /**
     * @return array{string, string, string}
     */
    private function gadgetWithTwoRoots(null|string $gadget = null): array
    {
        $gadget ??= $this->gadget();
        $first = $this->place($gadget, '1 First St', id: self::FIRST);
        $lower = $this->place($gadget, '2 Lower St', id: self::LOWER, createdAt: '2020-01-01 00:00:00+00');

        return [$gadget, $first, $lower];
    }

    private function gadget(): string
    {
        return (string) DB::table('gadgets')->insertGetId(['name' => 'Gadget']);
    }

    private function place(string $gadget, string $street, null|string $parent = null, null|string $id = null, null|string $createdAt = null): string
    {
        return (string) DB::table('gadget_places')->insertGetId([
            'id' => $id ?? Str::uuid()->toString(),
            'gadget_id' => $gadget,
            'parent_id' => $parent,
            'street' => $street,
            'created_at' => $createdAt ?? now(),
        ]);
    }

    private function designation(string $gadget): null|string
    {
        return DB::table('retained_widgets')->where('gadget_id', $gadget)->value('place_id');
    }

    private function retainedKey(string $gadget): int
    {
        return (int) DB::table('retained_widgets')->where('gadget_id', $gadget)->value('id');
    }

    private function widget(string $gadget): object
    {
        return DB::table('widgets')->where('gadget_id', $gadget)->sole();
    }

    /**
     * @param  list<Attribute>  $attributes
     */
    private function designated(string $column = 'place_id', array $attributes = []): CompatibilityMap
    {
        return new readonly class(new WidgetCompatibilityMap, $column, $attributes) implements CompatibilityMap
        {
            /**
             * @param  list<Attribute>  $attributes
             */
            public function __construct(private WidgetCompatibilityMap $base, private string $column, private array $attributes) {}

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
                [$place, $site] = $this->base->dependentCurrentModels();

                return [$place->designatedBy($this->column), $site];
            }

            public function attributes(): array
            {
                return [...$this->base->attributes(), ...$this->attributes];
            }

            public function unrepresentableStates(): array
            {
                return array_values(array_filter($this->base->unrepresentableStates(), fn (UnrepresentableState $state) => $state->name !== 'second_root_place'));
            }
        };
    }
}
