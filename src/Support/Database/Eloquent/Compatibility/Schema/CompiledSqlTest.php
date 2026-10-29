<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(CompiledSql::class)]
final class CompiledSqlTest extends TestCase
{
    #[Test]
    public function it_carries_the_view_function_trigger_and_planner_statements_it_was_given(): void
    {
        $compiledSql = new CompiledSql(['create view', 'alter view'], ['create function'], ['create trigger', 'create other trigger'], ['create statistics', 'analyze']);

        $this->assertSame(
            [['create view', 'alter view'], ['create function'], ['create trigger', 'create other trigger'], ['create statistics', 'analyze']],
            [$compiledSql->view, $compiledSql->functions, $compiledSql->triggers, $compiledSql->planner],
        );
    }

    #[Test]
    public function it_lists_the_view_statements_then_the_functions_then_the_triggers_then_the_planner_statements_in_their_order(): void
    {
        $compiledSql = new CompiledSql(['create view', 'alter view'], ['create function', 'create other function'], ['create trigger'], ['create statistics', 'create index', 'analyze']);

        $this->assertSame(['create view', 'alter view', 'create function', 'create other function', 'create trigger', 'create statistics', 'create index', 'analyze'], $compiledSql->statements());
    }

    #[Test]
    public function it_lists_no_statement_for_a_part_that_holds_none(): void
    {
        $this->assertSame(['create view'], (new CompiledSql(['create view'], [], [], []))->statements());
        $this->assertSame(['create view', 'analyze'], (new CompiledSql(['create view'], [], [], ['analyze']))->statements());
        $this->assertSame([], (new CompiledSql([], [], [], []))->statements());
    }
}
