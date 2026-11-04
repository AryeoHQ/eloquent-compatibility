<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

use DateTimeImmutable;
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
    #[TestWith(['2026-07-01 12:00:00', '2026-07-01 16:00:00.000000+00:00'])]
    #[TestWith(['2026-01-15 09:30:00', '2026-01-15 14:30:00.000000+00:00'])]
    public function it_writes_a_wall_clock_time_in_the_zone_as_the_instant_with_an_explicit_offset(string $legacy, string $stored): void
    {
        $this->assertSame($stored, (new InTimeZone('America/New_York'))->write($legacy));
    }

    #[Test]
    public function it_writes_a_date_time_by_its_wall_clock_and_ignores_its_own_zone(): void
    {
        $written = (new InTimeZone('America/New_York'))->write(new DateTimeImmutable('2026-01-15 09:30:00', new DateTimeZone('Asia/Tokyo')));

        $this->assertSame('2026-01-15 14:30:00.000000+00:00', $written);
    }

    #[Test]
    public function it_reads_back_through_postgresql_the_value_it_writes(): void
    {
        $conversion = new InTimeZone('America/New_York');
        $written = $conversion->write('2026-03-08 01:59:00');

        $this->assertSame('2026-03-08 01:59:00', DB::transaction(function () use ($conversion, $written): mixed {
            DB::statement("set local time zone 'Asia/Tokyo'");

            return DB::scalar('select '.$conversion->read('CAST(? AS timestamptz)').'::text', [$written]);
        }));
    }

    #[Test]
    public function it_writes_null_as_null_and_refuses_a_value_that_is_no_time(): void
    {
        $this->assertNull((new InTimeZone('UTC'))->write(null));

        $this->expectExceptionObject(new InvalidArgumentException('12 is not a date and time in UTC.'));

        (new InTimeZone('UTC'))->write(12);
    }

    #[Test]
    #[TestWith(['>'])]
    #[TestWith(['<='])]
    #[TestWith(['='])]
    public function it_filters_the_stored_column_by_the_instant(string $operator): void
    {
        $this->assertSame([$operator, '2026-07-01 16:00:00.000000+00:00'], (new InTimeZone('America/New_York'))->filter($operator, '2026-07-01 12:00:00'));
    }

    #[Test]
    public function it_cannot_filter_with_a_pattern(): void
    {
        $conversion = new InTimeZone('America/New_York');

        $this->assertNull($conversion->filter('like', '2026%'));
    }
}
