<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(TargetColumn::class)]
final class TargetColumnTest extends TestCase
{
    #[Test]
    public function it_carries_the_alias_column_and_value_it_was_given(): void
    {
        $value = fn (array $widget) => (int) round((float) $widget['price'] * 100);
        $targetColumn = new TargetColumn('gadget', 'price_cents', $value);

        $this->assertSame(['alias' => 'gadget', 'column' => 'price_cents', 'value' => $value, 'copiedFrom' => null], get_object_vars($targetColumn));
        $this->assertSame(1250, $targetColumn->value(['price' => '12.50']));
    }

    #[Test]
    public function it_copies_one_attribute_unchanged(): void
    {
        $targetColumn = TargetColumn::copy('gadget', 'name', 'title');

        $this->assertSame(['gadget', 'name', 'title'], [$targetColumn->alias, $targetColumn->column, $targetColumn->copiedFrom]);
        $this->assertSame('Blue', $targetColumn->value(['title' => 'Blue']));
        $this->assertNull($targetColumn->value([]));
    }
}
