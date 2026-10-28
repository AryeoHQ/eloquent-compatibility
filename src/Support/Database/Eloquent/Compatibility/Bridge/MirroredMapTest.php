<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;
use Tests\Fixtures\Support\Workbench\CompatibilityMapEventMirror;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(MirroredMap::class)]
final class MirroredMapTest extends TestCase
{
    #[Test]
    public function it_carries_the_resolved_map_the_mirror_and_the_key_translator_it_was_given(): void
    {
        $resolvedMap = ResolvedMap::of(new WidgetCompatibilityMap);
        $mirror = new CompatibilityMapEventMirror(new WidgetCompatibilityMap);
        $keyTranslator = new KeyTranslator($resolvedMap);

        $mirroredMap = new MirroredMap($resolvedMap, $mirror, $keyTranslator);

        $this->assertSame(['resolvedMap' => $resolvedMap, 'mirror' => $mirror, 'keyTranslator' => $keyTranslator], get_object_vars($mirroredMap));
    }
}
