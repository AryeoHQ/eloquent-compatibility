<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Tests\TestCase;

#[CoversClass(CompatibilityEventMirrorBypassed::class)]
final class CompatibilityEventMirrorBypassedTest extends TestCase
{
    #[Test]
    public function it_names_the_relation_whose_write_the_faked_dispatcher_stopped_and_its_compatibility_map(): void
    {
        $bypassed = CompatibilityEventMirrorBypassed::byFakedDispatcher('widgets', ['widgets']);

        $this->assertSame(
            "A write to 'widgets' was stopped before it reached the database, because Event::fake() replaced the model event dispatcher and its event mirror could not run. "
            ."Why: compatibility map 'widgets' raises the other side's model events from this model's events, so a faked dispatcher hides every listener of the other side and the test proves nothing about compatibility. "
            .'Instead: fake only the events the test asserts, such as Event::fake([OrderShipped::class]), or wrap the write in EventBridge::withoutMirroring() to switch the mirror off on purpose.',
            $bypassed->getMessage(),
        );
        $this->assertInstanceOf(CompatibilityException::class, $bypassed);
    }

    #[Test]
    public function it_names_every_compatibility_map_of_the_relation(): void
    {
        $bypassed = CompatibilityEventMirrorBypassed::byFakedDispatcher('gadgets', ['widgets', 'gizmos']);

        $this->assertStringStartsWith("A write to 'gadgets' was stopped before it reached the database", $bypassed->getMessage());
        $this->assertStringContainsString("Why: compatibility map 'widgets', 'gizmos' raises", $bypassed->getMessage());
    }

    #[Test]
    public function it_names_the_model_and_the_event_of_a_write_whose_statement_carried_no_mark(): void
    {
        $bypassed = CompatibilityEventMirrorBypassed::byUnmarkedStatement('App\\Models\\Widget', ModelEvent::Updated, ['widgets', 'gizmos']);

        $this->assertSame(
            "The App\\Models\\Widget write that fired 'updated' reached the database on a statement HasCompatibility never marked, so the event bridge mirrored none of it. "
            ."Why: compatibility map 'widgets', 'gizmos' mirrors a write only on the statement HasCompatibility marks from setKeysForSaveQuery() or getAttributesForInsert(), and this write's method called neither. "
            .'Instead: write the model through the Eloquent method that calls them, or wrap the write in EventBridge::withoutMirroring() to switch the mirror off on purpose.',
            $bypassed->getMessage(),
        );
        $this->assertInstanceOf(CompatibilityException::class, $bypassed);
    }
}
