<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(PlannerIndex::class)]
final class PlannerIndexTest extends TestCase
{
    #[Test]
    public function it_names_the_index_with_no_column_and_no_filter(): void
    {
        $this->assertSame(['name' => 'archived_newest', 'columns' => [], 'whereNull' => null, 'whereNotNull' => null], get_object_vars(PlannerIndex::named('archived_newest')));
    }

    #[Test]
    public function it_adds_each_column_on_a_copy_in_order_and_ascending_unless_it_says_descending(): void
    {
        $original = PlannerIndex::named('archived_newest')->on('is_archived')->whereNull('deleted_at');

        $indexed = $original->on('created_at', descending: true)->on('title');

        $this->assertSame(['name' => 'archived_newest', 'columns' => ['is_archived' => false, 'created_at' => true, 'title' => false], 'whereNull' => 'deleted_at', 'whereNotNull' => null], get_object_vars($indexed));
        $this->assertSame(['is_archived' => false], $original->columns);
    }

    #[Test]
    public function it_keeps_one_column_per_attribute_at_its_first_place_with_the_direction_said_last(): void
    {
        $indexed = PlannerIndex::named('archived_newest')->on('created_at')->on('is_archived')->on('created_at', descending: true);

        $this->assertSame(['created_at' => true, 'is_archived' => false], $indexed->columns);
    }

    #[Test]
    public function it_filters_on_a_copy_on_one_attribute_and_replaces_an_earlier_filter(): void
    {
        $original = PlannerIndex::named('archived_newest')->on('created_at');

        $null = $original->whereNull('deleted_at');
        $notNull = $null->whereNotNull('delivered_at');
        $nullAgain = $notNull->whereNull('updated_at');

        $this->assertSame(
            [[null, null], ['deleted_at', null], [null, 'delivered_at'], ['updated_at', null]],
            array_map(fn (PlannerIndex $index) => [$index->whereNull, $index->whereNotNull], [$original, $null, $notNull, $nullAgain]),
        );
        $this->assertSame([['created_at' => false], ['created_at' => false]], [$null->columns, $nullAgain->columns]);
    }
}
