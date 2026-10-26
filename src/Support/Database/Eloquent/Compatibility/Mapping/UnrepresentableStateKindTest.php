<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(UnrepresentableStateKind::class)]
final class UnrepresentableStateKindTest extends TestCase
{
    #[Test]
    public function it_names_each_kind_by_its_value(): void
    {
        $this->assertSame(
            ['Refuse' => 'refuse', 'Degrade' => 'degrade', 'Hide' => 'hide'],
            array_column(array_map(fn (UnrepresentableStateKind $kind) => ['name' => $kind->name, 'value' => $kind->value], UnrepresentableStateKind::cases()), 'value', 'name'),
        );
    }
}
