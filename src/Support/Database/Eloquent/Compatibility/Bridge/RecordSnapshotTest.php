<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\TestCase;

#[CoversClass(RecordSnapshot::class)]
final class RecordSnapshotTest extends TestCase
{
    #[Test]
    public function it_carries_the_record_and_the_row_it_was_given(): void
    {
        $record = $this->record();

        $snapshot = new RecordSnapshot($record, ['id' => 7, 'title' => 'Blue gadget']);

        $this->assertSame(['record' => $record, 'row' => ['id' => 7, 'title' => 'Blue gadget']], get_object_vars($snapshot));
    }

    #[Test]
    public function it_takes_a_new_row_for_the_same_record_and_leaves_itself_unchanged(): void
    {
        $record = $this->record();
        $before = new RecordSnapshot($record, null);

        $after = $before->withRow(['id' => 7, 'title' => 'Renamed']);

        $this->assertNotSame($before, $after);
        $this->assertSame($record, $after->record);
        $this->assertSame(['id' => 7, 'title' => 'Renamed'], $after->row);
        $this->assertNull($before->row);
    }

    #[Test]
    public function it_takes_no_row_for_a_record_that_no_longer_exists(): void
    {
        $snapshot = new RecordSnapshot($this->record(), ['id' => 7, 'title' => 'Blue gadget']);

        $this->assertNull($snapshot->withRow(null)->row);
    }

    private function record(): AffectedRecord
    {
        return new AffectedRecord('widgets', Widget::class, 'widgets', 'retained_widgets', 'id', 7, RecordRole::Previous);
    }
}
