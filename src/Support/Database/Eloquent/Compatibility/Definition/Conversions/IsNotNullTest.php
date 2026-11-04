<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

#[CoversClass(IsNotNull::class)]
final class IsNotNullTest extends TestCase
{
    #[Test]
    public function it_reads_whether_the_stored_value_is_set(): void
    {
        $conversion = new IsNotNull(fn () => '2026-11-01 12:00:00+00');

        $this->assertSame('("jobs"."published_at" IS NOT NULL)', $conversion->read('"jobs"."published_at"'));
        $this->assertSame([true, false], [
            DB::scalar('select '.$conversion->read('CAST(? AS timestamptz)'), ['2026-11-01 12:00:00+00']),
            DB::scalar('select '.$conversion->read('CAST(? AS timestamptz)'), [null]),
        ]);
    }

    #[Test]
    #[TestWith([true, '2026-11-01 12:00:00+00'])]
    #[TestWith([1, '2026-11-01 12:00:00+00'])]
    #[TestWith(['true', '2026-11-01 12:00:00+00'])]
    #[TestWith([false, null])]
    #[TestWith(['false', null])]
    #[TestWith(['0', null])]
    #[TestWith([null, null])]
    public function it_writes_the_value_set_to_gives_for_true_and_null_for_false(null|bool|int|string $legacy, null|string $stored): void
    {
        $this->assertSame($stored, (new IsNotNull(fn () => '2026-11-01 12:00:00+00'))->write($legacy));
    }

    #[Test]
    public function it_refuses_to_write_a_value_that_is_not_a_boolean(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException("'maybe' is not a boolean, so isNotNull() cannot store it."));

        (new IsNotNull(fn () => '2026-11-01 12:00:00+00'))->write('maybe');
    }

    /**
     * @param  null|array{string, mixed}  $filter
     */
    #[Test]
    #[TestWith(['=', true, ['!=', null]])]
    #[TestWith(['=', false, ['=', null]])]
    #[TestWith(['!=', true, ['=', null]])]
    #[TestWith(['<>', false, ['!=', null]])]
    #[TestWith(['>', false, null])]
    public function it_filters_by_whether_the_stored_column_is_null(string $operator, bool $value, null|array $filter): void
    {
        $this->assertSame($filter, (new IsNotNull(fn () => null))->filter($operator, $value));
    }
}
