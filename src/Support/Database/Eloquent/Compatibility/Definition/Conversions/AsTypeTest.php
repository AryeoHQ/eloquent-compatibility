<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

#[CoversClass(AsType::class)]
final class AsTypeTest extends TestCase
{
    #[Test]
    public function it_reads_the_stored_value_cast_to_the_legacy_type(): void
    {
        $conversion = new AsType('numeric(10,2)');

        $this->assertSame('CAST("jobs"."price" AS numeric(10,2))', $conversion->read('"jobs"."price"'));
        $this->assertSame('4500.56', DB::scalar('select '.$conversion->read('CAST(? AS numeric)').'::text', ['4500.555']));
    }

    #[Test]
    public function it_writes_the_legacy_value_unchanged(): void
    {
        $this->assertSame('4500.55', (new AsType('numeric(10,2)'))->write('4500.55'));
    }

    #[Test]
    #[TestWith(['='])]
    #[TestWith(['!='])]
    #[TestWith(['<'])]
    #[TestWith(['like'])]
    public function it_gives_no_stored_value_to_filter_by_because_a_cast_can_change_the_value(string $operator): void
    {
        $this->assertNull((new AsType('text'))->filter($operator, '12'));
        $this->assertFalse((new AsType('text'))->exact('text', 'text'));
    }
}
