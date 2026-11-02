<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

#[CoversClass(MultipliedBy::class)]
final class MultipliedByTest extends TestCase
{
    #[Test]
    public function it_reads_the_stored_value_multiplied(): void
    {
        $conversion = new MultipliedBy(1000);

        $this->assertSame('("job_sites"."area_km2" * CAST(1000 AS numeric))', $conversion->read('"job_sites"."area_km2"'));
        $this->assertSame('2000', DB::scalar('select '.$conversion->read('CAST(? AS integer)'), [2]));
    }

    #[Test]
    #[TestWith([2000, 2])]
    #[TestWith([2500, 2.5])]
    #[TestWith(['3000', 3])]
    #[TestWith([null, null])]
    public function it_writes_the_legacy_value_divided_and_keeps_a_whole_result_an_integer(null|int|string $legacy, null|int|float $stored): void
    {
        $this->assertSame($stored, (new MultipliedBy(1000))->write($legacy));
    }

    #[Test]
    #[TestWith([2000])]
    #[TestWith([7000])]
    #[TestWith([-4000])]
    public function it_reads_back_through_postgresql_the_value_it_writes(int $legacy): void
    {
        $conversion = new MultipliedBy(1000);

        $this->assertSame((string) $legacy, DB::scalar('select '.$conversion->read('CAST(? AS integer)'), [$conversion->write($legacy)]));
    }

    #[Test]
    public function it_refuses_to_write_a_value_that_is_not_a_number(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('true is not a number, so it cannot be divided by 1000.'));

        (new MultipliedBy(1000))->write(true);
    }

    /**
     * @param  null|array{string, mixed}  $filter
     */
    #[Test]
    #[TestWith([1000, '<', 5000, ['<', 5]])]
    #[TestWith([1000, '<>', 5000, ['<>', 5]])]
    #[TestWith([-10, '<', 50, ['>', -5]])]
    #[TestWith([-10, '<=', 50, ['>=', -5]])]
    #[TestWith([1000, 'ilike', '5%', null])]
    public function it_filters_the_stored_column_with_the_stored_value_and_flips_a_range_for_a_negative_factor(int $factor, string $operator, int|string $value, null|array $filter): void
    {
        $this->assertSame($filter, (new MultipliedBy($factor))->filter($operator, $value));
    }

    #[Test]
    #[TestWith([1000, 'desc', 'desc'])]
    #[TestWith([-10, 'ASC', 'desc'])]
    public function it_orders_the_stored_column_in_the_same_direction_unless_the_factor_is_negative(int $factor, string $direction, string $stored): void
    {
        $this->assertSame($stored, (new MultipliedBy($factor))->order($direction));
    }
}
