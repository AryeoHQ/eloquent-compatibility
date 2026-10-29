<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(PlannerIndexFilter::class)]
final class PlannerIndexFilterTest extends TestCase
{
    #[Test]
    public function it_names_each_filter_by_the_planner_index_method_that_declares_it_and_compiles_it_to_its_predicate(): void
    {
        $this->assertSame(
            ['WhereNull' => ['whereNull', 'IS NULL'], 'WhereNotNull' => ['whereNotNull', 'IS NOT NULL']],
            array_combine(
                array_map(fn (PlannerIndexFilter $filter) => $filter->name, PlannerIndexFilter::cases()),
                array_map(fn (PlannerIndexFilter $filter) => [$filter->value, $filter->predicate()], PlannerIndexFilter::cases()),
            ),
        );
    }
}
