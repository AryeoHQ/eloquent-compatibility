<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\Support\Listings\ListingStatus;
use Tests\TestCase;

#[CoversClass(OrDefault::class)]
final class OrDefaultTest extends TestCase
{
    #[Test]
    public function it_reads_the_default_where_the_stored_value_is_null(): void
    {
        $conversion = new OrDefault('Unknown');

        $this->assertSame("COALESCE(\"places\".\"city\", 'Unknown')", $conversion->read('"places"."city"'));
        $this->assertSame(['Unknown', 'Seattle'], [
            DB::scalar('select '.$conversion->read('CAST(? AS text)'), [null]),
            DB::scalar('select '.$conversion->read('CAST(? AS text)'), ['Seattle']),
        ]);
    }

    #[Test]
    public function it_reads_an_enum_default_as_its_value(): void
    {
        $this->assertSame("COALESCE(\"listings_retained\".\"status\", 'Draft')", (new OrDefault(ListingStatus::Draft))->read('"listings_retained"."status"'));
    }

    #[Test]
    public function it_writes_the_legacy_value_unchanged(): void
    {
        $this->assertSame(['Seattle', null], [(new OrDefault('Unknown'))->write('Seattle'), (new OrDefault('Unknown'))->write(null)]);
    }

    /**
     * @param  null|array{string, mixed}  $filter
     */
    #[Test]
    #[TestWith(['=', 'Seattle', ['=', 'Seattle']])]
    #[TestWith(['=', 'Unknown', null])]
    #[TestWith(['!=', 'Seattle', ['is distinct from', 'Seattle']])]
    #[TestWith(['!=', 'Unknown', ['!=', 'Unknown']])]
    #[TestWith(['<', 'Seattle', null])]
    #[TestWith(['=', null, ['none', null]])]
    #[TestWith(['!=', null, ['all', null]])]
    public function it_filters_equality_with_a_value_other_than_the_default_and_any_inequality(string $operator, null|string $value, null|array $filter): void
    {
        $this->assertSame($filter, (new OrDefault('Unknown'))->filter($operator, $value, 'text'));
    }

    #[Test]
    public function it_treats_a_number_written_as_text_as_the_default_it_equals(): void
    {
        $this->assertNull((new OrDefault(0))->filter('=', '0', 'integer'));
    }

    #[Test]
    public function it_cannot_order_by_the_stored_column(): void
    {
        $this->assertNull((new OrDefault(0))->order('asc'));
    }
}
