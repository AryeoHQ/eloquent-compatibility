<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\TestCase;

#[CoversClass(PreviousModel::class)]
final class PreviousModelTest extends TestCase
{
    private const array WIDGETS = [
        'view' => 'widgets',
        'table' => 'retained_widgets',
        'model' => Widget::class,
        'key' => 'id',
        'alias' => 'widget',
        'primaryForeignKey' => 'gadget_id',
        'fillWhenCreatedFromCurrent' => ['status' => "'Draft'"],
    ];

    #[Test]
    public function it_declares_only_the_view(): void
    {
        $this->assertSame(
            ['view' => 'widgets', 'table' => null, 'model' => null, 'key' => null, 'alias' => null, 'primaryForeignKey' => null, 'fillWhenCreatedFromCurrent' => []],
            get_object_vars(PreviousModel::servedAsView('widgets')),
        );
    }

    #[Test]
    #[TestWith(['table', 'retainedInTable', 'legacy_widgets'])]
    #[TestWith(['model', 'forModel', Gadget::class])]
    #[TestWith(['key', 'keyedBy', 'uuid'])]
    #[TestWith(['alias', 'aliasedAs', 'legacy'])]
    #[TestWith(['primaryForeignKey', 'primaryForeignKey', 'owner_id'])]
    public function it_sets_one_property_on_a_copy_and_keeps_the_rest(string $property, string $method, string $value): void
    {
        $original = $this->widgets();

        $changed = $original->{$method}($value);

        $this->assertSame(array_replace(self::WIDGETS, [$property => $value]), get_object_vars($changed));
        $this->assertSame(self::WIDGETS, get_object_vars($original));
    }

    #[Test]
    public function it_replaces_the_values_to_fill_on_a_copy_and_keeps_the_rest(): void
    {
        $original = $this->widgets();

        $filled = $original->fillWhenCreatedFromCurrent(['status' => "CASE WHEN NEW.archived THEN 'Archived' ELSE 'Draft' END", 'sku' => "''"]);

        $this->assertSame(array_replace(self::WIDGETS, ['fillWhenCreatedFromCurrent' => ['status' => "CASE WHEN NEW.archived THEN 'Archived' ELSE 'Draft' END", 'sku' => "''"]]), get_object_vars($filled));
        $this->assertSame(self::WIDGETS, get_object_vars($original));
    }

    private function widgets(): PreviousModel
    {
        return PreviousModel::servedAsView('widgets')
            ->retainedInTable('retained_widgets')
            ->forModel(Widget::class)
            ->keyedBy('id')
            ->aliasedAs('widget')
            ->primaryForeignKey('gadget_id')
            ->fillWhenCreatedFromCurrent(['status' => "'Draft'"]);
    }
}
