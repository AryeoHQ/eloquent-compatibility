<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(UnrepresentableState::class)]
final class UnrepresentableStateTest extends TestCase
{
    private const array REFUSED = [
        'kind' => UnrepresentableStateKind::Refuse,
        'name' => 'second_root_place',
        'description' => 'a second root place for one gadget',
        'because' => 'A widget has one street.',
        'instead' => 'Give the new place a parent_id.',
        'checkedOn' => null,
        'checkCondition' => null,
        'previousUpdateCondition' => null,
    ];

    #[Test]
    public function it_declares_a_refused_state_with_nothing_to_check(): void
    {
        $this->assertSame(self::REFUSED, get_object_vars($this->refused()));
    }

    #[Test]
    public function it_declares_a_degraded_state_with_its_rule_and_no_alternative(): void
    {
        $this->assertSame(
            ['kind' => UnrepresentableStateKind::Degrade, 'name' => 'child_place', 'description' => 'a place under another place', 'because' => 'The widget shows only the root place.', 'instead' => '', 'checkedOn' => null, 'checkCondition' => null, 'previousUpdateCondition' => null],
            get_object_vars(UnrepresentableState::degrade('child_place', 'a place under another place', 'The widget shows only the root place.')),
        );
    }

    #[Test]
    public function it_declares_a_hidden_state_with_its_condition_and_no_alternative(): void
    {
        $this->assertSame(
            ['kind' => UnrepresentableStateKind::Hide, 'name' => 'retired', 'description' => 'a retired widget', 'because' => 'Retired widgets predate gadgets.', 'instead' => '', 'checkedOn' => null, 'checkCondition' => "widget.status = 'Retired'", 'previousUpdateCondition' => null],
            get_object_vars(UnrepresentableState::hide('retired', 'a retired widget', 'Retired widgets predate gadgets.', "widget.status = 'Retired'")),
        );
    }

    #[Test]
    public function it_checks_a_copy_on_an_alias_and_keeps_the_rest(): void
    {
        $original = $this->refused()->checkedOnPreviousUpdate('NEW.status IS DISTINCT FROM OLD.status');

        $checked = $original->checkedOn('place', 'place.parent_id IS NULL');

        $this->assertSame(array_replace(self::REFUSED, ['checkedOn' => 'place', 'checkCondition' => 'place.parent_id IS NULL', 'previousUpdateCondition' => 'NEW.status IS DISTINCT FROM OLD.status']), get_object_vars($checked));
        $this->assertSame(array_replace(self::REFUSED, ['previousUpdateCondition' => 'NEW.status IS DISTINCT FROM OLD.status']), get_object_vars($original));
    }

    #[Test]
    public function it_replaces_a_hidden_states_condition_when_checked_on_an_alias(): void
    {
        $checked = UnrepresentableState::hide('retired', 'a retired widget', 'Retired widgets predate gadgets.', "widget.status = 'Retired'")->checkedOn('widget', 'widget.gadget_id IS NULL');

        $this->assertSame(['widget', 'widget.gadget_id IS NULL', UnrepresentableStateKind::Hide], [$checked->checkedOn, $checked->checkCondition, $checked->kind]);
    }

    #[Test]
    public function it_checks_a_copy_on_the_previous_update_and_keeps_the_rest(): void
    {
        $original = $this->refused()->checkedOn('place', 'place.parent_id IS NULL');

        $checked = $original->checkedOnPreviousUpdate('OLD.is_archived');

        $this->assertSame(array_replace(self::REFUSED, ['checkedOn' => 'place', 'checkCondition' => 'place.parent_id IS NULL', 'previousUpdateCondition' => 'OLD.is_archived']), get_object_vars($checked));
        $this->assertNull($original->previousUpdateCondition);
    }

    private function refused(): UnrepresentableState
    {
        return UnrepresentableState::refuse('second_root_place', 'a second root place for one gadget', 'A widget has one street.', 'Give the new place a parent_id.');
    }
}
