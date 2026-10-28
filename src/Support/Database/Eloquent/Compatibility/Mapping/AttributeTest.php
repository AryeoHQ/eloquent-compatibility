<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
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
