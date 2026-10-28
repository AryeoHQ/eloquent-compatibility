<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\CompatibilityMaps;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Tests\Fixtures\Support\Workbench\Account;
use Tests\Fixtures\Support\Workbench\AccountCompatibilityMap;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\GadgetPlace;
use Tests\Fixtures\Support\Workbench\GadgetSite;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(KeyTranslator::class)]
final class KeyTranslatorTest extends TestCase
{
    private KeyTranslator $keyTranslator;

    protected function setUp(): void
    {
        parent::setUp();

        Tables::create();
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        $this->keyTranslator = $this->keyTranslator(new WidgetCompatibilityMap);
    }

    #[Test]
    public function it_describes_a_previous_record_by_its_view_and_locks_it_through_its_retained_table(): void
    {
        $this->assertSame(['widgets', Widget::class, 'widgets', 'retained_widgets', 'id', 7, RecordRole::Previous], $this->describe($this->keyTranslator->previousRecord(7)));
        $this->assertSame(['widgets', Widget::class, 'widgets', 'retained_widgets', 'id', '7', RecordRole::Previous], $this->describe($this->keyTranslator->previousRecord('7')));
    }

    #[Test]
    public function it_describes_a_current_record_by_its_table_and_its_role_in_the_map(): void
    {
        $key = (string) Str::uuid();

        $this->assertSame(['widgets', Gadget::class, 'gadgets', 'gadgets', 'id', $key, RecordRole::Primary], $this->describe($this->keyTranslator->currentRecord('gadget', $key)));
        $this->assertSame(['widgets', GadgetPlace::class, 'gadget_places', 'gadget_places', 'id', $key, RecordRole::Dependent], $this->describe($this->keyTranslator->currentRecord('place', $key)));
        $this->assertSame(['widgets', GadgetSite::class, 'gadget_sites', 'gadget_sites', 'id', $key, RecordRole::Dependent], $this->describe($this->keyTranslator->currentRecord('site', $key)));
    }

    #[Test]
    public function it_translates_a_previous_key_into_the_key_of_each_current_record_the_view_reads(): void
    {
        $widget = $this->widget();
        $root = $this->rootPlace($widget);
        GadgetPlace::query()->create(['gadget_id' => $widget->gadget_id, 'parent_id' => $root, 'street' => '2 Child St']);
        GadgetPlace::query()->create(['gadget_id' => $widget->gadget_id, 'street' => '3 Old St', 'deleted_at' => now()]);

        $this->assertSame([
            'gadget' => $widget->gadget_id,
            'place' => $root,
            'site' => DB::table('gadget_sites')->where('gadget_id', $widget->gadget_id)->value('id'),
        ], $this->keyTranslator->currentKeys(DB::connection(), $widget->getKey()));
    }

    #[Test]
    public function it_leaves_out_each_current_record_a_previous_key_has_none_of(): void
    {
        $widget = Widget::query()->create(['title' => 'No place yet'])->refresh();
        $retired = DB::table('retained_widgets')->insertGetId(['status' => 'Retired']);

        $this->assertSame(['gadget' => $widget->gadget_id], $this->keyTranslator->currentKeys(DB::connection(), $widget->getKey()));
        $this->assertSame([], $this->keyTranslator->currentKeys(DB::connection(), $retired));
        $this->assertSame([], $this->keyTranslator->currentKeys(DB::connection(), $retired + 1));
    }

    #[Test]
    public function it_lists_every_dependent_row_under_the_primary_row_of_a_previous_key(): void
    {
        $widget = $this->widget();
        $root = $this->rootPlace($widget);
        $places = [
            $root,
            GadgetPlace::query()->create(['gadget_id' => $widget->gadget_id, 'parent_id' => $root, 'street' => '2 Child St'])->id,
            GadgetPlace::query()->create(['gadget_id' => $widget->gadget_id, 'street' => '3 Old St', 'deleted_at' => now()])->id,
        ];
        sort($places);
        $bare = Widget::query()->create(['title' => 'No place yet']);

        $this->assertSame([
            'place' => $places,
            'site' => [DB::table('gadget_sites')->where('gadget_id', $widget->gadget_id)->value('id')],
        ], $this->keyTranslator->dependentKeys(DB::connection(), $widget->getKey()));
        $this->assertSame(['place' => [], 'site' => []], $this->keyTranslator->dependentKeys(DB::connection(), $bare->getKey()));
        $this->assertSame(['place' => [], 'site' => []], $this->keyTranslator->dependentKeys(DB::connection(), $bare->getKey() + 1));
    }

    #[Test]
    public function it_lists_every_previous_key_over_a_primary_key_in_key_order(): void
    {
        $widget = $this->widget();
        $second = DB::table('retained_widgets')->insertGetId(['gadget_id' => $widget->gadget_id]);
        $other = Widget::query()->create(['title' => 'Other gadget']);

        $this->assertSame([$widget->getKey(), $second], $this->keyTranslator->previousKeys(DB::connection(), (string) $widget->gadget_id));
        $this->assertSame([$other->getKey()], $this->keyTranslator->previousKeys(DB::connection(), (string) $other->refresh()->gadget_id));
        $this->assertSame([], $this->keyTranslator->previousKeys(DB::connection(), (string) Str::uuid()));
    }

    #[Test]
    public function it_reads_the_previous_key_its_own_map_created_last_in_the_transaction(): void
    {
        resolve(Installer::class)->install(new AccountCompatibilityMap);
        $accounts = $this->keyTranslator(new AccountCompatibilityMap);

        $this->assertNull($this->keyTranslator->createdPreviousKey(DB::connection()));

        $widget = $this->widget();

        $this->assertSame((string) $widget->getKey(), $this->keyTranslator->createdPreviousKey(DB::connection()));
        $this->assertNull($accounts->createdPreviousKey(DB::connection()));

        $gadget = Gadget::query()->create(['name' => 'Native gadget']);
        $account = Account::query()->create(['email' => 'one@example.test']);

        $this->assertSame((string) DB::table('retained_widgets')->where('gadget_id', $gadget->getKey())->value('id'), $this->keyTranslator->createdPreviousKey(DB::connection()));
        $this->assertSame((string) $account->getKey(), $accounts->createdPreviousKey(DB::connection()));
    }

    #[Test]
    public function it_clears_the_previous_key_its_own_map_created_and_leaves_another_maps_alone(): void
    {
        resolve(Installer::class)->install(new AccountCompatibilityMap);
        $accounts = $this->keyTranslator(new AccountCompatibilityMap);
        $this->widget();
        $account = Account::query()->create(['email' => 'one@example.test']);

        $this->keyTranslator->clearCreatedPreviousKey(DB::connection());

        $this->assertNull($this->keyTranslator->createdPreviousKey(DB::connection()));
        $this->assertSame((string) $account->getKey(), $accounts->createdPreviousKey(DB::connection()));
    }

    private function keyTranslator(CompatibilityMap $map): KeyTranslator
    {
        return new KeyTranslator(resolve(CompatibilityMaps::class)->register($map));
    }

    private function widget(): Widget
    {
        return Widget::query()->create(['title' => 'Blue gadget', 'price' => '12.50', 'street' => '1 Main St', 'latitude' => 47.5])->refresh();
    }

    private function rootPlace(Widget $widget): string
    {
        return (string) DB::table('gadget_places')->where('gadget_id', $widget->gadget_id)->whereNull('parent_id')->value('id');
    }

    /**
     * @return list<mixed>
     */
    private function describe(AffectedRecord $record): array
    {
        return [$record->map, $record->model, $record->readFrom, $record->lockedTable, $record->keyColumn, $record->keyValue, $record->role];
    }
}
