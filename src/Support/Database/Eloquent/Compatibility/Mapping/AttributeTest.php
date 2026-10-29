<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(Attribute::class)]
final class AttributeTest extends TestCase
{
    private const array MAPPED_TITLE = [
        'column' => 'title',
        'retained' => false,
        'type' => null,
        'alias' => null,
        'readAs' => null,
        'writesTo' => [],
        'readOnlyBecause' => null,
        'defaultsTo' => null,
        'storedAs' => null,
        'timeZone' => null,
    ];

    #[Test]
    public function it_declares_a_retained_attribute(): void
    {
        $this->assertState(['column' => 'sku', 'retained' => true] + self::MAPPED_TITLE, Attribute::retained('sku'));
    }

    #[Test]
    public function it_declares_a_mapped_attribute(): void
    {
        $this->assertState(self::MAPPED_TITLE, Attribute::mapped('title'));
    }

    #[Test]
    public function it_sets_the_type_on_a_copy_and_keeps_the_rest(): void
    {
        $original = Attribute::mapped('title')->storedAs('gadget', 'name')->defaultsTo("'Untitled'");

        $typed = $original->asType('text');

        $this->assertState(['type' => 'text'] + $this->state($original), $typed);
        $this->assertNull($original->type);
    }

    #[Test]
    public function it_stores_the_attribute_in_one_column_and_replaces_earlier_writes(): void
    {
        $original = Attribute::mapped('title')->asType('text')->writesTo('gadget', 'label', 'NEW."title"');

        $stored = $original->storedAs('gadget', 'name');

        $this->assertState([
            'type' => 'text',
            'alias' => 'gadget',
            'readAs' => 'gadget."name"',
            'writesTo' => [['alias' => 'gadget', 'column' => 'name', 'expression' => 'NEW."title"']],
            'storedAs' => 'name',
        ] + self::MAPPED_TITLE, $stored);
        $this->assertState(['type' => 'text', 'writesTo' => [['alias' => 'gadget', 'column' => 'label', 'expression' => 'NEW."title"']]] + self::MAPPED_TITLE, $original);
    }

    #[Test]
    public function it_quotes_identifiers_that_contain_a_double_quote(): void
    {
        $stored = Attribute::mapped('ti"tle')->storedAs('gadget', 'na"me');

        $this->assertSame('gadget."na""me"', $stored->readAs);
        $this->assertSame('NEW."ti""tle"', $stored->writesTo[0]->expression);
    }

    #[Test]
    public function it_reads_the_attribute_through_an_expression_and_drops_its_writes_and_stored_column(): void
    {
        $original = Attribute::mapped('title')->asType('text')->storedAs('gadget', 'name')->defaultsTo("'Untitled'");

        $read = $original->readAs('gadget', 'upper(gadget.name)');

        $this->assertState(['type' => 'text', 'alias' => 'gadget', 'readAs' => 'upper(gadget.name)', 'defaultsTo' => "'Untitled'"] + self::MAPPED_TITLE, $read);
        $this->assertSame('gadget."name"', $original->readAs);
    }

    #[Test]
    public function it_appends_a_write_and_keeps_the_rest(): void
    {
        $original = Attribute::mapped('price')->readAs('gadget', 'gadget.price_cents / 100.0')->writesTo('gadget', 'price_cents', 'round(NEW.price::numeric * 100)');

        $written = $original->writesTo('gadget', 'currency', "'USD'");

        $this->assertSame([
            ['alias' => 'gadget', 'column' => 'price_cents', 'expression' => 'round(NEW.price::numeric * 100)'],
            ['alias' => 'gadget', 'column' => 'currency', 'expression' => "'USD'"],
        ], $this->state($written)['writesTo']);
        $this->assertSame(['writesTo' => []] + $this->state($written), ['writesTo' => []] + $this->state($original));
        $this->assertCount(1, $original->writesTo);
    }

    #[Test]
    public function it_declares_the_attribute_read_only_and_drops_its_writes(): void
    {
        $original = Attribute::mapped('title')->asType('text')->storedAs('gadget', 'name');

        $readOnly = $original->readOnlyBecause('A title follows the gadget name.');

        $this->assertState(['writesTo' => [], 'readOnlyBecause' => 'A title follows the gadget name.'] + $this->state($original), $readOnly);
        $this->assertNull($original->readOnlyBecause);
        $this->assertCount(1, $original->writesTo);
    }

    #[Test]
    public function it_sets_the_default_on_a_copy_and_keeps_the_rest(): void
    {
        $original = Attribute::mapped('title')->asType('text')->storedAs('gadget', 'name');

        $defaulted = $original->defaultsTo("'Untitled'");

        $this->assertState(['defaultsTo' => "'Untitled'"] + $this->state($original), $defaulted);
        $this->assertNull($original->defaultsTo);
    }

    #[Test]
    public function it_resolves_a_mapped_attribute_to_itself(): void
    {
        $mapped = Attribute::mapped('title')->asType('text')->storedAs('gadget', 'name');

        $this->assertSame($mapped, $mapped->resolve('widget'));
    }

    #[Test]
    public function it_resolves_a_writable_retained_attribute_to_its_column_on_the_previous_alias(): void
    {
        $original = Attribute::retained('sku')->asType('text')->defaultsTo("'none'");

        $resolved = $original->resolve('widget');

        $this->assertState([
            'column' => 'sku',
            'retained' => true,
            'type' => null,
            'alias' => 'widget',
            'readAs' => 'widget."sku"',
            'writesTo' => [['alias' => 'widget', 'column' => 'sku', 'expression' => 'NEW."sku"']],
            'readOnlyBecause' => null,
            'defaultsTo' => "'none'",
            'storedAs' => 'sku',
            'timeZone' => null,
        ], $resolved);
        $this->assertState(['column' => 'sku', 'retained' => true, 'type' => 'text', 'defaultsTo' => "'none'"] + self::MAPPED_TITLE, $original);
    }

    #[Test]
    public function it_resolves_a_read_only_retained_attribute_with_no_write(): void
    {
        $resolved = Attribute::retained('id')->readOnlyBecause('Other records hold the id.')->resolve('widget');

        $this->assertSame([], $resolved->writesTo);
        $this->assertSame(['widget', 'widget."id"', 'id', 'Other records hold the id.'], [$resolved->alias, $resolved->readAs, $resolved->storedAs, $resolved->readOnlyBecause]);
    }

    #[Test]
    #[TestWith(['before storedAs()'])]
    #[TestWith(['after storedAs()'])]
    public function it_converts_the_read_and_the_write_of_a_stored_attribute_to_its_time_zone(string $order): void
    {
        $typed = Attribute::mapped('delivered_at')->asType('timestamp(0) without time zone');

        $zoned = $order === 'before storedAs()'
            ? $typed->inTimeZone('UTC')->storedAs('gadget', 'delivered_at')
            : $typed->storedAs('gadget', 'delivered_at')->inTimeZone('UTC');

        $this->assertState([
            'column' => 'delivered_at',
            'type' => 'timestamp(0) without time zone',
            'alias' => 'gadget',
            'readAs' => 'gadget."delivered_at" AT TIME ZONE \'UTC\'',
            'writesTo' => [['alias' => 'gadget', 'column' => 'delivered_at', 'expression' => 'NEW."delivered_at" AT TIME ZONE \'UTC\'']],
            'storedAs' => 'delivered_at',
            'timeZone' => 'UTC',
        ] + self::MAPPED_TITLE, $zoned);
    }

    #[Test]
    public function it_sets_the_time_zone_on_a_copy_and_keeps_the_original_unconverted(): void
    {
        $original = Attribute::mapped('delivered_at')->asType('timestamp(0) without time zone')->storedAs('gadget', 'delivered_at');

        $original->inTimeZone('UTC');

        $this->assertSame([null, 'gadget."delivered_at"', 'NEW."delivered_at"'], [$original->timeZone, $original->readAs, $original->writesTo[0]->expression]);
    }

    #[Test]
    public function it_replaces_an_earlier_time_zone_rather_than_converting_twice(): void
    {
        $zoned = Attribute::mapped('delivered_at')->storedAs('gadget', 'delivered_at')->inTimeZone('UTC')->inTimeZone('America/Chicago');

        $this->assertSame(
            ['America/Chicago', 'gadget."delivered_at" AT TIME ZONE \'America/Chicago\'', ['NEW."delivered_at" AT TIME ZONE \'America/Chicago\'']],
            [$zoned->timeZone, $zoned->readAs, array_map(fn (TargetColumn $targetColumn) => $targetColumn->expression, $zoned->writesTo)],
        );
    }

    #[Test]
    public function it_converts_only_the_stored_write_and_keeps_a_later_write_as_written(): void
    {
        $zoned = Attribute::mapped('delivered_at')->storedAs('gadget', 'delivered_at')->writesTo('gadget', 'delivered_on', 'NEW."delivered_at"::date')->inTimeZone('UTC');

        $this->assertSame([
            ['alias' => 'gadget', 'column' => 'delivered_at', 'expression' => 'NEW."delivered_at" AT TIME ZONE \'UTC\''],
            ['alias' => 'gadget', 'column' => 'delivered_on', 'expression' => 'NEW."delivered_at"::date'],
        ], $this->state($zoned)['writesTo']);
    }

    #[Test]
    public function it_converts_the_read_of_a_read_only_attribute_and_adds_no_write(): void
    {
        $zoned = Attribute::mapped('created_at')->storedAs('gadget', 'created_at')->readOnlyBecause('A widget keeps the time it was created.')->inTimeZone('UTC');

        $this->assertSame(['gadget."created_at" AT TIME ZONE \'UTC\'', []], [$zoned->readAs, $zoned->writesTo]);
    }

    #[Test]
    public function it_quotes_a_time_zone_that_contains_a_single_quote(): void
    {
        $zoned = Attribute::mapped('delivered_at')->asType('timestamp')->storedAs('gadget', 'delivered_at')->inTimeZone("Mars/O'Neill");

        $this->assertSame(
            ['gadget."delivered_at" AT TIME ZONE \'Mars/O\'\'Neill\'', 'NEW."delivered_at" AT TIME ZONE \'Mars/O\'\'Neill\'', 'CAST(("delivered_at" AT TIME ZONE \'Mars/O\'\'Neill\') AS timestamp)'],
            [$zoned->readAs, $zoned->writesTo[0]->expression, $zoned->plannerExpression()],
        );
    }

    #[Test]
    public function it_keeps_the_time_zone_through_every_other_builder(): void
    {
        $zoned = Attribute::mapped('delivered_at')->inTimeZone('UTC');

        $this->assertSame(['UTC', 'UTC', 'UTC', 'UTC', 'UTC', 'UTC', 'UTC'], [
            $zoned->asType('timestamp')->timeZone,
            $zoned->storedAs('gadget', 'delivered_at')->timeZone,
            $zoned->readAs('gadget', 'gadget.delivered_at')->timeZone,
            $zoned->writesTo('gadget', 'delivered_at', 'NEW.delivered_at')->timeZone,
            $zoned->readOnlyBecause('A gadget records it.')->timeZone,
            $zoned->defaultsTo('now()')->timeZone,
            Attribute::retained('delivered_at')->inTimeZone('UTC')->resolve('widget')->timeZone,
        ]);
    }

    #[Test]
    public function it_keeps_the_time_zone_but_drops_the_stored_column_when_the_attribute_is_read_through_an_expression(): void
    {
        $read = Attribute::mapped('delivered_at')->storedAs('gadget', 'delivered_at')->inTimeZone('UTC')->readAs('gadget', 'gadget.delivered_at');

        $this->assertSame(['UTC', null, 'gadget.delivered_at', []], [$read->timeZone, $read->storedAs, $read->readAs, $read->writesTo]);
    }

    #[Test]
    public function it_exposes_the_planner_expression_of_a_stored_attribute_without_its_alias(): void
    {
        $this->assertSame([
            'CAST(("delivered_at" AT TIME ZONE \'UTC\') AS timestamp(0) without time zone)',
            'CAST(("archived") AS boolean)',
            'CAST(("na""me") AS text)',
        ], [
            Attribute::mapped('delivered_at')->asType('timestamp(0) without time zone')->storedAs('gadget', 'delivered_at')->inTimeZone('UTC')->plannerExpression(),
            Attribute::mapped('is_archived')->asType('boolean')->storedAs('gadget', 'archived')->plannerExpression(),
            Attribute::mapped('title')->asType('text')->storedAs('gadget', 'na"me')->plannerExpression(),
        ]);
    }

    #[Test]
    public function it_exposes_no_planner_expression_for_an_attribute_not_stored_in_one_typed_column(): void
    {
        $this->assertSame([null, null, null, null], [
            Attribute::mapped('price')->asType('text')->readAs('gadget', 'gadget.price_cents / 100.0')->plannerExpression(),
            Attribute::retained('sku')->asType('text')->resolve('widget')->plannerExpression(),
            Attribute::mapped('title')->storedAs('gadget', 'name')->plannerExpression(),
            Attribute::mapped('title')->asType('text')->plannerExpression(),
        ]);
    }

    #[Test]
    public function it_converts_between_the_view_and_its_column_in_its_own_time_zone_whatever_the_session_time_zone(): void
    {
        Tables::create();
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        DB::statement("set local time zone 'America/Chicago'");

        $id = DB::table('widgets')->insertGetId(['title' => 'Blue gadget', 'delivered_at' => '2026-10-28 12:00:00']);
        $inserted = $this->deliveredInUtc();
        DB::table('widgets')->where('id', $id)->update(['delivered_at' => '2026-10-29 08:30:00']);
        $updated = $this->deliveredInUtc();
        DB::table('gadgets')->update(['delivered_at' => '2026-10-30 09:15:00+00']);

        $this->assertSame(
            ['2026-10-28 12:00:00', '2026-10-29 08:30:00', '2026-10-30 09:15:00'],
            [$inserted, $updated, DB::table('widgets')->value('delivered_at')],
        );
    }

    private function deliveredInUtc(): string
    {
        return (string) DB::scalar("select to_char(delivered_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') from gadgets");
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function assertState(array $expected, Attribute $attribute): void
    {
        ksort($expected);

        $this->assertSame($expected, $this->state($attribute));
    }

    /**
     * @return array<string, mixed>
     */
    private function state(Attribute $attribute): array
    {
        $state = ['writesTo' => array_map(get_object_vars(...), $attribute->writesTo)] + get_object_vars($attribute);
        ksort($state);

        return $state;
    }
}
