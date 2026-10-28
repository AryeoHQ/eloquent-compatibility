<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(InvalidCompatibilityMap::class)]
final class InvalidCompatibilityMapTest extends TestCase
{
    #[Test]
    public function it_names_the_compatibility_map_and_the_problem(): void
    {
        $invalid = InvalidCompatibilityMap::because('widgets', 'its event mirror is registered twice');

        $this->assertSame("Compatibility map 'widgets' is declared wrongly: its event mirror is registered twice.", $invalid->getMessage());
        $this->assertInstanceOf(CompatibilityException::class, $invalid);
    }
}
