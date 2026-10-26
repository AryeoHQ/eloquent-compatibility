<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Mapping\ExcludedListener;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Tests\TestCase;

#[CoversClass(Exclusion::class)]
final class ExclusionTest extends TestCase
{
    #[Test]
    public function it_carries_the_event_and_the_listeners_it_was_given(): void
    {
        $listeners = [
            ExcludedListener::of('App\Listeners\RecordGadgetHistory', 'The widget keeps its own history.'),
            ExcludedListener::of('App\Listeners\NotifyGadgetOwner@handle', 'Widget owners are notified by the widget.')->forEvent(ModelEvent::Updating),
        ];

        $exclusion = new Exclusion(ModelEvent::Updating, $listeners);

        $this->assertSame(['event' => ModelEvent::Updating, 'listeners' => $listeners], get_object_vars($exclusion));
    }
}
