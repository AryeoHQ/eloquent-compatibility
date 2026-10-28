<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Testing\InteractsWithCompatibilityMaps;
use Tests\TestCase;

#[CoversClass(SqlFragments::class)]
final class SqlFragmentsTest extends TestCase
{
    use InteractsWithCompatibilityMaps;

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
    #[TestWith([['Draft', 'a note'], ['Draft', 'a note'], false])]
    #[TestWith([['Draft', 'a note'], ['Live', 'a note'], true])]
    #[TestWith([['Draft', 'a note'], ['Draft', 'another note'], true])]
    #[TestWith([['Draft', null], ['Draft', 'a note'], true])]
    #[TestWith([['Draft', 'a note'], ['Draft', null], true])]
    #[TestWith([[null, null], [null, null], false])]
    public function it_tells_whether_any_listed_column_changed_counting_null_as_a_value(array $old, array $new, bool $changed): void
    {
        DB::statement('create table "Changes" ("Status" text, "the note" text, changed boolean)');
        DB::unprepared('create function record_change() returns trigger language plpgsql as $record$ begin NEW.changed := '.SqlFragments::changed(['Status', 'the note']).'; return NEW; end $record$');
        DB::statement('create trigger record_change before update on "Changes" for each row execute function record_change()');
        DB::table('Changes')->insert(['Status' => $old[0], 'the note' => $old[1]]);

        DB::table('Changes')->update(['Status' => $new[0], 'the note' => $new[1]]);

        $this->assertSame($changed, DB::table('Changes')->value('changed'));
    }

    #[Test]
    #[TestWith(['{"a":1}', '{"a":1}', false])]
    #[TestWith(['{"a":1}', '{"a": 1}', true])]
    #[TestWith(['{"a":1,"b":2}', '{"b":2,"a":1}', true])]
    #[TestWith([null, '{"a":1}', true])]
    public function it_tells_whether_a_column_compared_as_text_changed_by_its_text(null|string $old, string $new, bool $changed): void
    {
        DB::statement('create table "Changes" ("Status" text, "the settings" json, changed boolean)');
        DB::unprepared('create function record_change() returns trigger language plpgsql as $record$ begin NEW.changed := '.SqlFragments::changed(['Status', 'the settings'], ['the settings']).'; return NEW; end $record$');
        DB::statement('create trigger record_change before update on "Changes" for each row execute function record_change()');
        DB::table('Changes')->insert(['Status' => 'Draft', 'the settings' => $old]);

        DB::table('Changes')->update(['the settings' => $new]);

        $this->assertSame($changed, DB::table('Changes')->value('changed'));
    }

    #[Test]
    public function it_refuses_with_a_message_expression_and_a_quoted_detail_and_hint_that_php_reads_back(): void
    {
        $message = SqlFragments::literal("Compatibility map 'widgets' refused record ").' || 7::text';

        $refusal = $this->assertWriteRefused(fn () => DB::unprepared('DO $refusal$ BEGIN '.SqlFragments::refuse($message, "A widget's id stays.", "Leave the widget's id alone.").' END $refusal$'));

        $this->assertSame(
            ["Compatibility map 'widgets' refused record 7", "A widget's id stays.", "Leave the widget's id alone."],
            [$refusal->refused, $refusal->because, $refusal->instead],
        );
        $this->assertSame(SqlState::Refused->value, $refusal->getPrevious()?->getCode());
    }

    #[Test]
    #[TestWith([SqlState::Refused, true])]
    #[TestWith([SqlState::SerializationFailure, false])]
    public function it_raises_the_given_sqlstate_with_its_message_detail_and_hint(SqlState $code, bool $isRefusal): void
    {
        try {
            DB::unprepared('DO $raise$ BEGIN '.SqlFragments::raise($code, SqlFragments::literal("It's raised"), "The record's detail.", "The record's hint.").' END $raise$');
        } catch (QueryException $e) {
            $this->assertSame($code->value, $e->getCode());
            $this->assertSame($isRefusal, CompatibilityWriteRefused::isRefusal($e));
            $this->assertStringContainsString("ERROR:  It's raised\nDETAIL:  The record's detail.\nHINT:  The record's hint.", $e->getMessage());

            return;
        }

        $this->fail('Expected the statement to raise.');
    }
}
