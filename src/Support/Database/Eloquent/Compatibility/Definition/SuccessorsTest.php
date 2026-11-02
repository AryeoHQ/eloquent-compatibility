<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Tests\Fixtures\Support\Listings\SyncListingToMls;
use Tests\Fixtures\Support\PlaceAssets\AssetFile;
use Tests\Fixtures\Support\PlaceAssets\AssetLink;
use Tests\Fixtures\Support\PlaceAssets\Video;
use Tests\TestCase;

#[CoversClass(Successors::class)]
final class SuccessorsTest extends TestCase
{
    #[Test]
    public function it_declares_one_successor_for_each_as_in_the_order_they_are_declared(): void
    {
        $declared = Successors::declare('place_videos', 'relatedMorph()', fn (Video $video): string => AssetFile::class, fn (Successors $successors) => $successors
            ->as(AssetLink::class, fn (Successor $link) => $link->attribute('youtube_embed_url', fn (Attribute $attribute) => $attribute->from('url')))
            ->as(AssetFile::class, fn (Successor $file) => $file->attribute('original_file_name', fn (Attribute $attribute) => $attribute->from('filename'))), 'drop choose:');

        $this->assertSame(
            [AssetLink::class => [AssetLink::class, 'asset_links', ['youtube_embed_url']], AssetFile::class => [AssetFile::class, 'asset_files', ['original_file_name']]],
            array_map(fn (Branch $branch) => [$branch->successor->model, $branch->successor->table, array_keys($branch->successor->attributes())], $declared),
        );
    }

    #[Test]
    public function it_declares_one_successor_without_a_choice(): void
    {
        $this->assertSame([AssetFile::class], array_keys(Successors::declare('place_images', 'relatedMorph()', null, fn (Successors $successors) => $successors->as(AssetFile::class, fn (Successor $file) => $file), 'drop choose:')));
    }

    /**
     * @return iterable<string, array{null|Closure, Closure(Successors): mixed, string}>
     */
    public static function invalidSuccessors(): iterable
    {
        $choose = fn (Video $video): string => AssetFile::class;

        yield 'no successor' => [$choose, fn (Successors $successors) => $successors, 'the successors: of relatedMorph() declare no successor; declare each with as()'];
        yield 'a choice of one successor' => [$choose, fn (Successors $successors) => $successors->as(AssetFile::class, fn (Successor $file) => $file), 'choose: on relatedMorph() picks one of the successors: classes, and successors: declares only AssetFile; drop choose:, and relatedMorph() reads AssetFile for every record'];
        yield 'two successors without a choice' => [null, fn (Successors $successors) => $successors->as(AssetFile::class, fn (Successor $file) => $file)->as(AssetLink::class, fn (Successor $link) => $link), 'successors: on relatedMorph() declares AssetFile and AssetLink without choose:; give choose: a closure that returns one of them'];
        yield 'one successor twice' => [$choose, fn (Successors $successors) => $successors->as(AssetFile::class, fn (Successor $file) => $file)->as(AssetFile::class, fn (Successor $file) => $file), 'as('.AssetFile::class.') is declared twice'];
        yield 'a successor that is no model' => [$choose, fn (Successors $successors) => $successors->as(SyncListingToMls::class, fn (Successor $sync) => $sync), 'as() names '.SyncListingToMls::class.', which is not an Eloquent model'];
    }

    /**
     * @param  Closure(Successors): mixed  $successors
     */
    #[DataProvider('invalidSuccessors')]
    #[Test]
    public function it_refuses_successors_that_choose_no_one_successor(null|Closure $choose, Closure $successors, string $problem): void
    {
        $this->expectExceptionObject(InvalidCompatibilityMap::because('place_videos', $problem));

        Successors::declare('place_videos', 'relatedMorph()', $choose, $successors, 'drop choose:, and relatedMorph() reads %s for every record');
    }

    #[Test]
    public function it_refuses_a_choice_that_captures_a_variable(): void
    {
        $embedded = AssetLink::class;

        $this->expectExceptionObject(InvalidCompatibilityMap::because('place_videos', 'the relatedMorph() choose: closure captures $embedded, and a map reads nothing but its own arguments'));

        Successors::declare('place_videos', 'relatedMorph()', fn (Video $video): string => $embedded, fn (Successors $successors) => $successors, 'drop choose:');
    }
}
