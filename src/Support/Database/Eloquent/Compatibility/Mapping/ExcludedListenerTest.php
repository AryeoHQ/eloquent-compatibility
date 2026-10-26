<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\Support\Workbench\Journal;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\TestCase;

#[CoversClass(ExcludedListener::class)]
final class ExcludedListenerTest extends TestCase
{
    #[Test]
    public function it_excludes_a_listener_from_every_event_until_one_is_named(): void
    {
        $excludedListener = ExcludedListener::of(Journal::class, 'The journal records only writes through the current model.');

        $this->assertSame(['listener' => Journal::class, 'event' => null, 'because' => 'The journal records only writes through the current model.'], get_object_vars($excludedListener));
        $this->assertSame(ModelEvent::cases(), array_values(array_filter(ModelEvent::cases(), $excludedListener->appliesTo(...))));
    }

    #[Test]
    public function it_narrows_a_copy_to_one_event_and_leaves_the_original_unchanged(): void
    {
        $original = ExcludedListener::of(Journal::class, 'Journal only.');

        $narrowed = $original->forEvent(ModelEvent::Updated);

        $this->assertSame(['listener' => Journal::class, 'event' => ModelEvent::Updated, 'because' => 'Journal only.'], get_object_vars($narrowed));
        $this->assertSame([ModelEvent::Updated], array_values(array_filter(ModelEvent::cases(), $narrowed->appliesTo(...))));
        $this->assertNull($original->event);
    }

    #[Test]
    #[TestWith(['handle'])]
    #[TestWith(['onUpdated'])]
    public function it_matches_every_method_of_a_class_listener(string $method): void
    {
        $this->assertTrue(ExcludedListener::of(Journal::class, 'Journal only.')->matches(ModelEvent::Updated, Journal::class, $method));
    }

    #[Test]
    public function it_matches_only_the_method_a_listener_names(): void
    {
        $excludedListener = ExcludedListener::of(Journal::class.'@onUpdated', 'Journal only.');

        $this->assertTrue($excludedListener->matches(ModelEvent::Updated, Journal::class, 'onUpdated'));
        $this->assertFalse($excludedListener->matches(ModelEvent::Updated, Journal::class, 'handle'));
    }

    #[Test]
    public function it_matches_a_class_name_with_or_without_a_leading_backslash(): void
    {
        $this->assertTrue(ExcludedListener::of('\\'.Journal::class, 'Journal only.')->matches(ModelEvent::Updated, Journal::class, 'handle'));
        $this->assertTrue(ExcludedListener::of(Journal::class, 'Journal only.')->matches(ModelEvent::Updated, '\\'.Journal::class, 'handle'));
    }

    #[Test]
    public function it_does_not_match_another_class(): void
    {
        $this->assertFalse(ExcludedListener::of(Journal::class, 'Journal only.')->matches(ModelEvent::Updated, Widget::class, 'handle'));
    }

    #[Test]
    public function it_does_not_match_an_event_it_does_not_apply_to(): void
    {
        $excludedListener = ExcludedListener::of(Journal::class, 'Journal only.')->forEvent(ModelEvent::Updated);

        $this->assertFalse($excludedListener->matches(ModelEvent::Created, Journal::class, 'handle'));
        $this->assertTrue($excludedListener->matches(ModelEvent::Updated, Journal::class, 'handle'));
    }
}
