<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(DriftCheck::class)]
final class DriftCheckTest extends TestCase
{
    #[Test]
    public function it_names_each_check_by_the_value_the_report_prints(): void
    {
        $this->assertSame([
            'PreviousWithoutCurrent' => 'previous without current',
            'OrphanCurrent' => 'orphan current',
            'MoreThanOnePrimary' => 'more than one primary',
            'RefusedState' => 'refused state',
            'Designation' => 'designation',
        ], array_column(array_map(fn (DriftCheck $check) => [$check->name, $check->value], DriftCheck::cases()), 1, 0));
    }

    #[Test]
    public function it_reads_each_check_back_from_its_value(): void
    {
        foreach (DriftCheck::cases() as $check) {
            $this->assertSame($check, DriftCheck::from($check->value));
        }

        $this->assertNull(DriftCheck::tryFrom('previous_without_current'));
    }
}
