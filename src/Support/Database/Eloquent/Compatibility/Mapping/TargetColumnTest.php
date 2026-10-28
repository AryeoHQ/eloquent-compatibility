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
    public function it_carries_the_alias_column_and_expression_it_was_given(): void
    {
        $targetColumn = new TargetColumn('gadget', 'price_cents', 'round(NEW.price::numeric * 100)');

        $this->assertSame(['alias' => 'gadget', 'column' => 'price_cents', 'expression' => 'round(NEW.price::numeric * 100)'], get_object_vars($targetColumn));
    }
}
