<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

#[CoversClass(InTimeZone::class)]
final class InTimeZoneTest extends TestCase
{
    #[Test]
    public function it_reads_a_stored_instant_as_the_wall_clock_time_in_the_zone(): void
    {
        $conversion = new InTimeZone('America/New_York');

        $this->assertSame("(\"jobs\".\"starts_at\" AT TIME ZONE 'America/New_York')", $conversion->read('"jobs"."starts_at"'));
        $this->assertSame('2026-07-01 12:00:00', DB::scalar('select '.$conversion->read('CAST(? AS timestamptz)').'::text', ['2026-07-01 16:00:00+00']));
    }

    #[Test]
    #[TestWith(['2026-07-01 12:00:00', '2026-07-01T16:00:00+00:00'])]
    #[TestWith(['2026-01-15 09:30:00', '2026-01-15T14:30:00+00:00'])]
    public function it_writes_a_wall_clock_time_in_the_zone_as_the_instant_in_the_application_zone(string $legacy, string $stored): void
    {
        $written = (new InTimeZone('America/New_York'))->write($legacy);

        $this->assertInstanceOf(DateTimeInterface::class, $written);
        $this->assertSame($stored, $written->format(DATE_ATOM));
    }

    #[Test]
    public function it_writes_a_date_time_by_its_wall_clock_and_ignores_its_own_zone(): void
    {
        $written = (new InTimeZone('America/New_York'))->write(new DateTimeImmutable('2026-01-15 09:30:00', new DateTimeZone('Asia/Tokyo')));

        $this->assertInstanceOf(DateTimeInterface::class, $written);
        $this->assertSame('2026-01-15T14:30:00+00:00', $written->format(DATE_ATOM));
    }

    #[Test]
    public function it_reads_back_through_postgresql_the_value_it_writes(): void
    {
        $conversion = new InTimeZone('America/New_York');
        $written = $conversion->write('2026-03-08 01:59:00');

        $this->assertInstanceOf(DateTimeInterface::class, $written);
        $this->assertSame('2026-03-08 01:59:00', DB::scalar('select '.$conversion->read('CAST(? AS timestamptz)').'::text', [$written->format('Y-m-d H:i:sP')]));
    }

    #[Test]
    public function it_writes_null_as_null_and_refuses_a_value_that_is_no_time(): void
    {
        $this->assertNull((new InTimeZone('UTC'))->write(null));

        $this->expectExceptionObject(new InvalidArgumentException('12 is not a date and time in UTC.'));

        (new InTimeZone('UTC'))->write(12);
    }

    #[Test]
    #[TestWith(['>', '2026-07-01T16:00:00+00:00'])]
    #[TestWith(['<=', '2026-07-01T16:00:00+00:00'])]
    #[TestWith(['=', '2026-07-01T16:00:00+00:00'])]
    public function it_filters_the_stored_column_by_the_instant(string $operator, string $instant): void
    {
        $filter = (new InTimeZone('America/New_York'))->filter($operator, '2026-07-01 12:00:00');

        $this->assertNotNull($filter);
        $this->assertSame($operator, $filter[0]);
        $this->assertInstanceOf(DateTimeInterface::class, $filter[1]);
        $this->assertSame($instant, $filter[1]->format(DATE_ATOM));
    }

    #[Test]
    public function it_cannot_filter_with_a_pattern_and_orders_in_the_same_direction(): void
    {
        $conversion = new InTimeZone('America/New_York');

        $this->assertSame([null, 'desc'], [$conversion->filter('like', '2026%'), $conversion->order('DESC')]);
    }
}
