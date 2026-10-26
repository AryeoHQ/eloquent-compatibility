<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\CompatibilityMaps;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\ExcludedListener;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Tests\Fixtures\Support\Workbench\Account;
use Tests\Fixtures\Support\Workbench\AccountCompatibilityMap;
use Tests\Fixtures\Support\Workbench\CompatibilityMapEventMirror;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\GadgetPlace;
use Tests\Fixtures\Support\Workbench\GadgetSite;
use Tests\Fixtures\Support\Workbench\Journal;
use Tests\Fixtures\Support\Workbench\Person;
use Tests\Fixtures\Support\Workbench\Profile;
use Tests\Fixtures\Support\Workbench\ProfileCompatibilityMap;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(MirrorIndex::class)]
final class MirrorIndexTest extends TestCase
{
    private MirrorIndex $mirrorIndex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mirrorIndex = new MirrorIndex;
    }

    #[Test]
    public function it_adds_a_map_and_returns_each_model_class_it_brings_in(): void
    {
        $this->assertSame([Widget::class, Gadget::class, GadgetPlace::class, GadgetSite::class], $this->add(new WidgetCompatibilityMap));
    }

    #[Test]
    public function it_returns_only_the_model_classes_no_earlier_map_brought_in(): void
    {
        $this->assertSame([Account::class, Person::class], $this->add(new AccountCompatibilityMap));
        $this->assertSame([Profile::class], $this->add(new ProfileCompatibilityMap));
    }

    #[Test]
    public function it_returns_the_resolved_map_the_key_translator_and_the_excluded_listeners_of_each_map(): void
    {
        $excludedListener = ExcludedListener::of(Journal::class, 'The journal records only writes through the current model.')->forEvent(ModelEvent::Updated);
        $widgets = resolve(CompatibilityMaps::class)->register(new WidgetCompatibilityMap);
        $this->mirrorIndex->add($widgets, new CompatibilityMapEventMirror(new WidgetCompatibilityMap, [$excludedListener]));
        $this->add(new AccountCompatibilityMap);

        $this->assertSame($widgets, $this->mirrorIndex->resolvedMap('widgets'));
        $this->assertSame('accounts', $this->mirrorIndex->resolvedMap('accounts')->previousModel->view);
        $this->assertSame($this->mirrorIndex->keyTranslator('widgets'), $this->mirrorIndex->keyTranslator('widgets'));
        $this->assertSame(['widgets', 'retained_widgets'], [$this->mirrorIndex->keyTranslator('widgets')->previousRecord(1)->map, $this->mirrorIndex->keyTranslator('widgets')->previousRecord(1)->lockTable]);
        $this->assertSame(['accounts', 'retained_accounts'], [$this->mirrorIndex->keyTranslator('accounts')->previousRecord(1)->map, $this->mirrorIndex->keyTranslator('accounts')->previousRecord(1)->lockTable]);
        $this->assertSame([$excludedListener], $this->mirrorIndex->excludedListeners('widgets'));
        $this->assertSame([], $this->mirrorIndex->excludedListeners('accounts'));
    }

    #[Test]
    public function it_lists_the_maps_and_aliases_a_model_belongs_to(): void
    {
        $this->addAll();

        $this->assertSame([['widgets', 'widget']], $this->members($this->mirrorIndex->forModel(Widget::class)));
        $this->assertSame([['widgets', 'place']], $this->members($this->mirrorIndex->forModel(GadgetPlace::class)));
        $this->assertSame([['accounts', 'person'], ['profiles', 'person']], $this->members($this->mirrorIndex->forModel(Person::class)));
        $this->assertSame([], $this->mirrorIndex->forModel(Journal::class));
    }

    #[Test]
    public function it_lists_the_maps_and_aliases_of_each_current_table_and_of_no_view_or_retained_table(): void
    {
        $this->addAll();

        $this->assertSame([['accounts', 'person'], ['profiles', 'person']], $this->members($this->mirrorIndex->forTable('people')));
        $this->assertSame([['widgets', 'gadget']], $this->members($this->mirrorIndex->forTable('gadgets')));
        $this->assertSame([['widgets', 'site']], $this->members($this->mirrorIndex->forTable('gadget_sites')));
        $this->assertSame([], $this->mirrorIndex->forTable('widgets'));
        $this->assertSame([], $this->mirrorIndex->forTable('retained_widgets'));
    }

    #[Test]
    public function it_lists_the_model_classes_over_a_relation_once(): void
    {
        $this->addAll();

        $this->assertSame([Person::class], $this->mirrorIndex->modelsOf('people'));
        $this->assertSame([Widget::class], $this->mirrorIndex->modelsOf('widgets'));
        $this->assertSame([GadgetPlace::class], $this->mirrorIndex->modelsOf('gadget_places'));
        $this->assertSame([], $this->mirrorIndex->modelsOf('retained_widgets'));
    }

    #[Test]
    public function it_lists_the_maps_over_a_relation_once(): void
    {
        $this->addAll();

        $this->assertSame(['accounts', 'profiles'], $this->mirrorIndex->mapsOf('people'));
        $this->assertSame(['profiles'], $this->mirrorIndex->mapsOf('profiles'));
        $this->assertSame(['widgets'], $this->mirrorIndex->mapsOf('gadgets'));
        $this->assertSame([], $this->mirrorIndex->mapsOf('retained_profiles'));
    }

    /**
     * @return list<string>
     */
    private function add(CompatibilityMap $map): array
    {
        return $this->mirrorIndex->add(resolve(CompatibilityMaps::class)->register($map), new CompatibilityMapEventMirror($map));
    }

    private function addAll(): void
    {
        $this->add(new WidgetCompatibilityMap);
        $this->add(new AccountCompatibilityMap);
        $this->add(new ProfileCompatibilityMap);
    }

    /**
     * @param  list<MapMember>  $members
     * @return list<array{string, string}>
     */
    private function members(array $members): array
    {
        return array_map(fn (MapMember $member) => [$member->map, $member->alias], $members);
    }
}
