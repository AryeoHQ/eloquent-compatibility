<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(MapMember::class)]
final class MapMemberTest extends TestCase
{
    #[Test]
    public function it_carries_the_map_and_the_alias_it_was_given(): void
    {
        $member = new MapMember('widgets', 'place');

        $this->assertSame(['map' => 'widgets', 'alias' => 'place'], get_object_vars($member));
    }
}
