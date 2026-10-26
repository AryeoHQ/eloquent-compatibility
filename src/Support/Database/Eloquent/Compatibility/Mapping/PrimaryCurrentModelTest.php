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
    private const array GADGET = ['model' => Gadget::class, 'alias' => null, 'fillWhenCreatedFromPrevious' => []];

    #[Test]
    public function it_declares_the_model_with_no_alias_and_nothing_to_fill(): void
    {
        $this->assertSame(self::GADGET, get_object_vars(PrimaryCurrentModel::for(Gadget::class)));
    }

    #[Test]
    public function it_sets_the_alias_on_a_copy_and_keeps_the_rest(): void
    {
        $original = PrimaryCurrentModel::for(Gadget::class)->fillWhenCreatedFromPrevious(['id' => 'gen_random_uuid()']);

        $aliased = $original->aliasedAs('gadget');

        $this->assertSame(['model' => Gadget::class, 'alias' => 'gadget', 'fillWhenCreatedFromPrevious' => ['id' => 'gen_random_uuid()']], get_object_vars($aliased));
        $this->assertNull($original->alias);
    }

    #[Test]
    public function it_replaces_the_values_to_fill_on_a_copy_and_keeps_the_rest(): void
    {
        $original = PrimaryCurrentModel::for(Gadget::class)->aliasedAs('gadget')->fillWhenCreatedFromPrevious(['name' => "'Untitled'"]);

        $filled = $original->fillWhenCreatedFromPrevious(['id' => 'COALESCE(NEW.gadget_id, gen_random_uuid())']);

        $this->assertSame(['model' => Gadget::class, 'alias' => 'gadget', 'fillWhenCreatedFromPrevious' => ['id' => 'COALESCE(NEW.gadget_id, gen_random_uuid())']], get_object_vars($filled));
        $this->assertSame(['name' => "'Untitled'"], $original->fillWhenCreatedFromPrevious);
    }
}
