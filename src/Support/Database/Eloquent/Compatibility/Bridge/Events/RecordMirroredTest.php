<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge\Events;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\TestCase;

#[CoversClass(RecordMirrored::class)]
final class RecordMirroredTest extends TestCase
{
    #[Test]
    public function it_carries_the_write_and_the_mirrored_record_it_was_given(): void
    {
        $source = new Widget(['title' => 'Renamed']);
        $destination = new Gadget(['name' => 'Renamed']);
        $events = [ModelEvent::Saving, ModelEvent::Updating, ModelEvent::Updated, ModelEvent::Saved];

        $recordMirrored = new RecordMirrored('widgets', $source, $destination, $events, ['name' => 'Blue gadget'], ['name' => 'Renamed']);

        $this->assertSame(
            ['map' => 'widgets', 'source' => $source, 'destination' => $destination, 'events' => $events, 'rowBefore' => ['name' => 'Blue gadget'], 'rowAfter' => ['name' => 'Renamed']],
            get_object_vars($recordMirrored),
        );
    }
}
