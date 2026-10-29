<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\Publication;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Archive;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Withdraw;
use Tests\TestCase;

#[CoversClass(PrimaryCurrentModel::class)]
final class PrimaryCurrentModelTest extends TestCase
{
    private const array GADGET = ['model' => Gadget::class, 'alias' => null, 'fillWhenCreatedFromPrevious' => [], 'triggerArguments' => []];

    #[Test]
    public function it_declares_the_model_with_no_alias_nothing_to_fill_and_no_trigger_arguments(): void
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
        $reason = fn (): string => 'retired';
        $original = PrimaryCurrentModel::for(Gadget::class)->fillWhenCreatedFromPrevious(['id' => 'gen_random_uuid()'])->triggerArguments(Archive::class, ['reason' => $reason]);

        $aliased = $original->aliasedAs('gadget');

        $this->assertSame(['model' => Gadget::class, 'alias' => 'gadget', 'fillWhenCreatedFromPrevious' => ['id' => 'gen_random_uuid()'], 'triggerArguments' => [Archive::class => ['reason' => $reason]]], get_object_vars($aliased));
        $this->assertNull($original->alias);
    }

    #[Test]
    public function it_replaces_the_values_to_fill_on_a_copy_and_keeps_the_rest(): void
    {
        $reason = fn (): string => 'retired';
        $original = PrimaryCurrentModel::for(Gadget::class)->aliasedAs('gadget')->fillWhenCreatedFromPrevious(['name' => "'Untitled'"])->triggerArguments(Archive::class, ['reason' => $reason]);

        $filled = $original->fillWhenCreatedFromPrevious(['id' => 'COALESCE(NEW.gadget_id, gen_random_uuid())']);

        $this->assertSame(['model' => Gadget::class, 'alias' => 'gadget', 'fillWhenCreatedFromPrevious' => ['id' => 'COALESCE(NEW.gadget_id, gen_random_uuid())'], 'triggerArguments' => [Archive::class => ['reason' => $reason]]], get_object_vars($filled));
        $this->assertSame(['name' => "'Untitled'"], $original->fillWhenCreatedFromPrevious);
    }

    #[Test]
    public function it_adds_the_arguments_of_a_trigger_on_a_copy_and_keeps_the_rest(): void
    {
        $reason = fn (): string => 'retired';
        $original = PrimaryCurrentModel::for(Publication::class)->aliasedAs('publication')->fillWhenCreatedFromPrevious(['state' => "'draft'"]);

        $declared = $original->triggerArguments(Archive::class, ['reason' => $reason]);

        $this->assertSame(['model' => Publication::class, 'alias' => 'publication', 'fillWhenCreatedFromPrevious' => ['state' => "'draft'"], 'triggerArguments' => [Archive::class => ['reason' => $reason]]], get_object_vars($declared));
        $this->assertSame([], $original->triggerArguments);
    }

    #[Test]
    public function it_replaces_the_arguments_of_a_trigger_declared_again_and_keeps_those_of_other_triggers(): void
    {
        $first = fn (): string => 'retired';
        $withdrawn = fn (): string => 'withdrawn';
        $second = fn (): string => 'superseded';
        $original = PrimaryCurrentModel::for(Publication::class)->triggerArguments(Archive::class, ['reason' => $first])->triggerArguments(Withdraw::class, ['reason' => $withdrawn]);

        $replaced = $original->triggerArguments(Archive::class, ['REASON' => $second]);

        $this->assertSame([Archive::class => ['REASON' => $second], Withdraw::class => ['reason' => $withdrawn]], $replaced->triggerArguments);
        $this->assertSame([Archive::class => ['reason' => $first], Withdraw::class => ['reason' => $withdrawn]], $original->triggerArguments);
    }
}
