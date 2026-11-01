<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

#[CoversClass(SqlFragments::class)]
final class SqlFragmentsTest extends TestCase
{
    #[Test]
    #[TestWith(['widgets', '"widgets"'])]
    #[TestWith(['order', '"order"'])]
    #[TestWith(['user', '"user"'])]
    #[TestWith(['Mixed Case', '"Mixed Case"'])]
    #[TestWith(['say "hi"', '"say ""hi"""'])]
    #[TestWith(['"', '""""'])]
    #[TestWith(['public.widgets', '"public.widgets"'])]
    public function it_quotes_an_identifier_postgresql_reads_back_as_that_one_name(string $identifier, string $quoted): void
    {
        $this->assertSame($quoted, SqlFragments::identifier($identifier));
        $this->assertSame([$identifier], array_keys((array) DB::selectOne('select 1 as '.SqlFragments::identifier($identifier))));
    }

    #[Test]
    #[TestWith(["it's", "'it''s'"])]
    #[TestWith(["''", "''''''"])]
    #[TestWith(['back\\slash', "'back\\slash'"])]
    #[TestWith(['trailing\\', "'trailing\\'"])]
    #[TestWith(["line\nbreak", "'line\nbreak'"])]
    #[TestWith(['$$ dollar $$', "'$$ dollar $$'"])]
    #[TestWith(['', "''"])]
    public function it_quotes_a_literal_postgresql_reads_back_unchanged(string $value, string $literal): void
    {
        $this->assertSame($literal, SqlFragments::literal($value));
        $this->assertSame($value, DB::scalar('select '.SqlFragments::literal($value)));
    }

    #[Test]
    #[TestWith(['compatibility__widgets__', 'compatibility__widgets__insert', true])]
    #[TestWith(['compatibility__widgets__', 'compatibility__widgets__', true])]
    #[TestWith(['compatibility__widgets__', 'compatibilityXXwidgetsXXinsert', false])]
    #[TestWith(['compatibility__widgets__', 'compatibility__widgets_', false])]
    #[TestWith(['100%', '100% sure', true])]
    #[TestWith(['100%', '1000', false])]
    #[TestWith(['back\\slash', 'back\\slash and more', true])]
    #[TestWith(['back\\slash', 'backslash', false])]
    #[TestWith(["it's", "it's here", true])]
    public function it_matches_only_values_that_begin_with_the_prefix_taken_literally(string $prefix, string $value, bool $matches): void
    {
        $this->assertSame($matches, DB::scalar('select ? like '.SqlFragments::like($prefix), [$value]));
    }

    /**
     * @param  array{null|string, null|string}  $old
     * @param  array{null|string, null|string}  $new
     */
}
