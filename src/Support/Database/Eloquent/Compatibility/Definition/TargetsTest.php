<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Closure;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Tests\Fixtures\Support\Listings\SyncListingToMls;
use Tests\Fixtures\Support\PlaceAssets\AssetFile;
use Tests\Fixtures\Support\PlaceAssets\AssetLink;
use Tests\Fixtures\Support\PlaceAssets\PlaceAsset;
use Tests\TestCase;

#[CoversClass(Targets::class)]
final class TargetsTest extends TestCase
{
    #[Test]
    public function it_declares_each_target_under_its_class(): void
    {
        $morph = $this->morph(fn (Targets $targets) => $targets
            ->as(
                target: AssetFile::class,
                successor: fn (Successor $file) => $file,
            )
            ->as(
                target: AssetLink::class,
                successor: fn (Successor $link) => $link,
            ));

        $this->assertSame([AssetFile::class, AssetLink::class], array_keys($morph()->morph()->targets ?? []));
    }

    /**
     * @return iterable<string, array{Closure(Targets): mixed, string}>
     */
    public static function invalidTargets(): iterable
    {
        yield 'no target' => [fn (Targets $targets) => $targets, 'the targets: of relatedMorph() declare no target; declare each with as()'];
        yield 'a target twice' => [fn (Targets $targets) => $targets
            ->as(
                target: AssetFile::class,
                successor: fn (Successor $file) => $file,
            )
            ->as(
                target: AssetFile::class,
                successor: fn (Successor $file) => $file,
            ), 'as('.AssetFile::class.') is declared twice'];
        yield 'a target that is no model' => [fn (Targets $targets) => $targets->as(
            target: SyncListingToMls::class,
            successor: fn (Successor $listener) => $listener,
        ), 'as() names '.SyncListingToMls::class.', which is not an Eloquent model'];
    }

    /**
     * @param  Closure(Targets): mixed  $targets
     */
    #[DataProvider('invalidTargets')]
    #[Test]
    public function it_refuses_a_target_declaration_the_morph_cannot_serve(Closure $targets, string $problem): void
    {
        $this->expectExceptionObject(InvalidCompatibilityMap::because(
            map: 'place_videos',
            problem: $problem,
        ));

        $this->morph($targets)();
    }

    /**
     * @param  Closure(Targets): mixed  $targets
     * @return Closure(): Successor
     */
    private function morph(Closure $targets): Closure
    {
        return fn (): Successor => (new Successor(
            map: 'place_videos',
            model: PlaceAsset::class,
        ))->relatedMorph(
            relation: fn (PlaceAsset $asset) => $asset->asset(),
            choose: fn (Model $legacy): string => AssetFile::class,
            targets: $targets,
        );
    }
}
