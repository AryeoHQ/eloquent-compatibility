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

#[CoversClass(Mapping::class)]
final class MappingTest extends TestCase
{
    #[Test]
    public function it_reads_each_stored_value_as_its_legacy_value_and_an_unmapped_one_as_null(): void
    {
        $mapping = $this->statuses();

        $this->assertSame("(CASE CAST(\"jobs\".\"lifecycle_status\" AS text) WHEN 'drafting' THEN 'Draft' WHEN 'live' THEN 'Active' WHEN 'closed' THEN 'Sold' END)", $mapping->read('"jobs"."lifecycle_status"'));
        $this->assertSame(
            ['Draft', 'Active', 'Sold', null],
            array_map(fn (string $stored) => DB::scalar('select '.$mapping->read('CAST(? AS text)'), [$stored]), ['drafting', 'live', 'closed', 'archived']),
        );
    }

    #[Test]
    public function it_compares_an_integer_stored_value_as_text(): void
    {
        $this->assertSame("(CASE CAST(\"places\".\"kind\" AS text) WHEN '1' THEN 'Root' WHEN '2' THEN 'Unit' END)", (new Mapping([1 => 'Root', 2 => 'Unit']))->read('"places"."kind"'));
        $this->assertSame([['=', 1], ['=', '1']], [(new Mapping([1 => 'Root', 2 => 'Unit']))->filter('=', 'Root', 'integer'), (new Mapping([1 => 'Root', 2 => 'Unit']))->filter('=', 'Root', 'text')]);
    }

    #[Test]
    #[TestWith(['Draft', 'drafting'])]
    #[TestWith(['Sold', 'closed'])]
    #[TestWith([null, null])]
    public function it_writes_the_stored_value_a_legacy_value_maps_from(null|string $legacy, null|string $stored): void
    {
        $this->assertSame($stored, $this->statuses()->write($legacy));
    }

    #[Test]
    public function it_writes_the_stored_value_a_legacy_enum_case_maps_from(): void
    {
        $this->assertSame('live', $this->statuses()->write(ListingStatus::Active));
    }

    #[Test]
    public function it_refuses_to_write_a_legacy_value_no_stored_value_maps_to(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException("mapping() maps no stored value to 'Withdrawn'."));

        $this->statuses()->write('Withdrawn');
    }

    /**
     * @param  null|array{string, mixed}  $filter
     */
    #[Test]
    #[TestWith(['=', 'Active', ['=', 'live']])]
    #[TestWith(['!=', 'Sold', ['in', ['drafting', 'live']]])]
    #[TestWith(['<>', 'Draft', ['in', ['live', 'closed']]])]
    #[TestWith(['!=', 'Withdrawn', ['in', ['drafting', 'live', 'closed']]])]
    #[TestWith(['<', 'Sold', null])]
    #[TestWith(['=', 'Withdrawn', null])]
    #[TestWith(['=', null, null])]
    public function it_filters_equality_by_the_stored_value_and_inequality_by_the_other_mapped_values(string $operator, null|string $value, null|array $filter): void
    {
        $this->assertSame($filter, $this->statuses()->filter($operator, $value));
    }

    #[Test]
    public function it_filters_by_a_legacy_enum_case(): void
    {
        $this->assertSame(['=', 'closed'], $this->statuses()->filter('=', ListingStatus::Sold));
    }

    #[Test]
    public function it_cannot_order_by_the_stored_column(): void
    {
        $this->assertNull($this->statuses()->order('asc'));
    }

    private function statuses(): Mapping
    {
        return new Mapping(['drafting' => ListingStatus::Draft, 'live' => ListingStatus::Active, 'closed' => 'Sold']);
    }
}
