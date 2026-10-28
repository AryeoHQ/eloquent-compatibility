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
use Support\Database\Eloquent\Compatibility\Bridge\EventBridge;
use Support\Database\Eloquent\Compatibility\Bridge\Events\RecordMirrored;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
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
        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror(new WidgetCompatibilityMap));
    }

    #[Test]
    public function it_records_the_events_the_bridge_mirrors_only_once_a_test_asks(): void
    {
        $widget = $this->widget();

        $this->recordMirroredEvents();
        $widget->update(['title' => 'Renamed']);

        $this->assertCount(1, $this->mirroredEvents());
        $this->assertInstanceOf(Gadget::class, $this->mirroredEvents()[0]->destination);
        $this->assertSame($widget, $this->mirroredEvents()[0]->source);
    }

    #[Test]
    public function it_starts_afresh_and_records_each_event_once_when_a_test_asks_again(): void
    {
        $widget = $this->widget();
        $this->recordMirroredEvents();
        $widget->update(['title' => 'Renamed']);

        $this->recordMirroredEvents();

        $this->assertSame([], $this->mirroredEvents());

        $widget->update(['title' => 'Renamed again']);

        $this->assertCount(1, $this->mirroredEvents());
    }

    #[Test]
    public function it_passes_assert_mirrored_for_the_model_the_event_the_filter_and_the_count_the_bridge_raised(): void
    {
        $this->recordMirroredEvents();

        $widget = $this->widget();
        $widget->update(['title' => 'Renamed']);
        $widget->update(['title' => 'Renamed again']);

        $this->assertMirrored(Gadget::class, ModelEvent::Created);
        $this->assertMirrored(GadgetPlace::class, ModelEvent::Created, fn (GadgetPlace $place) => $place->getAttribute('street') === '1 Main St');
        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Gadget $gadget, RecordMirrored $recordMirrored) => $recordMirrored->source === $widget, times: 2);
        $this->assertMirrored(Gadget::class, ModelEvent::Saving, times: 3);
        $this->assertMirrored(Gadget::class, ModelEvent::Deleted, times: 0);
    }

    #[Test]
    public function it_fails_assert_mirrored_and_names_what_the_bridge_raised_instead(): void
    {
        $widget = $this->widget();
        $this->recordMirroredEvents();
        $widget->update(['title' => 'Renamed']);
        $raised = 'It raised: Gadget "'.$widget->refresh()->gadget_id.'" [saving, updating, updated, saved]';

        $this->assertThrows(fn () => $this->assertMirrored(Gadget::class, ModelEvent::Created), PHPUnitException::class, "Expected the event bridge to raise 'created' on ".Gadget::class.' 1 time(s). '.$raised);
        $this->assertThrows(fn () => $this->assertMirrored(GadgetPlace::class, ModelEvent::Updated), PHPUnitException::class, $raised);
        $this->assertThrows(fn () => $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Gadget $gadget) => $gadget->getAttribute('name') === 'Blue gadget'), PHPUnitException::class, $raised);
        $this->assertThrows(fn () => $this->assertMirrored(Gadget::class, ModelEvent::Updated, times: 2), PHPUnitException::class, "raise 'updated' on ".Gadget::class.' 2 time(s)');
    }

    #[Test]
    public function it_fails_assert_mirrored_and_says_the_bridge_raised_nothing(): void
    {
        $this->recordMirroredEvents();

        $this->assertThrows(fn () => $this->assertMirrored(Gadget::class, ModelEvent::Updated), PHPUnitException::class, 'It raised: nothing');
    }

    #[Test]
    public function it_passes_assert_nothing_mirrored_when_the_bridge_raised_nothing(): void
    {
        $this->recordMirroredEvents();

        DB::table('people')->insert(['email' => 'unmapped@example.com']);

        $this->assertNothingMirrored();
    }

    #[Test]
    public function it_fails_assert_nothing_mirrored_and_names_what_the_bridge_raised(): void
    {
        $this->recordMirroredEvents();

        $widget = $this->widget();

        $this->assertThrows(
            fn () => $this->assertNothingMirrored(),
            PHPUnitException::class,
            'Expected no mirrored events. The event bridge raised: Gadget "'.$widget->refresh()->gadget_id.'" [saving, creating, created, saved]',
        );
    }

    #[Test]
    public function it_returns_the_refusal_of_a_mirrored_listener_and_rolls_the_write_back(): void
    {
        $widget = $this->widget();
        Event::listen('eloquent.updating: '.Gadget::class, fn () => false);

        $refusal = $this->assertWriteRefused(fn () => $widget->update(['title' => 'Refused']), "a 'updating' listener of Gadget");

        $this->assertStringContainsString("Compatibility map 'widgets' refused this write", $refusal->refused);
        $this->assertSame('Blue gadget', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_returns_the_refusal_of_the_database_as_a_compatibility_refusal_and_rolls_the_write_back(): void
    {
        $id = $this->widget()->getKey();
        DB::table('gadgets')->update(['archived' => true]);

        $refusal = $this->assertWriteRefused(fn () => DB::table('widgets')->where('id', $id)->update(['status' => 'Live']), 'a status change on an archived gadget');

        $this->assertInstanceOf(QueryException::class, $refusal->getPrevious());
        $this->assertSame('An archived gadget has no status the widget can change.', $refusal->because);
        $this->assertSame('Unarchive the gadget first.', $refusal->instead);
        $this->assertSame('Draft', DB::table('widgets')->where('id', $id)->value('status'));
    }

    #[Test]
    public function it_fails_assert_write_refused_when_the_write_succeeds(): void
    {
        $widget = $this->widget();

        $this->assertThrows(
            fn () => $this->assertWriteRefused(fn () => $widget->update(['title' => 'Accepted'])),
            PHPUnitException::class,
            'Expected the write to be refused, and it succeeded.',
        );
        $this->assertSame('Accepted', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_fails_assert_write_refused_when_the_refusal_says_something_else(): void
    {
        $widget = $this->widget();
        Event::listen('eloquent.updating: '.Gadget::class, fn () => false);

        $this->assertThrows(
            fn () => $this->assertWriteRefused(fn () => $widget->update(['title' => 'Refused']), 'a status change on an archived gadget'),
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
        $widget = $this->widget();
        $this->recordMirroredEvents();

        $result = $this->withoutEventMirroring(fn () => $widget->update(['title' => 'Mirror off']));

        $this->assertTrue($result);
        $this->assertNothingMirrored();
        $this->assertSame('Mirror off', DB::table('gadgets')->value('name'));

        $widget->update(['title' => 'Mirror on']);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated);
    }

    private function widget(): Widget
    {
        return Widget::query()->create(['title' => 'Blue gadget', 'price' => '12.50', 'street' => '1 Main St', 'latitude' => 47.5]);
    }
}
