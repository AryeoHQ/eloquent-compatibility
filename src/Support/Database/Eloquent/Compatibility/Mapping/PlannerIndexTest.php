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
        $this->assertSame(['name' => 'archived_newest', 'columns' => [], 'filters' => []], get_object_vars(PlannerIndex::named('archived_newest')));
    }

    #[Test]
    public function it_adds_each_column_on_a_copy_in_order_and_ascending_unless_it_says_descending(): void
    {
        $original = PlannerIndex::named('archived_newest')->on('is_archived')->whereNull('deleted_at');

        $indexed = $original->on('created_at', descending: true)->on('title');

        $this->assertSame([
            'name' => 'archived_newest',
            'columns' => [['attribute' => 'is_archived', 'descending' => false], ['attribute' => 'created_at', 'descending' => true], ['attribute' => 'title', 'descending' => false]],
            'filters' => [['filter' => PlannerIndexFilter::WhereNull, 'attribute' => 'deleted_at']],
        ], get_object_vars($indexed));
        $this->assertSame([['attribute' => 'is_archived', 'descending' => false]], $original->columns);
    }

    #[Test]
    public function it_records_a_repeated_column_as_a_second_call(): void
    {
        $indexed = PlannerIndex::named('archived_newest')->on('created_at')->on('is_archived')->on('created_at', descending: true);

        $this->assertSame(
            [['attribute' => 'created_at', 'descending' => false], ['attribute' => 'is_archived', 'descending' => false], ['attribute' => 'created_at', 'descending' => true]],
            $indexed->columns,
        );
    }

    #[Test]
    public function it_records_each_filter_on_a_copy_in_order_and_keeps_the_earlier_ones(): void
    {
        $original = PlannerIndex::named('archived_newest')->on('created_at');

        $null = $original->whereNull('deleted_at');
        $notNull = $null->whereNotNull('delivered_at');
        $nullAgain = $notNull->whereNull('updated_at');

        $this->assertSame(
            [
                [],
                [['filter' => PlannerIndexFilter::WhereNull, 'attribute' => 'deleted_at']],
                [['filter' => PlannerIndexFilter::WhereNull, 'attribute' => 'deleted_at'], ['filter' => PlannerIndexFilter::WhereNotNull, 'attribute' => 'delivered_at']],
                [['filter' => PlannerIndexFilter::WhereNull, 'attribute' => 'deleted_at'], ['filter' => PlannerIndexFilter::WhereNotNull, 'attribute' => 'delivered_at'], ['filter' => PlannerIndexFilter::WhereNull, 'attribute' => 'updated_at']],
            ],
            array_map(fn (PlannerIndex $index) => $index->filters, [$original, $null, $notNull, $nullAgain]),
        );
        $this->assertSame([[['attribute' => 'created_at', 'descending' => false]], [['attribute' => 'created_at', 'descending' => false]]], [$null->columns, $nullAgain->columns]);
    }
}
