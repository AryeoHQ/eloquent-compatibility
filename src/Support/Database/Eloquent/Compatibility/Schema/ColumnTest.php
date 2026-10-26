<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(Column::class)]
final class ColumnTest extends TestCase
{
    #[Test]
    public function it_carries_the_type_default_and_nullability_it_was_given(): void
    {
        $column = new Column('character varying(255)', "'Draft'::character varying", true);

        $this->assertSame(['character varying(255)', "'Draft'::character varying", true], [$column->type, $column->default, $column->notNull]);
    }
}
