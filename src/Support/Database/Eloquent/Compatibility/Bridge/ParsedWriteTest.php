<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\TestCase;

#[CoversClass(ParsedWrite::class)]
final class ParsedWriteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Tables::create();
    }

    #[Test]
    public function it_parses_the_insert_update_soft_delete_and_force_delete_eloquent_emits(): void
    {
        $gadget = new Gadget(['name' => 'Blue gadget']);

        $this->assertParsed(Operation::Insert, 'gadgets', fn () => $gadget->save());
        $this->assertParsed(Operation::Update, 'gadgets', fn () => $gadget->update(['name' => 'Renamed']));
        $this->assertParsed(Operation::Update, 'gadgets', fn () => $gadget->delete());
        $this->assertParsed(Operation::Delete, 'gadgets', fn () => $gadget->forceDelete());
    }

    #[Test]
    public function it_parses_the_writes_the_query_builder_emits_with_a_quoted_and_a_schema_qualified_name(): void
    {
        $this->assertParsed(Operation::Insert, 'gadgets', fn () => DB::table('gadgets')->insert(['name' => 'Blue gadget']));
        $this->assertParsed(Operation::Update, 'gadgets', fn () => DB::table('public.gadgets')->update(['name' => 'Renamed']));
        $this->assertParsed(Operation::Insert, 'gadget_places', fn () => DB::table('public.gadget_places')->insertUsing(['gadget_id', 'street'], DB::table('gadgets')->select('id', DB::raw("'1 Main St'"))));
        $this->assertParsed(Operation::Delete, 'gadget_places', fn () => DB::table('public.gadget_places')->delete());
        $this->assertParsed(Operation::Delete, 'gadgets', fn () => DB::table('gadgets')->where('name', 'Renamed')->delete());
    }

    #[Test]
    #[TestWith(['insert into gadgets (name) values (?)', Operation::Insert, 'gadgets'])]
    #[TestWith(["  \n\tUPDATE Gadgets SET name = ?", Operation::Update, 'gadgets'])]
    #[TestWith(['DELETE FROM public.gadget_places WHERE id = ?', Operation::Delete, 'gadget_places'])]
    #[TestWith(['delete   from   "gadget_sites" where "id" = ?', Operation::Delete, 'gadget_sites'])]
    #[TestWith(['update "audit"."retained_widgets" set "sku" = ?', Operation::Update, 'retained_widgets'])]
    public function it_parses_a_raw_write_whatever_its_case_spacing_quoting_or_schema(string $sql, Operation $operation, string $relation): void
    {
        $parsedWrite = ParsedWrite::of($sql);

        $this->assertNotNull($parsedWrite);
        $this->assertSame([$operation, $relation], [$parsedWrite->operation, $parsedWrite->relation]);
    }

    #[Test]
    #[TestWith(['select * from "gadgets" where "id" = ?'])]
    #[TestWith(['with renamed as (select 1) update "gadgets" set "name" = ?'])]
    #[TestWith(['truncate "gadgets"'])]
    #[TestWith(['insert "gadgets" default values'])]
    #[TestWith(['delete "gadgets"'])]
    #[TestWith(['updated "gadgets"'])]
    #[TestWith(['-- update "gadgets" set "name" = ?'])]
    #[TestWith([''])]
    public function it_parses_nothing_from_sql_that_is_not_a_single_table_write(string $sql): void
    {
        $this->assertNull(ParsedWrite::of($sql));
    }

    #[Test]
    #[TestWith(['update "gadget-items" set "name" = ? where "id" = ?', Operation::Update, 'gadget-items'])]
    #[TestWith(['insert into "gadget places" ("street") values (?)', Operation::Insert, 'gadget places'])]
    #[TestWith(['delete from "gadget""items" where "id" = ?', Operation::Delete, 'gadget"items'])]
    #[TestWith(['update "public"."gadget-items" set "name" = ?', Operation::Update, 'gadget-items'])]
    #[TestWith(['update "my ""schema"""."Gadget Items" set "name" = ?', Operation::Update, 'Gadget Items'])]
    #[TestWith(['update "Gadgets" set "name" = ?', Operation::Update, 'Gadgets'])]
    #[TestWith(['update "1gadgets" set "name" = ?', Operation::Update, '1gadgets'])]
    public function it_parses_a_quoted_relation_as_one_whole_case_sensitive_identifier(string $sql, Operation $operation, string $relation): void
    {
        $parsedWrite = ParsedWrite::of($sql);

        $this->assertSame([$operation, $relation], [$parsedWrite?->operation, $parsedWrite?->relation]);
    }

    #[Test]
    #[TestWith(['UPDATE Widgets SET sku = ?', Operation::Update, 'widgets'])]
    #[TestWith(['delete from Public.Widgets where id = ?', Operation::Delete, 'widgets'])]
    public function it_folds_an_unquoted_relation_to_lower_case(string $sql, Operation $operation, string $relation): void
    {
        $parsedWrite = ParsedWrite::of($sql);

        $this->assertSame([$operation, $relation], [$parsedWrite?->operation, $parsedWrite?->relation]);
    }

    /**
     * @param  null|list<string>  $columns
     */
    #[Test]
    #[TestWith(['insert into "widgets" ("title", "price") values (?, ?) returning "id"', ['title', 'price']])]
    #[TestWith(['insert into "widgets" default values returning "id"', []])]
    #[TestWith(['INSERT INTO public.widgets (Title, "Odd ""Name""") VALUES (?, ?)', ['title', 'Odd "Name"']])]
    #[TestWith(['insert into "widgets" select * from "retained_widgets"', null])]
    #[TestWith(['update "widgets" set "title" = ?, "updated_at" = ? where "id" = ?', ['title', 'updated_at']])]
    #[TestWith(['UPDATE public.widgets SET Title = lower(?), "Odd ""Name""" = coalesce("Odd ""Name""", ?) WHERE id = ?', ['title', 'Odd "Name"']])]
    #[TestWith(['update "widgets" set "specs" = jsonb_set("specs", \'{a,b}\', ?), "tags" = ARRAY[1, 2] where "id" = ?', ['specs', 'tags']])]
    #[TestWith(['update "widgets" set "count" = "count" + 1, "label" = \'from, where\' returning "id"', ['count', 'label']])]
    #[TestWith(['update "widgets" set ("title", "sku") = (?, ?) where "id" = ?', null])]
    #[TestWith(['update "widgets" where "id" = ?', null])]
    #[TestWith(['delete from "widgets" where "id" = ?', null])]
    public function it_parses_the_columns_an_insert_names_or_an_update_sets_and_none_for_a_delete(string $sql, null|array $columns): void
    {
        $this->assertSame($columns, ParsedWrite::of($sql)?->columns);
    }

    /**
     * @param  array<string, list<int>>  $placeholders
     */
    #[Test]
    #[TestWith(['update "widgets" set "title" = ?, "updated_at" = ? where "id" = ?', ['id' => [2]]])]
    #[TestWith(['update "widgets" set "id" = ? where "id" = ?', ['id' => [1]]])]
    #[TestWith(['update "widgets" set "sku" = ? where "widgets"."id" = ? and "widgets"."deleted_at" is null', ['id' => [1]]])]
    #[TestWith(['UPDATE widgets SET sku = ? WHERE tenant_id = ? AND "Id" = ? OR id = ?', ['tenant_id' => [1], 'Id' => [2], 'id' => [3]]])]
    #[TestWith(['delete from "widgets" where "id" = ?', ['id' => [0]]])]
    #[TestWith(['delete from "widgets" where "id" in (?, ?)', []])]
    #[TestWith(['delete from "widgets"', []])]
    #[TestWith(['insert into "widgets" ("title") values (?)', []])]
    public function it_parses_the_placeholder_of_each_column_its_where_clause_compares_for_equality(string $sql, array $placeholders): void
    {
        $this->assertSame($placeholders, ParsedWrite::of($sql)?->wherePlaceholders);
    }

    /**
     * @param  array<string, list<int>>  $placeholders
     */
    #[Test]
    #[TestWith(["update \"widgets\" set \"title\" = 'Why?', \"updated_at\" = ? where \"id\" = ?", ['id' => [1]]])]
    #[TestWith(["update \"widgets\" set \"title\" = 'It''s ?' where \"id\" = ?", ['id' => [0]]])]
    #[TestWith(["update \"widgets\" set \"title\" = ? where \"id\" = ? and \"sku\" <> '?'", ['id' => [1]]])]
    #[TestWith(["update \"widgets\" set \"title\" = E'It\\'s ?' where \"id\" = ?", ['id' => [0]]])]
    #[TestWith(["update \"widgets\" set \"title\" = e'\\\\' where \"id\" = ?", ['id' => [0]]])]
    #[TestWith(['update "widgets" set "title" = $$Why? \'$$ where "id" = ?', ['id' => [0]]])]
    #[TestWith(['update "widgets" set "title" = $why$Why? $$ $why$ where "id" = ?', ['id' => [0]]])]
    #[TestWith(['update "widgets" set "Why?" = ? where "id" = ?', ['id' => [1]]])]
    #[TestWith(['update "widgets" set "title" = ? where "a "" where id = ?" = ?', ['a " where id = ?' => [1]]])]
    #[TestWith(["update \"widgets\" set \"title\" = ? -- why?\nwhere \"id\" = ?", ['id' => [1]]])]
    #[TestWith(["update \"widgets\" set \"title\" = ? -- c\rwhere \"id\" = ?", ['id' => [1]]])]
    #[TestWith(['update "widgets" set "title" = $Q$x$q$ where "id" = ?$Q$, "updated_at" = ? where "id" = ?', ['id' => [1]]])]
    #[TestWith(['update "widgets" set "title" = ? /* why? /* where id = ? */ */ where "id" = ?', ['id' => [1]]])]
    #[TestWith(['update "widgets" set "flags" = "flags" ?? ? where "id" = ?', ['id' => [1]]])]
    #[TestWith(["update \"widgets\" set \"title\" = ' where id = ?' where \"sku\" = ?", ['sku' => [0]]])]
    #[TestWith(["delete from \"widgets\" where \"title\" = 'id = ?'", []])]
    public function it_counts_only_the_placeholders_outside_literals_identifiers_and_comments(string $sql, array $placeholders): void
    {
        $this->assertSame($placeholders, ParsedWrite::of($sql)?->wherePlaceholders);
    }

    /**
     * @param  array<string, list<int>>  $placeholders
     */
    #[DataProvider('longConstructs')]
    #[Test]
    public function it_counts_the_placeholders_after_a_literal_identifier_or_comment_of_any_length(string $sql, array $placeholders): void
    {
        $this->assertSame($placeholders, ParsedWrite::of($sql)?->wherePlaceholders);
    }

    /**
     * @return iterable<string, array{string, array<string, list<int>>}>
     */
    public static function longConstructs(): iterable
    {
        foreach ([10_000, 100_000] as $length) {
            $filler = str_repeat('a', $length);

            yield "a single-quoted literal of $length bytes" => ["update \"widgets\" set \"title\" = '$filler', \"updated_at\" = ? where \"id\" = ?", ['id' => [1]]];
            yield "an E'' literal of $length bytes" => ["update \"widgets\" set \"title\" = E'$filler', \"updated_at\" = ? where \"id\" = ?", ['id' => [1]]];
            yield "a quoted identifier of $length bytes" => ["update \"widgets\" set \"$filler\" = ?, \"updated_at\" = ? where \"id\" = ?", ['id' => [2]]];
            yield "a block comment of $length bytes" => ["update \"widgets\" set \"title\" = ? /* $filler */ where \"id\" = ?", ['id' => [1]]];
        }

        $filler = str_repeat('a', 100_000);

        yield 'an unterminated single-quoted literal of 100000 bytes' => ["update \"widgets\" set \"title\" = ? where \"id\" = ? and \"sku\" = '$filler", ['id' => [1]]];
        yield "an unterminated E'' literal of 100000 bytes" => ["update \"widgets\" set \"title\" = ? where \"id\" = ? and \"sku\" = E'$filler", ['id' => [1]]];
        yield 'an unterminated quoted identifier of 100000 bytes' => ["update \"widgets\" set \"title\" = ? where \"id\" = ? and \"$filler", ['id' => [1]]];
        yield 'an unterminated block comment of 100000 bytes' => ["update \"widgets\" set \"title\" = ? where \"id\" = ? /* $filler", ['id' => [1]]];
    }

    #[Test]
    public function it_parses_nothing_when_the_scan_of_the_statement_fails(): void
    {
        $nesting = 10_000;

        $this->assertNull(ParsedWrite::of('update "widgets" set "title" = ? '.str_repeat('/*', $nesting).str_repeat('*/', $nesting).' where "id" = ?'));
    }

    #[Test]
    public function it_parses_the_columns_of_the_insert_eloquent_emits_in_the_order_of_the_models_attributes(): void
    {
        $gadget = new Gadget(['price_cents' => 1250, 'name' => 'Blue gadget']);
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $gadget->save();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame(array_keys($gadget->getAttributes()), ParsedWrite::of(DB::getQueryLog()[0]['query'])?->columns);
        $this->assertSame(['price_cents', 'name', 'id', 'updated_at', 'created_at'], array_keys($gadget->getAttributes()));
    }

    #[Test]
    public function it_parses_no_write_from_a_read(): void
    {
        $this->assertNull(ParsedWrite::of('select * from "gadget-items"'));
    }

    private function assertParsed(Operation $operation, string $relation, callable $write): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $write();
        } finally {
            DB::disableQueryLog();
        }

        $queries = array_column(DB::getQueryLog(), 'query');
        $this->assertCount(1, $queries, implode("\n", $queries));

        $parsedWrite = ParsedWrite::of($queries[0]);

        $this->assertNotNull($parsedWrite, $queries[0]);
        $this->assertSame([$operation, $relation], [$parsedWrite->operation, $parsedWrite->relation], $queries[0]);
    }
}
