<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Tests\Fixtures\Support\Listings\LifecycleStatus;
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

    #[Test]
    #[TestWith([null, 'NULL'])]
    #[TestWith([true, 'true'])]
    #[TestWith([false, 'false'])]
    #[TestWith([42, '42'])]
    #[TestWith([-1.5, '-1.5'])]
    #[TestWith(["Coeur d'Alene", "'Coeur d''Alene'"])]
    public function it_writes_a_value_as_a_literal_postgresql_reads_back_unchanged(null|bool|int|float|string $value, string $literal): void
    {
        $this->assertSame($literal, SqlFragments::value($value));
        $this->assertEquals($value, DB::selectOne('select '.SqlFragments::value($value).' as value')->value);
    }

    #[Test]
    public function it_writes_a_backed_enum_as_its_value_and_refuses_a_value_with_no_literal(): void
    {
        $this->assertSame("'live'", SqlFragments::value(LifecycleStatus::Live));

        $this->expectExceptionObject(new InvalidArgumentException('array has no SQL literal.'));

        SqlFragments::value([1]);
    }

    #[Test]
    public function it_binds_each_placeholder_outside_a_quoted_string_and_unescapes_a_doubled_question_mark(): void
    {
        $this->assertSame(
            "\"city\" = 'Coeur d''Alene' and \"note\" = 'why?' and \"tags\" ? 'primary' and \"id\" in (1, NULL)",
            SqlFragments::bind("\"city\" = ? and \"note\" = 'why?' and \"tags\" ?? ? and \"id\" in (?, ?)", ["Coeur d'Alene", 'primary', 1, null]),
        );
    }

    /**
     * @param  list<mixed>  $bindings
     */
    #[Test]
    #[TestWith(['"a" = ? and "b" = ?', ['one'], 'The SQL ["a" = ? and "b" = ?] has more placeholders than its 1 bindings.'])]
    #[TestWith(['"a" = ?', ['one', 'two'], 'The SQL ["a" = ?] has 1 placeholders for its 2 bindings.'])]
    public function it_refuses_bindings_that_do_not_match_the_placeholders(string $sql, array $bindings, string $refusal): void
    {
        $this->expectExceptionObject(new InvalidArgumentException($refusal));

        SqlFragments::bind($sql, $bindings);
    }

    #[Test]
    public function it_keeps_an_object_name_postgresql_keeps_whole_and_refuses_a_longer_one(): void
    {
        $this->assertSame(str_repeat('a', 63), SqlFragments::objectName('widgets', str_repeat('a', 63)));

        $this->expectExceptionObject(InvalidCompatibilityMap::because('widgets', 'the compiled name '.str_repeat('a', 64).' has 64 characters, and PostgreSQL keeps 63'));

        SqlFragments::objectName('widgets', str_repeat('a', 64));
    }
}
