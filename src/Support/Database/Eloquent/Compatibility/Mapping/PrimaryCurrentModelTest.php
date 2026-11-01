<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\TestCase;

#[CoversClass(PrimaryCurrentModel::class)]
final class PrimaryCurrentModelTest extends TestCase
{
    private const array GADGET = ['model' => Gadget::class, 'alias' => null, 'fillWhenCreatedFromPrevious' => [], 'creating' => []];

    #[Test]
    public function it_declares_the_model_with_no_alias_and_nothing_to_fill(): void
    {
        $this->assertSame(self::GADGET, get_object_vars(PrimaryCurrentModel::for(Gadget::class)));
    }

    #[Test]
    public function it_declares_the_model_by_its_declared_class_name(): void
    {
        $this->assertSame(Gadget::class, PrimaryCurrentModel::for('\\'.Gadget::class)->model);
    }

    #[Test]
    public function it_sets_the_alias_on_a_copy_and_keeps_the_rest(): void
    {
        $fill = fn () => 'id';
        $original = PrimaryCurrentModel::for(Gadget::class)->fillWhenCreatedFromPrevious(['id' => $fill]);

        $aliased = $original->aliasedAs('gadget');

        $this->assertSame(['model' => Gadget::class, 'alias' => 'gadget', 'fillWhenCreatedFromPrevious' => ['id' => $fill], 'creating' => []], get_object_vars($aliased));
        $this->assertNull($original->alias);
    }

    #[Test]
    public function it_replaces_the_values_to_fill_on_a_copy_and_keeps_the_rest(): void
    {
        $untitled = fn () => 'Untitled';
        $gadgetId = fn (array $widget) => $widget['gadget_id'] ?? null;
        $original = PrimaryCurrentModel::for(Gadget::class)->aliasedAs('gadget')->fillWhenCreatedFromPrevious(['name' => $untitled]);

        $filled = $original->fillWhenCreatedFromPrevious(['id' => $gadgetId]);

        $this->assertSame(['model' => Gadget::class, 'alias' => 'gadget', 'fillWhenCreatedFromPrevious' => ['id' => $gadgetId], 'creating' => []], get_object_vars($filled));
        $this->assertSame(['name' => $untitled], $original->fillWhenCreatedFromPrevious);
    }

    #[Test]
    public function it_appends_a_creation_hook_on_a_copy_and_keeps_the_rest(): void
    {
        $original = PrimaryCurrentModel::for(Gadget::class)->aliasedAs('gadget');
        $first = fn (Gadget $gadget) => $gadget->setAttribute('name', 'First');
        $second = fn (Gadget $gadget) => $gadget->setAttribute('name', 'Second');

        $hooked = $original->onCreate($first)->onCreate($second);

        $this->assertSame([$first, $second], $hooked->creating);
        $this->assertSame([], $original->creating);
    }
}
