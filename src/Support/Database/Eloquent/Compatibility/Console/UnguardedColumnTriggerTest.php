<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(UnguardedColumnTrigger::class)]
final class UnguardedColumnTriggerTest extends TestCase
{
    #[Test]
    public function it_carries_what_it_was_given(): void
    {
        $trigger = new UnguardedColumnTrigger('widgets', 'gadgets', 'audit_price', ['price_cents', 'archived']);

        $this->assertSame(
            ['map' => 'widgets', 'table' => 'gadgets', 'trigger' => 'audit_price', 'columns' => ['price_cents', 'archived']],
            get_object_vars($trigger),
        );
    }
}
