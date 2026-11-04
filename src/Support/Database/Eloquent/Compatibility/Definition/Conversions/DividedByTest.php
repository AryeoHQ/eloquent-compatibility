<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

#[CoversClass(DividedBy::class)]
final class DividedByTest extends TestCase
{
    #[Test]
    public function it_reads_the_stored_value_divided_as_a_numeric_so_postgresql_keeps_the_fraction(): void
    {
        $conversion = new DividedBy(100);

        $this->assertSame('(CAST("jobs"."price_cents" AS numeric) / 100)', $conversion->read('"jobs"."price_cents"'));
        $this->assertSame('4500.55', DB::scalar('select round('.$conversion->read('CAST(? AS integer)').', 2)::text', [450055]));
    }

    #[Test]
    #[TestWith(['4500.55', 450055])]
    #[TestWith([12, 1200])]
    #[TestWith(['0.07', 7])]
    #[TestWith(['4500.555', 450055.5])]
    #[TestWith([null, null])]
    public function it_writes_the_legacy_value_multiplied_and_keeps_a_whole_result_an_integer(null|int|string $legacy, null|int|float $stored): void
    {
        $this->assertSame($stored, (new DividedBy(100))->write($legacy));
    }

    #[Test]
    #[TestWith(['4500.55'])]
    #[TestWith(['0.01'])]
    #[TestWith(['19.99'])]
    #[TestWith(['1234567.89'])]
    public function it_reads_back_through_postgresql_the_value_it_writes(string $legacy): void
    {
        $conversion = new DividedBy(100);

        $this->assertSame($legacy, DB::scalar('select round('.$conversion->read('CAST(? AS bigint)').', 2)::text', [$conversion->write($legacy)]));
    }

    #[Test]
    public function it_refuses_to_write_a_value_that_is_not_a_number(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException("'cheap' is not a number, so it cannot be multiplied by 100."));

        (new DividedBy(100))->write('cheap');
    }

    /**
     * @param  null|array{string, mixed}  $filter
     */
    #[Test]
    #[TestWith([100, '>', '4500.55', ['>', 450055]])]
    #[TestWith([100, '<=', 3, ['<=', 300]])]
    #[TestWith([100, '!=', 3, ['!=', 300]])]
    #[TestWith([-1, '>', 5, ['<', -5]])]
    #[TestWith([-1, '>=', 5, ['<=', -5]])]
    #[TestWith([-1, '=', 5, ['=', -5]])]
    #[TestWith([100, 'like', '45%', null])]
    public function it_filters_the_stored_column_with_the_stored_value_and_flips_a_range_for_a_negative_divisor(int $divisor, string $operator, int|string $value, null|array $filter): void
    {
        $this->assertSame($filter, (new DividedBy($divisor))->filter($operator, $value));
    }
}
