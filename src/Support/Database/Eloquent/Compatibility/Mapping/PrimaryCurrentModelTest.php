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
    private const array GADGET = ['model' => Gadget::class, 'alias' => null, 'fillWhenCreatedFromPrevious' => [], 'triggerArguments' => [], 'indexes' => []];

    #[Test]
    public function it_declares_the_model_with_no_alias_nothing_to_fill_no_trigger_arguments_and_no_index(): void
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
        $index = PlannerIndex::named('newest')->on('created_at', descending: true);
        $original = PrimaryCurrentModel::for(Gadget::class)->fillWhenCreatedFromPrevious(['id' => 'gen_random_uuid()'])->triggerArguments(Archive::class, ['reason' => $reason])->indexedBy($index);

        $aliased = $original->aliasedAs('gadget');

        $this->assertSame(['model' => Gadget::class, 'alias' => 'gadget', 'fillWhenCreatedFromPrevious' => ['id' => 'gen_random_uuid()'], 'triggerArguments' => [Archive::class => ['reason' => $reason]], 'indexes' => [$index]], get_object_vars($aliased));
        $this->assertNull($original->alias);
    }

    #[Test]
    public function it_replaces_the_values_to_fill_on_a_copy_and_keeps_the_rest(): void
    {
        $reason = fn (): string => 'retired';
        $index = PlannerIndex::named('newest')->on('created_at', descending: true);
        $original = PrimaryCurrentModel::for(Gadget::class)->aliasedAs('gadget')->fillWhenCreatedFromPrevious(['name' => "'Untitled'"])->triggerArguments(Archive::class, ['reason' => $reason])->indexedBy($index);

        $filled = $original->fillWhenCreatedFromPrevious(['id' => 'COALESCE(NEW.gadget_id, gen_random_uuid())']);

        $this->assertSame(['model' => Gadget::class, 'alias' => 'gadget', 'fillWhenCreatedFromPrevious' => ['id' => 'COALESCE(NEW.gadget_id, gen_random_uuid())'], 'triggerArguments' => [Archive::class => ['reason' => $reason]], 'indexes' => [$index]], get_object_vars($filled));
        $this->assertSame(['name' => "'Untitled'"], $original->fillWhenCreatedFromPrevious);
    }

    #[Test]
    public function it_adds_the_arguments_of_a_trigger_on_a_copy_and_keeps_the_rest(): void
    {
        $reason = fn (): string => 'retired';
        $index = PlannerIndex::named('newest')->on('created_at', descending: true);
        $original = PrimaryCurrentModel::for(Publication::class)->aliasedAs('publication')->fillWhenCreatedFromPrevious(['state' => "'draft'"])->indexedBy($index);

        $declared = $original->triggerArguments(Archive::class, ['reason' => $reason]);

        $this->assertSame(['model' => Publication::class, 'alias' => 'publication', 'fillWhenCreatedFromPrevious' => ['state' => "'draft'"], 'triggerArguments' => [Archive::class => ['reason' => $reason]], 'indexes' => [$index]], get_object_vars($declared));
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

    #[Test]
    public function it_adds_the_indexes_on_a_copy_after_those_declared_before_and_keeps_the_rest(): void
    {
        $reason = fn (): string => 'retired';
        $newest = PlannerIndex::named('newest')->on('created_at', descending: true);
        $archived = PlannerIndex::named('archived')->on('is_archived');
        $delivered = PlannerIndex::named('delivered')->on('delivered_at')->whereNotNull('delivered_at');
        $original = PrimaryCurrentModel::for(Gadget::class)->aliasedAs('gadget')->fillWhenCreatedFromPrevious(['id' => 'gen_random_uuid()'])->triggerArguments(Archive::class, ['reason' => $reason])->indexedBy($newest);

        $indexed = $original->indexedBy($archived, $delivered);

        $this->assertSame(['model' => Gadget::class, 'alias' => 'gadget', 'fillWhenCreatedFromPrevious' => ['id' => 'gen_random_uuid()'], 'triggerArguments' => [Archive::class => ['reason' => $reason]], 'indexes' => [$newest, $archived, $delivered]], get_object_vars($indexed));
        $this->assertSame([$newest], $original->indexes);
    }

    #[Test]
    public function it_lists_the_indexes_in_order_when_they_are_passed_by_name(): void
    {
        $newest = PlannerIndex::named('newest')->on('created_at', descending: true);
        $archived = PlannerIndex::named('archived')->on('is_archived');

        $this->assertSame([$newest, $archived], PrimaryCurrentModel::for(Gadget::class)->indexedBy(...['first' => $newest, 'second' => $archived])->indexes);
    }
}
