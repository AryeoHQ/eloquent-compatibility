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
    public function it_refuses_a_write_because_its_operation_writes_it(): void
    {
        $this->expectExceptionObject(new LogicException('readAs(upper("places"."city")) has no inverse, so its writtenBy() operation writes it.'));

        (new ReadAs('upper("places"."city")'))->write('SEATTLE');
    }

    #[Test]
    public function it_cannot_filter_by_the_stored_column(): void
    {
        $conversion = new ReadAs('upper("places"."city")');

        $this->assertNull($conversion->filter('=', 'SEATTLE'));
    }
}
