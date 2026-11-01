<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\TestCase;

#[CoversClass(ModelEvent::class)]
final class ModelEventTest extends TestCase
{
    #[Test]
    public function it_names_each_case_by_its_value(): void
    {
        $this->assertSame([
            'Saving' => 'saving',
            'Saved' => 'saved',
            'Creating' => 'creating',
            'Created' => 'created',
            'Updating' => 'updating',
            'Updated' => 'updated',
            'Deleting' => 'deleting',
            'Deleted' => 'deleted',
            'Trashed' => 'trashed',
            'Restoring' => 'restoring',
            'Restored' => 'restored',
            'ForceDeleting' => 'forceDeleting',
            'ForceDeleted' => 'forceDeleted',
        ], array_column(array_map(fn (ModelEvent $event) => ['name' => $event->name, 'value' => $event->value], ModelEvent::cases()), 'value', 'name'));
    }

    #[Test]
    public function it_names_the_event_string_eloquent_dispatches_for_a_model(): void
    {
        $this->assertSame('eloquent.updated: '.Gadget::class, ModelEvent::Updated->for(Gadget::class));
        $this->assertSame('eloquent.forceDeleting: '.Gadget::class, ModelEvent::ForceDeleting->for(Gadget::class));
    }

    #[Test]
    public function it_names_only_events_eloquent_dispatches(): void
    {
        Tables::create();
        $dispatched = [];
        Event::listen('eloquent.*', function (string $event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        $gadget = Gadget::query()->create(['name' => 'Blue gadget']);
        $gadget->update(['name' => 'Red gadget']);
        $gadget->delete();
        $gadget->restore();
        $gadget->forceDelete();

        $this->assertSame([], array_values(array_diff(
            array_map(fn (ModelEvent $event) => $event->for(Gadget::class), ModelEvent::cases()),
            array_filter($dispatched, fn (string $event) => Str::endsWith($event, ': '.Gadget::class)),
        )));
    }
}
