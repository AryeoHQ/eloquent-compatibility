<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ReadAs::class)]
final class ReadAsTest extends TestCase
{
    #[Test]
    public function it_reads_its_own_sql_in_place_of_the_stored_column(): void
    {
        $conversion = new ReadAs("upper('seattle')");

        $this->assertSame("(upper('seattle'))", $conversion->read('"places"."city"'));
        $this->assertSame('SEATTLE', DB::scalar('select '.$conversion->read('"places"."city"')));
    }

    #[Test]
    public function it_writes_through_its_inverse(): void
    {
        $this->assertSame('seattle', (new ReadAs('upper("places"."city")', fn (mixed $value) => strtolower((string) $value)))->write('SEATTLE'));
    }

    #[Test]
    public function it_refuses_a_write_without_an_inverse(): void
    {
        $this->expectExceptionObject(new LogicException('readAs(upper("places"."city")) has no writeTo(), so it reads only.'));

        (new ReadAs('upper("places"."city")'))->write('SEATTLE');
    }

    #[Test]
    public function it_cannot_filter_or_order_by_the_stored_column(): void
    {
        $conversion = new ReadAs('upper("places"."city")', fn (mixed $value) => $value);

        $this->assertSame([null, null], [$conversion->filter('=', 'SEATTLE'), $conversion->order('asc')]);
    }
}
