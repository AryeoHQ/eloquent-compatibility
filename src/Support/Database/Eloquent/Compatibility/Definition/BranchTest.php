<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Tests\Fixtures\Support\Files\Link;
use Tests\Fixtures\Support\Listings\SyncListingToMls;
use Tests\TestCase;

#[CoversClass(Branch::class)]
final class BranchTest extends TestCase
{
    #[Test]
    public function it_declares_one_successor_with_the_attributes_its_closure_gives_it(): void
    {
        $branch = Branch::declare('videos', Link::class, fn (Successor $link) => $link
            ->attribute('youtube_embed_url', fn (Attribute $attribute) => $attribute->from('url'))
            ->attribute('original_file_name', fn (Attribute $attribute) => $attribute->fixed(null)));

        $this->assertSame([Link::class, Link::class, 'links', 'id'], [$branch->model, $branch->successor->model, $branch->successor->table, $branch->successor->key]);
        $this->assertSame(['youtube_embed_url' => 'url', 'original_file_name' => null], array_map(fn (Attribute $attribute) => $attribute->column(), $branch->successor->attributes()));
    }

    #[Test]
    public function it_refuses_a_class_that_is_no_model(): void
    {
        $this->expectExceptionObject(InvalidCompatibilityMap::because('videos', 'as() names '.SyncListingToMls::class.', which is not an Eloquent model'));

        Branch::declare('videos', SyncListingToMls::class, fn (Successor $successor) => $successor);
    }

    #[Test]
    public function it_refuses_a_closure_that_captures_a_variable(): void
    {
        $column = 'url';

        $this->expectExceptionObject(InvalidCompatibilityMap::because('videos', 'the as(Link) closure captures $column, and a map reads nothing but its own arguments'));

        Branch::declare('videos', Link::class, fn (Successor $link) => $link->attribute('youtube_embed_url', fn (Attribute $attribute) => $attribute->from($column)));
    }
}
