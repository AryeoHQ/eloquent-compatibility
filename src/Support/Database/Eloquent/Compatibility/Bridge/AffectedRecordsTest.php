<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Support\Database\Eloquent\Compatibility\CompatibilityMaps;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Tests\Fixtures\Support\Workbench\Account;
use Tests\Fixtures\Support\Workbench\AccountCompatibilityMap;
use Tests\Fixtures\Support\Workbench\CompatibilityMapEventMirror;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\GadgetPlace;
use Tests\Fixtures\Support\Workbench\Person;
use Tests\Fixtures\Support\Workbench\ProfileCompatibilityMap;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(AffectedRecords::class)]
final class AffectedRecordsTest extends TestCase
{
    private MirrorIndex $mirrorIndex;

    private AffectedRecords $affectedRecords;

    /** @var null|list<array{string, list<mixed>}> */
    private null|array $locks = null;

    protected function setUp(): void
    {
        parent::setUp();

        Tables::create();
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        $this->mirrorIndex = new MirrorIndex;
        $this->add(new WidgetCompatibilityMap);
        $this->affectedRecords = new AffectedRecords($this->mirrorIndex);

        DB::listen(function (QueryExecuted $query): void {
            if ($this->locks !== null && str_ends_with($query->sql, ' for update')) {
                $this->locks[] = [$query->sql, array_values($query->bindings)];
            }
        });
    }

    protected function tearDown(): void
    {
        Model::preventAccessingMissingAttributes(false);

        parent::tearDown();
    }

    #[Test]
    public function it_finds_the_current_records_of_a_previous_model_update(): void
    {
        $widget = $this->widget();
        [$gadget, $place, $site] = $this->currentKeys($widget);

        $this->assertSame($this->sorted([
            'gadgets#'.$gadget => RecordRole::Primary,
            'gadget_places#'.$place => RecordRole::Dependent,
            'gadget_sites#'.$site => RecordRole::Dependent,
        ]), $this->roles($this->affectedRecords->of(DB::connection(), $widget, Operation::Update, false)));
    }

    #[Test]
    public function it_finds_no_record_before_a_previous_model_insert_and_the_created_current_records_after_it(): void
    {
        $widget = new Widget(['title' => 'Blue gadget', 'price' => '12.50', 'street' => '1 Main St', 'latitude' => 47.5]);

        $this->assertSame([], $this->affectedRecords->of(DB::connection(), $widget, Operation::Insert, false));
        $this->assertSame([[], []], $this->locking(fn () => $this->affectedRecords->before(DB::connection(), $widget, Operation::Insert)));

        $widget->save();
        [$gadget, $place, $site] = $this->currentKeys($widget->refresh());

        $this->assertSame($this->sorted([
            'gadgets#'.$gadget => RecordRole::Primary,
            'gadget_places#'.$place => RecordRole::Dependent,
            'gadget_sites#'.$site => RecordRole::Dependent,
        ]), $this->roles($this->affectedRecords->of(DB::connection(), $widget, Operation::Insert, true)));
    }

    #[Test]
    public function it_finds_every_dependent_row_a_previous_model_delete_removes(): void
    {
        $widget = $this->widget();
        [$gadget, $root, $site] = $this->currentKeys($widget);
        $child = GadgetPlace::query()->create(['gadget_id' => $gadget, 'parent_id' => $root, 'street' => '2 Child St'])->id;
        $old = GadgetPlace::query()->create(['gadget_id' => $gadget, 'street' => '3 Old St', 'deleted_at' => now()])->id;

        $records = $this->duringDelete($widget, true, fn () => $this->affectedRecords->of(DB::connection(), $widget, Operation::Delete, false));

        $this->assertSame($this->sorted([
            'gadgets#'.$gadget => RecordRole::Primary,
            'gadget_places#'.$root => RecordRole::Dependent,
            'gadget_places#'.$child => RecordRole::Dependent,
            'gadget_places#'.$old => RecordRole::Dependent,
            'gadget_sites#'.$site => RecordRole::Dependent,
        ]), $this->roles($records));
    }

    #[Test]
    public function it_keeps_a_soft_delete_to_the_dependent_rows_the_previous_model_reads(): void
    {
        $widget = $this->widget();
        [$gadget, $root, $site] = $this->currentKeys($widget);
        GadgetPlace::query()->create(['gadget_id' => $gadget, 'parent_id' => $root, 'street' => '2 Child St']);

        $records = $this->duringDelete($widget, false, fn () => $this->affectedRecords->of(DB::connection(), $widget, Operation::Delete, false));

        $this->assertSame($this->sorted([
            'gadgets#'.$gadget => RecordRole::Primary,
            'gadget_places#'.$root => RecordRole::Dependent,
            'gadget_sites#'.$site => RecordRole::Dependent,
        ]), $this->roles($records));
    }

    #[Test]
    public function it_finds_every_previous_record_of_a_primary_current_model_write(): void
    {
        $widget = $this->widget();
        $gadget = Gadget::query()->findOrFail($widget->gadget_id);
        $second = DB::table('retained_widgets')->insertGetId(['gadget_id' => $gadget->getKey()]);

        $this->assertSame($this->sorted([
            'widgets#'.$widget->getKey() => RecordRole::Previous,
            'widgets#'.$second => RecordRole::Previous,
        ]), $this->roles($this->affectedRecords->of(DB::connection(), $gadget, Operation::Update, false)));
    }

    #[Test]
    public function it_finds_no_record_before_a_primary_current_model_insert_and_the_created_previous_record_after_it(): void
    {
        $gadget = new Gadget(['name' => 'Native gadget']);

        $this->assertSame([], $this->affectedRecords->of(DB::connection(), $gadget, Operation::Insert, false));

        $gadget->save();
        $created = DB::table('retained_widgets')->where('gadget_id', $gadget->getKey())->value('id');

        $this->assertSame(
            ['widgets#'.$created => RecordRole::Previous],
            $this->roles($this->affectedRecords->of(DB::connection(), $gadget, Operation::Insert, true)),
        );
    }

    #[Test]
    public function it_finds_the_created_previous_record_after_a_primary_current_model_insert_that_holds_no_key(): void
    {
        DB::table('gadgets')->insert(['name' => 'Raw gadget']);
        $created = DB::table('retained_widgets')->value('id');

        $this->assertSame(
            ['widgets#'.$created => RecordRole::Previous],
            $this->roles($this->affectedRecords->of(DB::connection(), new Gadget(['name' => 'Raw gadget']), Operation::Insert, true)),
        );
    }

    #[Test]
    public function it_finds_no_created_record_after_an_insert_that_inserted_no_row(): void
    {
        Gadget::query()->create(['name' => 'Created earlier']);
        $gadget = new Gadget(['name' => 'Never inserted']);

        $this->affectedRecords->before(DB::connection(), $gadget, Operation::Insert);

        $this->assertSame([], $this->affectedRecords->of(DB::connection(), $gadget, Operation::Insert, true));
    }

    #[Test]
    #[TestWith([Operation::Update])]
    #[TestWith([Operation::Delete])]
    public function it_finds_no_record_of_a_primary_current_model_write_whose_key_resolves_to_no_row_after_a_create_in_the_transaction(Operation $operation): void
    {
        Gadget::query()->create(['name' => 'Created earlier']);
        $gadget = (new Gadget)->newFromBuilder(['name' => 'Loaded without its key']);

        $this->assertSame([], $this->affectedRecords->of(DB::connection(), $gadget, $operation, false));
        $this->assertSame([], $this->affectedRecords->of(DB::connection(), $gadget, $operation, true));
    }

    #[Test]
    public function it_finds_the_previous_record_of_a_dependent_current_model_before_its_insert(): void
    {
        $widget = $this->widget();
        [$gadget, $root] = $this->currentKeys($widget);
        $place = new GadgetPlace(['gadget_id' => $gadget, 'parent_id' => $root, 'street' => '2 Child St']);

        $this->assertSame(
            ['widgets#'.$widget->getKey() => RecordRole::Previous],
            $this->roles($this->affectedRecords->of(DB::connection(), $place, Operation::Insert, false)),
        );
    }

    #[Test]
    public function it_finds_the_previous_records_of_both_primary_rows_a_dependent_moves_between(): void
    {
        $first = $this->widget();
        $second = Widget::query()->create(['title' => 'Second gadget'])->refresh();
        $place = GadgetPlace::query()->where('gadget_id', $first->gadget_id)->sole();
        $place->setAttribute('gadget_id', $second->gadget_id);

        $this->assertSame($this->sorted([
            'widgets#'.$first->getKey() => RecordRole::Previous,
            'widgets#'.$second->getKey() => RecordRole::Previous,
        ]), $this->roles($this->affectedRecords->of(DB::connection(), $place, Operation::Update, false)));
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function it_finds_the_current_records_of_a_previous_model_loaded_without_its_key(bool $strict): void
    {
        [$gadget, $place, $site] = $this->currentKeys($this->widget());
        Widget::query()->create(['title' => 'Second gadget']);
        Model::preventAccessingMissingAttributes($strict);
        $widget = Widget::query()->select(['uuid', 'title'])->where('title', 'Blue gadget')->firstOrFail();
        $widget->setKeyName('uuid')->setKeyType('string')->setIncrementing(false);

        $this->assertSame($this->sorted([
            'gadgets#'.$gadget => RecordRole::Primary,
            'gadget_places#'.$place => RecordRole::Dependent,
            'gadget_sites#'.$site => RecordRole::Dependent,
        ]), $this->roles($this->affectedRecords->of(DB::connection(), $widget, Operation::Update, false)));
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function it_finds_the_previous_records_of_a_primary_current_model_loaded_without_its_key(bool $strict): void
    {
        $widget = $this->widget();
        Widget::query()->create(['title' => 'Second gadget']);
        Model::preventAccessingMissingAttributes($strict);
        $gadget = Gadget::query()->select(['name'])->where('name', 'Blue gadget')->firstOrFail();
        $gadget->setKeyName('name')->setKeyType('string')->setIncrementing(false);

        $this->assertSame(
            ['widgets#'.$widget->getKey() => RecordRole::Previous],
            $this->roles($this->affectedRecords->of(DB::connection(), $gadget, Operation::Update, false)),
        );
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function it_finds_the_previous_record_of_a_dependent_current_model_loaded_without_its_primary_foreign_key(bool $strict): void
    {
        $widget = $this->widget();
        Widget::query()->create(['title' => 'Second gadget', 'street' => '9 Other St']);
        Model::preventAccessingMissingAttributes($strict);
        $place = GadgetPlace::query()->select(['id', 'street'])->where('street', '1 Main St')->firstOrFail();

        $this->assertSame(
            ['widgets#'.$widget->getKey() => RecordRole::Previous],
            $this->roles($this->affectedRecords->of(DB::connection(), $place, Operation::Update, false)),
        );
    }

    #[Test]
    public function it_finds_the_previous_records_of_every_other_map_over_a_current_record(): void
    {
        $this->addAccountsAndProfiles();
        $account = Account::query()->create(['email' => 'one@example.test'])->refresh();
        $person = (string) $account->getAttribute('person_id');
        $profile = DB::table('retained_profiles')->where('person_id', $person)->value('id');

        $this->assertSame($this->sorted([
            'people#'.$person => RecordRole::Primary,
            'profiles#'.$profile => RecordRole::Previous,
        ]), $this->roles($this->affectedRecords->of(DB::connection(), $account, Operation::Update, false)));
        $this->assertSame($this->sorted([
            'accounts#'.$account->getKey() => RecordRole::Previous,
            'profiles#'.$profile => RecordRole::Previous,
        ]), $this->roles($this->affectedRecords->of(DB::connection(), Person::query()->findOrFail($person), Operation::Update, false)));
    }

    #[Test]
    public function it_snapshots_every_affected_record_as_it_stands_before_the_statement(): void
    {
        $widget = $this->widget();
        [$gadget, $place, $site] = $this->currentKeys($widget);
        $widget->setAttribute('title', 'Renamed');

        $snapshots = $this->affectedRecords->before(DB::connection(), $widget, Operation::Update);

        $this->assertSame(['gadgets#'.$gadget, 'gadget_places#'.$place, 'gadget_sites#'.$site], array_keys($snapshots));
        $this->assertSame(array_keys($snapshots), array_values(array_map(fn (RecordSnapshot $snapshot) => $snapshot->record->id(), $snapshots)));
        $this->assertSame('Blue gadget', $snapshots['gadgets#'.$gadget]->row['name'] ?? null);
        $this->assertSame('1 Main St', $snapshots['gadget_places#'.$place]->row['street'] ?? null);
        $this->assertSame('47.500000000000', $snapshots['gadget_sites#'.$site]->row['latitude'] ?? null);
    }

    #[Test]
    public function it_locks_the_primary_row_then_the_retained_row_then_the_dependent_rows_before_a_previous_model_write(): void
    {
        $widget = $this->widget();
        [$gadget, $place, $site] = $this->currentKeys($widget);

        [, $locks] = $this->locking(fn () => $this->affectedRecords->before(DB::connection(), $widget, Operation::Update));

        $this->assertSame([
            ['select 1 from "gadgets" where "id" = ? for update', [$gadget]],
            ['select 1 from "retained_widgets" where "id" = ? for update', [$widget->getKey()]],
            ['select 1 from "gadget_places" where "id" = ? for update', [$place]],
            ['select 1 from "gadget_sites" where "id" = ? for update', [$site]],
        ], $locks);
    }

    #[Test]
    public function it_locks_the_own_row_of_a_current_model_in_the_same_order_and_snapshots_only_the_records_it_mirrors(): void
    {
        $widget = $this->widget();
        [$gadget, $place] = $this->currentKeys($widget);

        [$snapshots, $locks] = $this->locking(fn () => $this->affectedRecords->before(DB::connection(), Gadget::query()->findOrFail($gadget), Operation::Update));

        $this->assertSame([
            ['select 1 from "gadgets" where "id" = ? for update', [$gadget]],
            ['select 1 from "retained_widgets" where "id" = ? for update', [$widget->getKey()]],
        ], $locks);
        $this->assertSame(['widgets#'.$widget->getKey()], array_keys($snapshots));

        [$snapshots, $locks] = $this->locking(fn () => $this->affectedRecords->before(DB::connection(), GadgetPlace::query()->findOrFail($place), Operation::Update));

        $this->assertSame([
            ['select 1 from "gadgets" where "id" = ? for update', [$gadget]],
            ['select 1 from "retained_widgets" where "id" = ? for update', [$widget->getKey()]],
            ['select 1 from "gadget_places" where "id" = ? for update', [$place]],
        ], $locks);
        $this->assertSame(['widgets#'.$widget->getKey()], array_keys($snapshots));
    }

    #[Test]
    public function it_locks_every_primary_row_a_dependent_write_names_before_its_retained_rows_and_snapshots_none_of_them(): void
    {
        $first = $this->widget();
        $second = Widget::query()->create(['title' => 'Second gadget'])->refresh();
        [$gadget, $root] = $this->currentKeys($first);
        $moved = GadgetPlace::query()->findOrFail($root);
        $moved->setAttribute('gadget_id', $second->gadget_id);
        $gadgets = [$gadget, (string) $second->gadget_id];
        sort($gadgets);

        [$inserted, $insertLocks] = $this->locking(fn () => $this->affectedRecords->before(DB::connection(), new GadgetPlace(['gadget_id' => $gadget, 'parent_id' => $root, 'street' => '2 Child St']), Operation::Insert));
        [$updated, $updateLocks] = $this->locking(fn () => $this->affectedRecords->before(DB::connection(), $moved, Operation::Update));

        $this->assertSame([
            ['select 1 from "gadgets" where "id" = ? for update', [$gadget]],
            ['select 1 from "retained_widgets" where "id" = ? for update', [$first->getKey()]],
        ], $insertLocks);
        $this->assertSame([
            ['select 1 from "gadgets" where "id" = ? for update', [$gadgets[0]]],
            ['select 1 from "gadgets" where "id" = ? for update', [$gadgets[1]]],
            ['select 1 from "retained_widgets" where "id" = ? for update', [$first->getKey()]],
            ['select 1 from "retained_widgets" where "id" = ? for update', [$second->getKey()]],
            ['select 1 from "gadget_places" where "id" = ? for update', [$root]],
        ], $updateLocks);
        $this->assertSame(
            [['widgets#'.$first->getKey()], $this->sorted(['widgets#'.$first->getKey() => RecordRole::Previous, 'widgets#'.$second->getKey() => RecordRole::Previous])],
            [array_keys($inserted), $this->sorted(array_map(fn (RecordSnapshot $snapshot) => $snapshot->record->role, $updated))],
        );
    }

    #[Test]
    public function it_locks_the_records_of_one_role_by_table_and_then_by_key(): void
    {
        $widget = $this->widget();
        [$gadget, $root, $site] = $this->currentKeys($widget);
        $places = [$root];

        foreach (['2 Child St', '3 Child St', '4 Child St'] as $street) {
            $places[] = GadgetPlace::query()->create(['gadget_id' => $gadget, 'parent_id' => $root, 'street' => $street])->id;
        }

        sort($places);

        [, $locks] = $this->duringDelete($widget, true, fn () => $this->locking(fn () => $this->affectedRecords->before(DB::connection(), $widget, Operation::Delete)));

        $this->assertSame([
            ['select 1 from "gadgets" where "id" = ? for update', [$gadget]],
            ['select 1 from "retained_widgets" where "id" = ? for update', [$widget->getKey()]],
            ...array_map(fn (string $place) => ['select 1 from "gadget_places" where "id" = ? for update', [$place]], $places),
            ['select 1 from "gadget_sites" where "id" = ? for update', [$site]],
        ], $locks);
    }

    #[Test]
    public function it_locks_the_retained_rows_of_every_map_over_a_current_record_in_table_order(): void
    {
        $this->addAccountsAndProfiles();
        $account = Account::query()->create(['email' => 'one@example.test'])->refresh();
        $person = (string) $account->getAttribute('person_id');
        $profile = DB::table('retained_profiles')->where('person_id', $person)->value('id');

        [, $locks] = $this->locking(fn () => $this->affectedRecords->before(DB::connection(), $account, Operation::Update));

        $this->assertSame([
            ['select 1 from "people" where "id" = ? for update', [$person]],
            ['select 1 from "retained_accounts" where "id" = ? for update', [$account->getKey()]],
            ['select 1 from "retained_profiles" where "id" = ? for update', [$profile]],
        ], $locks);
    }

    #[Test]
    public function it_locks_a_dependent_row_that_appears_under_the_first_locks_after_them_and_snapshots_it(): void
    {
        $widget = $this->widget();
        [$gadget, $root, $site] = $this->currentKeys($widget);
        $newcomer = (string) Str::uuid();
        DB::listen(function (QueryExecuted $query) use ($gadget, $root, $newcomer): void {
            if (str_ends_with($query->sql, ' for update') && DB::table('gadget_places')->where('id', $newcomer)->doesntExist()) {
                DB::table('gadget_places')->insert(['id' => $newcomer, 'gadget_id' => $gadget, 'parent_id' => $root, 'street' => '5 Newcomer St']);
            }
        });

        [$snapshots, $locks] = $this->duringDelete($widget, true, fn () => $this->locking(fn () => $this->affectedRecords->before(DB::connection(), $widget, Operation::Delete)));

        $this->assertSame([
            ['select 1 from "gadgets" where "id" = ? for update', [$gadget]],
            ['select 1 from "retained_widgets" where "id" = ? for update', [$widget->getKey()]],
            ['select 1 from "gadget_places" where "id" = ? for update', [$root]],
            ['select 1 from "gadget_sites" where "id" = ? for update', [$site]],
            ['select 1 from "gadget_places" where "id" = ? for update', [$newcomer]],
        ], $locks);
        $this->assertSame('5 Newcomer St', $snapshots['gadget_places#'.$newcomer]->row['street'] ?? null);
    }

    #[Test]
    public function it_reads_each_record_from_its_relation_and_reports_a_missing_row_as_null(): void
    {
        $widget = $this->widget();
        [$gadget] = $this->currentKeys($widget);
        $keyTranslator = $this->mirrorIndex->keyTranslator('widgets');
        $previous = $keyTranslator->previousRecord($widget->getKey());
        $primary = $keyTranslator->currentRecord('gadget', $gadget);
        $missing = $keyTranslator->currentRecord('place', (string) Str::uuid());

        $snapshots = $this->affectedRecords->snapshots(DB::connection(), ['previous' => $previous, 'primary' => $primary, 'missing' => $missing]);

        $this->assertSame(['previous', 'primary', 'missing'], array_keys($snapshots));
        $this->assertSame([$previous, $primary, $missing], array_values(array_map(fn (RecordSnapshot $snapshot) => $snapshot->record, $snapshots)));
        $this->assertSame('Blue gadget', $snapshots['previous']->row['title'] ?? null);
        $this->assertSame('Blue gadget', $snapshots['primary']->row['name'] ?? null);
        $this->assertNull($snapshots['missing']->row);
        $this->assertSame([], $this->affectedRecords->snapshots(DB::connection(), []));
    }

    #[Test]
    public function it_names_the_own_record_of_a_model_in_each_role_it_has_in_a_mirrored_map(): void
    {
        $widget = $this->widget();
        [$gadget, $place] = $this->currentKeys($widget);

        $records = [
            $this->affectedRecords->native(DB::connection(), $widget),
            $this->affectedRecords->native(DB::connection(), Gadget::query()->findOrFail($gadget)),
            $this->affectedRecords->native(DB::connection(), GadgetPlace::query()->findOrFail($place)),
        ];

        $this->assertSame([
            ['widgets#'.$widget->getKey(), RecordRole::Previous, 'retained_widgets'],
            ['gadgets#'.$gadget, RecordRole::Primary, 'gadgets'],
            ['gadget_places#'.$place, RecordRole::Dependent, 'gadget_places'],
        ], array_map(fn (null|AffectedRecord $record) => [$record?->id(), $record?->role, $record?->lockedTable], $records));
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function it_names_the_own_record_of_a_model_loaded_without_its_mapped_key_from_the_persisted_key(bool $strict): void
    {
        $widget = $this->widget();
        Widget::query()->create(['title' => 'Second gadget']);
        Model::preventAccessingMissingAttributes($strict);
        $loaded = Widget::query()->select(['uuid', 'title'])->where('title', 'Blue gadget')->firstOrFail();
        $loaded->setKeyName('uuid')->setKeyType('string')->setIncrementing(false);

        $this->assertSame('widgets#'.$widget->getKey(), $this->affectedRecords->native(DB::connection(), $loaded)?->id());
    }

    #[Test]
    public function it_names_no_own_record_for_a_model_without_a_persisted_key_or_outside_every_mirrored_map(): void
    {
        $this->assertNull($this->affectedRecords->native(DB::connection(), new Widget(['title' => 'Unsaved gadget'])));
        $this->assertNull($this->affectedRecords->native(DB::connection(), Person::query()->create(['email' => 'one@example.test'])));
    }

    private function add(CompatibilityMap $map): void
    {
        $this->mirrorIndex->add(resolve(CompatibilityMaps::class)->register($map), new CompatibilityMapEventMirror($map));
    }

    private function addAccountsAndProfiles(): void
    {
        resolve(Installer::class)->install(new AccountCompatibilityMap);
        resolve(Installer::class)->install(new ProfileCompatibilityMap);
        $this->add(new AccountCompatibilityMap);
        $this->add(new ProfileCompatibilityMap);
    }

    private function widget(): Widget
    {
        return Widget::query()->create(['title' => 'Blue gadget', 'price' => '12.50', 'street' => '1 Main St', 'latitude' => 47.5])->refresh();
    }

    /**
     * @return list<string>
     */
    private function currentKeys(Widget $widget): array
    {
        return [
            (string) $widget->gadget_id,
            (string) DB::table('gadget_places')->where('gadget_id', $widget->gadget_id)->whereNull('parent_id')->value('id'),
            (string) DB::table('gadget_sites')->where('gadget_id', $widget->gadget_id)->value('id'),
        ];
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    private function duringDelete(Widget $widget, bool $force, callable $callback): mixed
    {
        $results = [];

        Event::listen('eloquent.deleting: '.Widget::class, function (Widget $deleting) use ($widget, $force, $callback, &$results): false {
            $this->assertSame($widget, $deleting);
            $this->assertSame($force, $deleting->isForceDeleting());
            $results[] = $callback();

            return false;
        });

        $force ? $widget->forceDelete() : $widget->delete();
        $this->assertCount(1, $results);

        return $results[0];
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return array{TResult, list<array{string, list<mixed>}>}
     */
    private function locking(callable $callback): array
    {
        $this->locks = [];

        try {
            return [$callback(), $this->locks];
        } finally {
            $this->locks = null;
        }
    }

    /**
     * @param  array<string, AffectedRecord>  $records
     * @return array<string, RecordRole>
     */
    private function roles(array $records): array
    {
        foreach ($records as $id => $record) {
            $this->assertSame($id, $record->id());
        }

        return $this->sorted(array_map(fn (AffectedRecord $record) => $record->role, $records));
    }

    /**
     * @param  array<string, RecordRole>  $roles
     * @return array<string, RecordRole>
     */
    private function sorted(array $roles): array
    {
        ksort($roles);

        return $roles;
    }
}
