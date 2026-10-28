<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\TestCase;

#[CoversClass(AffectedRecord::class)]
final class AffectedRecordTest extends TestCase
{
    #[Test]
    public function it_carries_the_record_it_was_given(): void
    {
        $record = new AffectedRecord('widgets', Widget::class, 'widgets', 'retained_widgets', 'id', 7, RecordRole::Previous);

        $this->assertSame(
            ['map' => 'widgets', 'model' => Widget::class, 'readFrom' => 'widgets', 'lockedTable' => 'retained_widgets', 'keyColumn' => 'id', 'keyValue' => 7, 'role' => RecordRole::Previous],
            get_object_vars($record),
        );
    }

    #[Test]
    public function it_identifies_a_record_by_its_relation_and_key(): void
    {
        $previous = new AffectedRecord('widgets', Widget::class, 'widgets', 'retained_widgets', 'id', 7, RecordRole::Previous);
        $primary = new AffectedRecord('widgets', Gadget::class, 'gadgets', 'gadgets', 'id', '0b4c5d6e-0000-4000-8000-000000000007', RecordRole::Primary);

        $this->assertSame('widgets#7', $previous->id());
        $this->assertSame('gadgets#0b4c5d6e-0000-4000-8000-000000000007', $primary->id());
    }

    #[Test]
    public function it_gives_one_id_to_a_record_that_two_maps_reach_and_separate_ids_to_one_key_in_two_relations(): void
    {
        $throughWidgets = new AffectedRecord('widgets', Gadget::class, 'gadgets', 'gadgets', 'id', 7, RecordRole::Primary);
        $throughAnotherMap = new AffectedRecord('catalog', Gadget::class, 'gadgets', 'gadgets', 'id', '7', RecordRole::Dependent);
        $sameKeyElsewhere = new AffectedRecord('widgets', Widget::class, 'widgets', 'retained_widgets', 'id', 7, RecordRole::Previous);

        $this->assertSame($throughWidgets->id(), $throughAnotherMap->id());
        $this->assertNotSame($throughWidgets->id(), $sameKeyElsewhere->id());
    }
}
