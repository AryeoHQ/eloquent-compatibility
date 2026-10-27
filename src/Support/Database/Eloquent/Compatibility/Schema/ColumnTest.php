<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

#[CoversClass(Column::class)]
final class ColumnTest extends TestCase
{
    #[Test]
    public function it_carries_the_type_default_and_nullability_it_was_given(): void
    {
        $column = new Column('character varying(255)', "'Draft'::character varying", true);

        $this->assertSame(['character varying(255)', "'Draft'::character varying", true, false], [$column->type, $column->default, $column->notNull, $column->generated]);
    }

    #[Test]
    #[TestWith([null, true, false, true])]
    #[TestWith(["'Draft'::character varying", true, false, false])]
    #[TestWith([null, true, true, false])]
    #[TestWith([null, false, false, false])]
    public function it_needs_a_value_from_every_insert_only_when_it_is_not_null_without_a_default_and_not_generated(null|string $default, bool $notNull, bool $generated, bool $needsAValue): void
    {
        $this->assertSame($needsAValue, (new Column('text', $default, $notNull, $generated))->needsAValue());
    }
}
