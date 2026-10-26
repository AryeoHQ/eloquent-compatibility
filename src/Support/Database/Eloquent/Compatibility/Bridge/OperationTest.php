<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

#[CoversClass(Operation::class)]
final class OperationTest extends TestCase
{
    #[Test]
    public function it_backs_each_operation_with_the_sql_verb_that_starts_its_statement(): void
    {
        $this->assertSame(['Insert' => 'insert', 'Update' => 'update', 'Delete' => 'delete'], array_column(Operation::cases(), 'value', 'name'));
    }

    #[Test]
    #[TestWith([Operation::Insert, Operation::Insert, true])]
    #[TestWith([Operation::Insert, Operation::Update, false])]
    #[TestWith([Operation::Insert, Operation::Delete, false])]
    #[TestWith([Operation::Update, Operation::Insert, false])]
    #[TestWith([Operation::Update, Operation::Update, true])]
    #[TestWith([Operation::Update, Operation::Delete, false])]
    #[TestWith([Operation::Delete, Operation::Insert, false])]
    #[TestWith([Operation::Delete, Operation::Update, true])]
    #[TestWith([Operation::Delete, Operation::Delete, true])]
    public function it_accepts_its_own_operation_and_a_delete_accepts_the_update_of_a_soft_delete(Operation $pending, Operation $executed, bool $accepted): void
    {
        $this->assertSame($accepted, $pending->accepts($executed));
    }
}
