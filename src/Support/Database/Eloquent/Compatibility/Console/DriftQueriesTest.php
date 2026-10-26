<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\CompatibilityMaps;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\PreviousModel;
use Support\Database\Eloquent\Compatibility\Mapping\PrimaryCurrentModel;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(DriftQueries::class)]
final class DriftQueriesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Tables::create();
    }

    #[Test]
    public function it_finds_nothing_when_every_record_has_its_previous_and_current_models(): void
    {
        $this->install();
        $this->widget();
        DB::table('retained_widgets')->insert(['status' => 'Retired']);

        $this->assertSame([], $this->find());
    }

    #[Test]
    public function it_finds_a_retained_row_that_links_no_current_record(): void
    {
        $this->install();
        $unlinked = DB::table('retained_widgets')->insertGetId(['status' => 'Draft']);

        $this->assertFindings([
            [DriftCheck::PreviousWithoutCurrent, "retained_widgets#{$unlinked}", 'retained_widgets.gadget_id', 'no gadgets row is linked, and no hidden unrepresentable state covers the record'],
        ]);
    }

    #[Test]
    public function it_finds_a_retained_row_that_links_a_current_record_that_does_not_exist(): void
    {
        $this->install();
        DB::statement('ALTER TABLE retained_widgets DROP CONSTRAINT retained_widgets_gadget_id_foreign');
        $dangling = DB::table('retained_widgets')->insertGetId(['gadget_id' => Str::uuid()->toString()]);

        $this->assertFindings([
            [DriftCheck::PreviousWithoutCurrent, "retained_widgets#{$dangling}", 'retained_widgets.gadget_id', 'it links a gadgets row that does not exist'],
        ]);
    }

    #[Test]
    public function it_leaves_out_a_retained_row_a_hidden_unrepresentable_state_covers_and_no_other(): void
    {
        $this->install();
        DB::statement('ALTER TABLE retained_widgets DROP CONSTRAINT retained_widgets_gadget_id_foreign');
        DB::table('retained_widgets')->insert(['status' => 'Retired']);
        $dangling = DB::table('retained_widgets')->insertGetId(['status' => 'Retired', 'gadget_id' => Str::uuid()->toString()]);
        $draft = DB::table('retained_widgets')->insertGetId(['status' => 'Draft']);

        $this->assertSame(
            ["retained_widgets#{$dangling}", "retained_widgets#{$draft}"],
            array_map(fn (DriftFinding $finding) => $finding->record, $this->find()),
        );
    }

    #[Test]
    public function it_finds_a_current_record_no_retained_row_links(): void
    {
        $this->install();
        $gadget = $this->gadget();
        DB::table('retained_widgets')->where('gadget_id', $gadget)->delete();

        $this->assertFindings([
            [DriftCheck::OrphanCurrent, "gadgets#{$gadget}", 'retained_widgets.gadget_id', 'no retained row links it, so the previous model cannot see the record'],
        ]);
    }

    #[Test]
    public function it_finds_a_current_record_that_more_than_one_retained_row_links(): void
    {
        $this->install();
        $gadget = $this->gadget();
        DB::table('retained_widgets')->insert([['gadget_id' => $gadget], ['gadget_id' => $gadget]]);

        $this->assertFindings([
            [DriftCheck::MoreThanOnePrimary, "gadgets#{$gadget}", 'retained_widgets.gadget_id', '3 retained rows link the same record'],
        ]);
    }

    #[Test]
    public function it_finds_each_record_in_a_refused_state(): void
    {
        $this->install();
        $gadget = $this->gadget();
        $first = $this->place($gadget);
        DB::unprepared('ALTER TABLE gadget_places DISABLE TRIGGER USER');
        $second = $this->place($gadget);
        DB::unprepared('ALTER TABLE gadget_places ENABLE TRIGGER USER');
        $this->place($gadget, parent: $first);

        $this->assertFindings(array_map(
            fn (string $place) => [DriftCheck::RefusedState, "gadget_places#{$place}", 'second_root_place', 'the record is in a refused state: a second root place for one gadget'],
            [min($first, $second), max($first, $second)],
        ));
    }

    #[Test]
    public function it_finds_a_designation_of_a_missing_row_or_of_a_row_of_another_record(): void
    {
        $this->install($this->designated());
        $kept = $this->gadget();
        $place = $this->place($kept);
        $stray = $this->gadget();
        $missing = $this->gadget();
        DB::table('retained_widgets')->where('gadget_id', $stray)->update(['place_id' => $place]);
        DB::table('retained_widgets')->where('gadget_id', $missing)->update(['place_id' => Str::uuid()->toString()]);

        $this->assertFindings([
            [DriftCheck::Designation, 'retained_widgets#'.$this->retainedKey($stray), 'retained_widgets.place_id', 'it names a gadget_places row of another gadgets row'],
            [DriftCheck::Designation, 'retained_widgets#'.$this->retainedKey($missing), 'retained_widgets.place_id', 'it names a gadget_places row that does not exist'],
        ]);
    }

    #[Test]
    public function it_reports_the_checks_in_order_and_at_most_the_limit_of_each_lowest_key_first(): void
    {
        $this->install();
        $unlinked = [
            DB::table('retained_widgets')->insertGetId(['status' => 'Draft']),
            DB::table('retained_widgets')->insertGetId(['status' => 'Draft']),
            DB::table('retained_widgets')->insertGetId(['status' => 'Draft']),
        ];
        $orphans = [$this->gadget(), $this->gadget(), $this->gadget()];
        DB::table('retained_widgets')->whereIn('gadget_id', $orphans)->delete();
        sort($orphans);
        $shared = $this->gadget();
        DB::table('retained_widgets')->insert(['gadget_id' => $shared]);

        $this->assertSame([
            ['previous without current', "retained_widgets#{$unlinked[0]}"],
            ['previous without current', "retained_widgets#{$unlinked[1]}"],
            ['orphan current', "gadgets#{$orphans[0]}"],
            ['orphan current', "gadgets#{$orphans[1]}"],
            ['more than one primary', "gadgets#{$shared}"],
        ], array_map(fn (DriftFinding $finding) => [$finding->check->value, $finding->record], $this->find(2)));
    }

    /**
     * @param  list<array{DriftCheck, string, string, string}>  $expected
     */
    private function assertFindings(array $expected): void
    {
        $this->assertSame(
            array_map(fn (array $finding) => ['widgets', ...$finding], $expected),
            array_map(fn (DriftFinding $finding) => [$finding->map, $finding->check, $finding->record, $finding->attribute, $finding->detail], $this->find()),
        );
    }

    /**
     * @return list<DriftFinding>
     */
    private function find(int $limit = 100): array
    {
        return resolve(DriftQueries::class)->find(resolve(CompatibilityMaps::class)->get('widgets'), $limit);
    }

    private function install(CompatibilityMap $map = new WidgetCompatibilityMap): void
    {
        resolve(Installer::class)->install($map);
        resolve(CompatibilityMaps::class)->register($map);
    }

    private function widget(): int
    {
        return DB::table('widgets')->insertGetId(['title' => 'Blue gadget', 'street' => '1 Main St']);
    }

    private function gadget(): string
    {
        return (string) DB::table('gadgets')->insertGetId(['name' => 'Gadget']);
    }

    private function place(string $gadget, null|string $parent = null): string
    {
        return (string) DB::table('gadget_places')->insertGetId(['gadget_id' => $gadget, 'parent_id' => $parent, 'street' => 'Main St']);
    }

    private function retainedKey(string $gadget): int
    {
        return (int) DB::table('retained_widgets')->where('gadget_id', $gadget)->value('id');
    }

    private function designated(): CompatibilityMap
    {
        return new readonly class(new WidgetCompatibilityMap) implements CompatibilityMap
        {
            public function __construct(private WidgetCompatibilityMap $base) {}

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

                return [$place->designatedBy('place_id'), $site];
            }

            public function attributes(): array
            {
                return $this->base->attributes();
            }

            public function unrepresentableStates(): array
            {
                return $this->base->unrepresentableStates();
            }
        };
    }
}
