<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(Assignment::class)]
final class AssignmentTest extends TestCase
{
    #[Test]
    public function it_carries_the_attribute_and_target_column_it_was_given(): void
    {
        $attribute = Attribute::mapped('title')->asType('text')->storedAs('gadget', 'name');
        $targetColumn = new TargetColumn('gadget', 'name', 'NEW."title"');

        $assignment = new Assignment($attribute, $targetColumn);

        $this->assertSame(['attribute' => $attribute, 'targetColumn' => $targetColumn], get_object_vars($assignment));
    }
}
