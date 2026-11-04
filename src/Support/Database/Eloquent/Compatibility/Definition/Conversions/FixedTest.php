<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\Support\Listings\ListingStatus;
use Tests\TestCase;

#[CoversClass(Fixed::class)]
final class FixedTest extends TestCase
{
    #[Test]
    #[TestWith([null, 'NULL'])]
    #[TestWith([true, 'true'])]
    #[TestWith([false, 'false'])]
    #[TestWith([42, '42'])]
    #[TestWith([-7, '-7'])]
    #[TestWith([2.5, '2.5'])]
    #[TestWith(["it's", "'it''s'"])]
    public function it_reads_its_value_as_a_literal_in_place_of_the_stored_column(null|bool|int|float|string $value, string $sql): void
    {
        $this->assertSame($sql, (new Fixed($value))->read('"files"."url"'));
    }

    #[Test]
    #[TestWith([null])]
    #[TestWith([true])]
    #[TestWith([42])]
    #[TestWith(["it's"])]
    public function it_reads_back_through_postgresql_the_value_it_holds(null|bool|int|string $value): void
    {
        $this->assertSame($value, DB::scalar('select '.(new Fixed($value))->read('')));
    }

    #[Test]
    public function it_reads_an_enum_case_as_its_value(): void
    {
        $this->assertSame("'Sold'", (new Fixed(ListingStatus::Sold))->read(''));
    }

    #[Test]
    public function it_accepts_a_write_of_its_own_value(): void
    {
        $this->assertSame([null, 'Sold'], [(new Fixed(null))->write(null), (new Fixed('Sold'))->write(ListingStatus::Sold)]);
    }

    #[Test]
    public function it_refuses_a_write_of_another_value(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException("The attribute always reads NULL, so it cannot store 'https://example.com'."));

        (new Fixed(null))->write('https://example.com');
    }

    #[Test]
    public function it_cannot_filter_by_a_stored_column(): void
    {
        $this->assertNull((new Fixed(null))->filter('=', null));
    }
}
