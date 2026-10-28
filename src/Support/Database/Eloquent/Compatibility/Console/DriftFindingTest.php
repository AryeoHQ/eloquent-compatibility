<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(DriftFinding::class)]
final class DriftFindingTest extends TestCase
{
    #[Test]
    public function it_carries_what_it_was_given(): void
    {
        $finding = new DriftFinding('widgets', DriftCheck::OrphanCurrent, 'gadgets#1', 'retained_widgets.gadget_id', 'no retained row links it');

        $this->assertSame(
            ['map' => 'widgets', 'check' => DriftCheck::OrphanCurrent, 'recordId' => 'gadgets#1', 'attribute' => 'retained_widgets.gadget_id', 'detail' => 'no retained row links it'],
            get_object_vars($finding),
        );
    }
}
