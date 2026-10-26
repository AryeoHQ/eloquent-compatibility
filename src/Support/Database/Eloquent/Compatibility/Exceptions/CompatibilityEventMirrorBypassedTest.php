<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(CompatibilityEventMirrorBypassed::class)]
final class CompatibilityEventMirrorBypassedTest extends TestCase
{
    #[Test]
    public function it_names_the_relation_the_faked_dispatcher_bypassed_and_its_compatibility_map(): void
    {
        $bypassed = CompatibilityEventMirrorBypassed::byFakedDispatcher('widgets', ['widgets']);

        $this->assertSame(
            "A write to 'widgets' reached the database without its event mirror, because Event::fake() replaced the model event dispatcher. "
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

        $this->assertStringStartsWith("A write to 'gadgets' reached the database", $bypassed->getMessage());
        $this->assertStringContainsString("Why: compatibility map 'widgets', 'gizmos' raises", $bypassed->getMessage());
    }
}
