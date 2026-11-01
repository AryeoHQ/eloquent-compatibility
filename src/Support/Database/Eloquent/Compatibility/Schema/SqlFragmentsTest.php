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

    #[Test]
    public function it_inlines_each_binding_as_a_literal_postgresql_reads_back_without_a_connection(): void
    {
        $grammar = DB::connection()->getQueryGrammar();
        $sql = SqlFragments::inline(
            "select ?::text as quote, ? as number, ? as decimal, ? as truth, ? as nothing, ? as instant, '?' as kept",
            ["it's \\ ok", 7, 1.5, true, null, new \DateTimeImmutable('2026-11-01 10:00:00+00:00')],
            $grammar,
        );

        $this->assertSame("select 'it''s \\ ok'::text as quote, 7 as number, 1.5 as decimal, true as truth, null as nothing, '2026-11-01 10:00:00.000000+00:00' as instant, '?' as kept", $sql);
        $this->assertSame(
            ['quote' => "it's \\ ok", 'number' => 7, 'decimal' => '1.5', 'truth' => true, 'nothing' => null, 'kept' => '?'],
            array_diff_key((array) DB::selectOne($sql), ['instant' => true]),
        );
        // PostgreSQL's ? operator, which the grammar escapes as ??, comes back as one ?.
        $this->assertSame("select '{}'::jsonb ? 'a'", SqlFragments::inline("select '{}'::jsonb ?? ?", ['a'], $grammar));
    }

    #[Test]
    public function it_refuses_more_placeholders_than_bindings(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SqlFragments::inline('select ?, ?', [1], DB::connection()->getQueryGrammar());
    }
}
