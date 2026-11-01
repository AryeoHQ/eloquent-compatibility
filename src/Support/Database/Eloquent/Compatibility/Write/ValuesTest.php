<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

#[CoversClass(Values::class)]
final class ValuesTest extends TestCase
{
    #[Test]
    #[TestWith([null, null, null, true])]
    #[TestWith([null, '', 'text', false])]
    #[TestWith(['1', '001', 'character varying(255)', false])]
    #[TestWith(['1', '001', 'integer', true])]
    #[TestWith(['47.600000000000', 47.6, 'numeric(24,12)', true])]
    #[TestWith(['47.600000000000001', 47.6, 'numeric(24,12)', false])]
    #[TestWith([true, 't', 'boolean', true])]
    #[TestWith([false, '1', 'boolean', false])]
    #[TestWith(['{"a":1,"b":2}', '{"b": 2, "a": 1}', 'jsonb', true])]
    #[TestWith(['2026-11-01 06:18:58+00', '2026-11-01 06:18:58', 'timestamp with time zone', true])]
    #[TestWith(['2026-11-01 06:18:58', '2026-11-01 06:18:59', 'timestamp(0) without time zone', false])]
    public function it_compares_two_stored_values_as_the_column_type_does(mixed $a, mixed $b, null|string $type, bool $same): void
    {
        $this->assertSame($same, Values::same($a, $b, $type));
        $this->assertSame($same, Values::same($b, $a, $type));
    }

    #[Test]
    public function it_compares_a_date_object_with_its_stored_text(): void
    {
        $this->assertTrue(Values::same(Carbon::parse('2026-11-01 06:18:58', 'UTC'), '2026-11-01 06:18:58', 'timestamp(0) without time zone'));
    }

    #[Test]
    public function it_adds_an_amount_exactly_to_an_integer_and_as_a_float_otherwise(): void
    {
        $this->assertSame([12, 9_223_372_036_854_775_807, 3.5, 2], [Values::add('10', 2), Values::add(9_223_372_036_854_775_806, 1), Values::add('1.5', 2), Values::add(null, 2)]);
    }
}
