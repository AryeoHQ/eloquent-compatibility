<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

#[CoversClass(ReservedWords::class)]
final class ReservedWordsTest extends TestCase
{
    #[Test]
    #[TestWith(['all'])]
    #[TestWith(['analyse'])]
    #[TestWith(['authorization'])]
    #[TestWith(['current_timestamp'])]
    #[TestWith(['from'])]
    #[TestWith(['join'])]
    #[TestWith(['natural'])]
    #[TestWith(['order'])]
    #[TestWith(['returning'])]
    #[TestWith(['system_user'])]
    #[TestWith(['user'])]
    #[TestWith(['with'])]
    public function it_lists_a_reserved_postgresql_word(string $word): void
    {
        $this->assertContains($word, ReservedWords::POSTGRESQL);
    }

    #[Test]
    #[TestWith(['gadget'])]
    #[TestWith(['place'])]
    #[TestWith(['widget'])]
    #[TestWith(['name'])]
    #[TestWith(['type'])]
    #[TestWith(['key'])]
    #[TestWith(['Order'])]
    #[TestWith(['"order"'])]
    public function it_does_not_list_a_word_postgresql_accepts_as_an_alias(string $word): void
    {
        $this->assertNotContains($word, ReservedWords::POSTGRESQL);
    }

    #[Test]
    public function it_lists_each_word_once_in_lowercase(): void
    {
        $this->assertSame(array_values(array_unique(ReservedWords::POSTGRESQL)), ReservedWords::POSTGRESQL);
        $this->assertSame(array_map(strtolower(...), ReservedWords::POSTGRESQL), ReservedWords::POSTGRESQL);
    }

    #[Test]
    public function it_lists_every_word_the_server_reserves_as_an_alias(): void
    {
        $reserved = DB::table(DB::raw('pg_get_keywords()'))->whereIn('catcode', ['R', 'T'])->orderBy('word')->pluck('word')->all();

        $this->assertSame([], array_values(array_diff($reserved, ReservedWords::POSTGRESQL)));
    }
}
