<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

#[CoversClass(RecordRole::class)]
final class RecordRoleTest extends TestCase
{
    #[Test]
    public function it_names_the_primary_previous_and_dependent_roles(): void
    {
        $this->assertSame(['Primary', 'Previous', 'Dependent'], array_map(fn (RecordRole $role) => $role->name, RecordRole::cases()));
    }

    #[Test]
    #[TestWith([RecordRole::Primary, 0])]
    #[TestWith([RecordRole::Previous, 1])]
    #[TestWith([RecordRole::Dependent, 2])]
    public function it_locks_the_primary_record_first_then_the_previous_record_then_the_dependent_records(RecordRole $role, int $lockOrder): void
    {
        $this->assertSame($lockOrder, $role->lockOrder());
    }
}
