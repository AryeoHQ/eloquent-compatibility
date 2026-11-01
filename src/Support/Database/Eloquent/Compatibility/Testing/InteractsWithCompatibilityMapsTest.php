<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Testing;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Exception as PHPUnitException;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Support\Database\Eloquent\Compatibility\Write\Events\RecordMirrored;
use Support\Database\Eloquent\Compatibility\Write\WriteRouter;
use Tests\Fixtures\Support\Workbench\CompatibilityMapEventMirror;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\GadgetPlace;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversTrait(InteractsWithCompatibilityMaps::class)]
final class InteractsWithCompatibilityMapsTest extends TestCase
{
    use InteractsWithCompatibilityMaps;

    protected function setUp(): void
    {
        parent::setUp();

        Tables::create();
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        resolve(WriteRouter::class)->register(new CompatibilityMapEventMirror(new WidgetCompatibilityMap));
    }

    #[Test]
    public function it_records_the_events_the_router_mirrors_only_once_a_test_asks(): void
    {
        $gadget = $this->gadget();

        $this->recordMirroredEvents();
        $gadget->update(['name' => 'Renamed']);

        $this->assertCount(1, $this->mirroredEvents());
        $this->assertInstanceOf(Widget::class, $this->mirroredEvents()[0]->destination);
        $this->assertSame($gadget, $this->mirroredEvents()[0]->source);
    }

    #[Test]
    public function it_starts_afresh_and_records_each_event_once_when_a_test_asks_again(): void
    {
        $gadget = $this->gadget();
        $this->recordMirroredEvents();
        $gadget->update(['name' => 'Renamed']);

        $this->recordMirroredEvents();

        $this->assertSame([], $this->mirroredEvents());

        $gadget->update(['name' => 'Renamed again']);

        $this->assertCount(1, $this->mirroredEvents());
    }

    #[Test]
    public function it_passes_assert_mirrored_for_the_model_the_event_the_filter_and_the_count_the_router_raised(): void
    {
        $this->recordMirroredEvents();

        $gadget = $this->gadget();
        $place = GadgetPlace::query()->create(['gadget_id' => $gadget->id, 'street' => '1 Main St']);
        $gadget->update(['name' => 'Renamed']);
        $gadget->update(['name' => 'Renamed again']);

        $this->assertMirrored(Widget::class, ModelEvent::Created);
        $this->assertMirrored(Widget::class, ModelEvent::Updated, fn (Widget $widget, RecordMirrored $recordMirrored) => $recordMirrored->source === $place && $widget->street === '1 Main St');
        $this->assertMirrored(Widget::class, ModelEvent::Updated, fn (Widget $widget, RecordMirrored $recordMirrored) => $recordMirrored->source === $gadget, times: 2);
        $this->assertMirrored(Widget::class, ModelEvent::Saving, times: 4);
        $this->assertMirrored(Widget::class, ModelEvent::Deleted, times: 0);
    }

    #[Test]
    public function it_fails_assert_mirrored_and_names_what_the_router_raised_instead(): void
    {
        $gadget = $this->gadget();
        $this->recordMirroredEvents();
        $gadget->update(['name' => 'Renamed']);
        $raised = 'It raised: Widget '.Widget::query()->sole()->id.' [saving, updating, updated, saved]';

        $this->assertThrows(fn () => $this->assertMirrored(Widget::class, ModelEvent::Created), PHPUnitException::class, "Expected the write router to raise 'created' on ".Widget::class.' 1 time(s). '.$raised);
        $this->assertThrows(fn () => $this->assertMirrored(Gadget::class, ModelEvent::Updated), PHPUnitException::class, $raised);
        $this->assertThrows(fn () => $this->assertMirrored(Widget::class, ModelEvent::Updated, fn (Widget $widget) => $widget->title === 'Blue gadget'), PHPUnitException::class, $raised);
        $this->assertThrows(fn () => $this->assertMirrored(Widget::class, ModelEvent::Updated, times: 2), PHPUnitException::class, "raise 'updated' on ".Widget::class.' 2 time(s)');
    }

    #[Test]
    public function it_fails_assert_mirrored_and_says_the_router_raised_nothing(): void
    {
        $this->recordMirroredEvents();

        $this->assertThrows(fn () => $this->assertMirrored(Widget::class, ModelEvent::Updated), PHPUnitException::class, 'It raised: nothing');
    }

    #[Test]
    public function it_passes_assert_nothing_mirrored_when_the_router_raised_nothing(): void
    {
        $this->recordMirroredEvents();

        DB::table('people')->insert(['email' => 'unmapped@example.com']);
        Widget::query()->create(['title' => 'Written through the previous model']);

        $this->assertNothingMirrored();
    }

    #[Test]
    public function it_fails_assert_nothing_mirrored_and_names_what_the_router_raised(): void
    {
        $this->recordMirroredEvents();

        $this->gadget();

        $this->assertThrows(
            fn () => $this->assertNothingMirrored(),
            PHPUnitException::class,
            'Expected no mirrored events. The write router raised: Widget '.Widget::query()->sole()->id.' [saving, creating, created, saved]',
        );
    }

    #[Test]
    public function it_returns_the_refusal_of_a_mirrored_listener_and_rolls_the_write_back(): void
    {
        $gadget = $this->gadget();
        Event::listen('eloquent.updating: '.Widget::class, fn () => false);

        $refusal = $this->assertWriteRefused(fn () => $gadget->update(['name' => 'Refused']), "a 'updating' listener of Widget");

        $this->assertStringContainsString("Compatibility map 'widgets' refused this write", $refusal->refused);
        $this->assertSame('Blue gadget', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_returns_a_refusal_of_the_router_and_rolls_the_write_back(): void
    {
        $widget = Widget::query()->create(['title' => 'Blue gadget', 'is_archived' => true]);

        $refusal = $this->assertWriteRefused(fn () => $widget->update(['status' => 'Live', 'title' => 'Refused']), 'a status change on an archived gadget');

        $this->assertSame('An archived gadget has no status the widget can change.', $refusal->because);
        $this->assertSame('Unarchive the gadget first.', $refusal->instead);
        $this->assertSame(['Draft', 'Blue gadget'], [DB::table('widgets')->value('status'), DB::table('gadgets')->value('name')]);
    }

    #[Test]
    public function it_fails_assert_write_refused_when_the_write_succeeds(): void
    {
        $gadget = $this->gadget();

        $this->assertThrows(
            fn () => $this->assertWriteRefused(fn () => $gadget->update(['name' => 'Accepted'])),
            PHPUnitException::class,
            'Expected the write to be refused, and it succeeded.',
        );
        $this->assertSame('Accepted', DB::table('widgets')->value('title'));
    }

    #[Test]
    public function it_fails_assert_write_refused_when_the_refusal_says_something_else(): void
    {
        $gadget = $this->gadget();
        Event::listen('eloquent.updating: '.Widget::class, fn () => false);

        $this->assertThrows(
            fn () => $this->assertWriteRefused(fn () => $gadget->update(['name' => 'Refused']), 'a status change on an archived gadget'),
            PHPUnitException::class,
            'contains "a status change on an archived gadget"',
        );
    }

    #[Test]
    public function it_rethrows_a_failure_that_is_not_a_refusal(): void
    {
        $this->assertThrows(fn () => $this->assertWriteRefused(fn () => throw new RuntimeException('Not a refusal')), RuntimeException::class, 'Not a refusal');
        $this->assertThrows(fn () => $this->assertWriteRefused(fn () => DB::table('gadgets')->insert(['name' => null])), QueryException::class, 'SQLSTATE[23502]');
    }

    #[Test]
    public function it_runs_a_write_without_mirroring_and_returns_its_result(): void
    {
        $gadget = $this->gadget();
        $this->recordMirroredEvents();

        $result = $this->withoutEventMirroring(fn () => $gadget->update(['name' => 'Mirror off']));

        $this->assertTrue($result);
        $this->assertNothingMirrored();
        $this->assertSame('Mirror off', DB::table('widgets')->value('title'));

        $gadget->update(['name' => 'Mirror on']);

        $this->assertMirrored(Widget::class, ModelEvent::Updated);
    }

    private function gadget(): Gadget
    {
        return Gadget::query()->create(['name' => 'Blue gadget']);
    }
}
