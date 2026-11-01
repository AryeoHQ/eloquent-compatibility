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

    /**
     * @param  null|array{string, mixed}  $filter
     */
    #[Test]
    #[TestWith(['=', ['=', '12']])]
    #[TestWith(['!=', ['!=', '12']])]
    #[TestWith(['<>', ['<>', '12']])]
    #[TestWith(['<', null])]
    #[TestWith(['like', null])]
    public function it_filters_only_equality_by_the_value_unchanged(string $operator, null|array $filter): void
    {
        $this->assertSame($filter, (new AsType('text'))->filter($operator, '12'));
    }

    #[Test]
    public function it_cannot_order_by_the_stored_column(): void
    {
        $this->assertNull((new AsType('text'))->order('asc'));
    }
}
