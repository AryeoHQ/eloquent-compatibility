<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Support\Database\Eloquent\Compatibility\Bridge\Events\RecordMirrored;
use Support\Database\Eloquent\Compatibility\CompatibilityMaps;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityEventMirrorBypassed;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\Attribute;
use Support\Database\Eloquent\Compatibility\Mapping\DependentCurrentModel;
use Support\Database\Eloquent\Compatibility\Mapping\ExcludedListener;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Support\Database\Eloquent\Compatibility\Mapping\PreviousModel;
use Support\Database\Eloquent\Compatibility\Mapping\PrimaryCurrentModel;
use Support\Database\Eloquent\Compatibility\Mapping\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Support\Database\Eloquent\Compatibility\Testing\InteractsWithCompatibilityMaps;
use Tests\Fixtures\Support\Workbench\Account;
use Tests\Fixtures\Support\Workbench\AccountCompatibilityMap;
use Tests\Fixtures\Support\Workbench\CompatibilityMapEventMirror;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\GadgetItem;
use Tests\Fixtures\Support\Workbench\GadgetPlace;
use Tests\Fixtures\Support\Workbench\GadgetSite;
use Tests\Fixtures\Support\Workbench\ItemCompatibilityMap;
use Tests\Fixtures\Support\Workbench\Journal;
use Tests\Fixtures\Support\Workbench\LegacyItem;
use Tests\Fixtures\Support\Workbench\Person;
use Tests\Fixtures\Support\Workbench\Profile;
use Tests\Fixtures\Support\Workbench\ProfileCompatibilityMap;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(EventBridge::class)]
#[CoversClass(EventReplayer::class)]
#[CoversClass(AffectedRecords::class)]
final class EventBridgeTest extends TestCase
{
    use InteractsWithCompatibilityMaps;

    /** @var list<array<string, mixed>> */
    private array $seen = [];

    private bool $committed = false;

    /** @var list<string> */
    private array $recorded = [];

    protected function setUp(): void
    {
        parent::setUp();

        Tables::create();
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
    }

    protected function tearDown(): void
    {
        Model::preventAccessingMissingAttributes(false);

        parent::tearDown();
    }

    #[Test]
    public function it_raises_the_current_model_events_of_a_previous_model_update_once_with_the_state_a_native_write_reports(): void
    {
        $this->mirror();
        $widget = $this->widget();
        $this->recordMirroredEvents();
        $this->watch(Gadget::class, 'updating', fn (Gadget $gadget) => ['dirty' => $gadget->isDirty('name'), 'original' => $gadget->getOriginal('name'), 'name' => $gadget->name, 'changed' => $gadget->wasChanged('name')]);
        $this->watch(Gadget::class, 'updated', fn (Gadget $gadget) => ['dirty' => $gadget->isDirty('name'), 'original' => $gadget->getOriginal('name'), 'name' => $gadget->name, 'changed' => $gadget->wasChanged('name')]);
        $this->watch(Gadget::class, 'saved', fn (Gadget $gadget) => ['dirty' => $gadget->isDirty('name'), 'original' => $gadget->getOriginal('name'), 'name' => $gadget->name, 'changed' => $gadget->wasChanged('name')]);

        $widget->update(['title' => 'Renamed']);

        $this->assertSame([
            ['event' => 'updating', 'dirty' => true, 'original' => 'Blue gadget', 'name' => 'Renamed', 'changed' => false],
            ['event' => 'updated', 'dirty' => true, 'original' => 'Blue gadget', 'name' => 'Renamed', 'changed' => true],
            ['event' => 'saved', 'dirty' => true, 'original' => 'Blue gadget', 'name' => 'Renamed', 'changed' => true],
        ], $this->seen);
        $this->assertMirrored(Gadget::class, ModelEvent::Updated);
        $this->assertCount(1, $this->mirroredEvents());
        $this->assertSame([ModelEvent::Saving, ModelEvent::Updating, ModelEvent::Updated, ModelEvent::Saved], $this->mirroredEvents()[0]->events);
        $this->assertSame(1, DB::transactionLevel());
    }

    #[Test]
    public function it_raises_the_created_events_of_every_current_record_of_a_previous_model_create(): void
    {
        $this->mirror();
        $this->recordMirroredEvents();
        $this->watch(Gadget::class, 'creating', fn (Gadget $gadget) => ['exists' => $gadget->exists, 'name' => $gadget->name]);
        $this->watch(Gadget::class, 'created', fn (Gadget $gadget) => ['exists' => $gadget->exists, 'recent' => $gadget->wasRecentlyCreated]);

        $widget = $this->widget();

        $this->assertSame([
            ['event' => 'creating', 'exists' => false, 'name' => 'Blue gadget'],
            ['event' => 'created', 'exists' => true, 'recent' => true],
        ], $this->seen);

        foreach ([Gadget::class, GadgetPlace::class, GadgetSite::class] as $model) {
            $this->assertMirrored($model, ModelEvent::Created, fn (Model $record) => $record->exists);
        }

        $this->assertSame(Gadget::query()->sole()->getKey(), $widget->refresh()->gadget_id);
    }

    #[Test]
    public function it_raises_the_soft_delete_and_restore_events_through_the_primary_current_model(): void
    {
        $this->mirror();
        $widget = $this->widget();
        $this->recordMirroredEvents();

        $widget->delete();

        $this->assertMirrored(Gadget::class, ModelEvent::Trashed, fn (Model $gadget) => $gadget->getAttribute('deleted_at') !== null);
        $this->assertSame([ModelEvent::Deleting, ModelEvent::Trashed, ModelEvent::Deleted], $this->mirroredEvents()[0]->events);

        $widget->restore();

        $this->assertMirrored(Gadget::class, ModelEvent::Restored, fn (Model $gadget) => $gadget->getAttribute('deleted_at') === null);
        $this->assertSame([ModelEvent::Restoring, ModelEvent::Saving, ModelEvent::Updating, ModelEvent::Updated, ModelEvent::Saved, ModelEvent::Restored], $this->mirroredEvents()[1]->events);
    }

    #[Test]
    public function it_raises_the_force_delete_events_of_every_current_record(): void
    {
        $this->mirror();
        $widget = $this->widget();
        $this->recordMirroredEvents();

        $widget->forceDelete();

        $this->assertMirrored(Gadget::class, ModelEvent::ForceDeleted, fn (Model $gadget) => ! $gadget->exists);
        $this->assertMirrored(GadgetPlace::class, ModelEvent::ForceDeleted);
        $this->assertMirrored(GadgetSite::class, ModelEvent::Deleted);
        $this->assertSame(0, Gadget::withTrashed()->count());
    }

    #[Test]
    public function it_raises_the_force_delete_events_of_every_dependent_row_a_previous_model_force_delete_removes(): void
    {
        $this->mirror();
        $widget = $this->widget()->refresh();
        $root = GadgetPlace::query()->where('gadget_id', $widget->gadget_id)->sole();
        GadgetPlace::query()->create(['gadget_id' => $widget->gadget_id, 'parent_id' => $root->id, 'street' => '2 Child St']);
        GadgetPlace::query()->create(['gadget_id' => $widget->gadget_id, 'street' => '3 Old St', 'deleted_at' => now()]);
        $this->recordMirroredEvents();

        $widget->forceDelete();

        $this->assertMirrored(GadgetPlace::class, ModelEvent::ForceDeleted, times: 3);
        $this->assertSame(0, GadgetPlace::withTrashed()->count());
    }

    #[Test]
    public function it_reports_a_force_delete_to_the_mirrored_listeners_of_a_soft_deleting_model_as_a_native_force_delete_does(): void
    {
        $this->mirror();
        $widget = $this->widget();
        $gadget = Gadget::query()->create(['name' => 'Deleted natively']);

        foreach (['forceDeleting', 'deleting', 'deleted', 'forceDeleted'] as $event) {
            $this->watch(Gadget::class, $event, fn (Gadget $gadget) => ['forceDeleting' => $gadget->isForceDeleting()]);
        }

        $gadget->forceDelete();
        $native = $this->seen;
        $this->seen = [];

        $widget->forceDelete();

        $this->assertSame([
            ['event' => 'forceDeleting', 'forceDeleting' => false],
            ['event' => 'deleting', 'forceDeleting' => true],
            ['event' => 'deleted', 'forceDeleting' => true],
            ['event' => 'forceDeleted', 'forceDeleting' => false],
        ], $native);
        $this->assertSame($native, $this->seen);
    }

    #[Test]
    public function it_raises_the_previous_model_events_of_a_current_model_update(): void
    {
        $this->mirror();
        $this->widget();
        $this->recordMirroredEvents();
        $this->watch(Widget::class, 'updated', fn (Widget $widget) => ['original' => $widget->getOriginal('title'), 'title' => $widget->title, 'changed' => $widget->wasChanged('title')]);

        Gadget::query()->sole()->update(['name' => 'From the current model']);

        $this->assertSame([['event' => 'updated', 'original' => 'Blue gadget', 'title' => 'From the current model', 'changed' => true]], $this->seen);
        $this->assertMirrored(Widget::class, ModelEvent::Updated);
    }

    #[Test]
    public function it_raises_the_previous_model_created_events_when_the_primary_current_model_is_created(): void
    {
        $this->mirror();
        $this->recordMirroredEvents();
        $this->watch(Widget::class, 'creating', fn (Widget $widget) => ['id' => $widget->getKey(), 'title' => $widget->title]);
        $this->watch(Widget::class, 'created', fn (Widget $widget) => ['id' => $widget->getKey() !== null, 'title' => $widget->title]);

        Gadget::query()->create(['name' => 'New from the current model']);

        $this->assertSame([
            ['event' => 'creating', 'id' => null, 'title' => 'New from the current model'],
            ['event' => 'created', 'id' => true, 'title' => 'New from the current model'],
        ], $this->seen);
        $this->assertSame('New from the current model', Widget::query()->sole()->title);
    }

    #[Test]
    public function it_raises_the_previous_model_events_when_a_dependent_current_model_changes(): void
    {
        $this->mirror();
        $widget = Widget::query()->create(['title' => 'No place yet'])->refresh();
        $this->recordMirroredEvents();

        GadgetPlace::query()->create(['gadget_id' => $widget->gadget_id, 'street' => '3 New St']);

        $this->assertMirrored(Widget::class, ModelEvent::Updated, fn (Model $mirrored, RecordMirrored $event) => $mirrored->getAttribute('street') === '3 New St' && $event->rowBefore['street'] === null);
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function it_raises_the_previous_model_events_of_a_dependent_current_model_loaded_without_its_primary_foreign_key(bool $strict): void
    {
        Model::preventAccessingMissingAttributes($strict);
        $this->mirror();
        $this->widget();
        $this->recordMirroredEvents();

        GadgetPlace::query()->select(['id', 'street'])->firstOrFail()->update(['street' => 'Changed']);

        $this->assertMirrored(Widget::class, ModelEvent::Updated, fn (Model $mirrored) => $mirrored->getAttribute('street') === 'Changed');
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function it_raises_the_previous_model_events_of_both_primary_records_when_a_dependent_loaded_without_its_primary_foreign_key_moves(bool $strict): void
    {
        Model::preventAccessingMissingAttributes($strict);
        $this->mirror();
        $first = $this->widget();
        $second = Widget::query()->create(['title' => 'Second gadget'])->refresh();
        $place = GadgetPlace::query()->select(['id', 'street'])->firstOrFail();
        $this->recordMirroredEvents();

        $place->setAttribute('gadget_id', $second->gadget_id);
        $place->save();

        $this->assertMirrored(Widget::class, ModelEvent::Updated, fn (Model $mirrored, RecordMirrored $event) => $mirrored->getKey() === $first->getKey() && $event->rowBefore['street'] === '1 Main St' && $mirrored->getAttribute('street') === null);
        $this->assertMirrored(Widget::class, ModelEvent::Updated, fn (Model $mirrored, RecordMirrored $event) => $mirrored->getKey() === $second->getKey() && $event->rowBefore['street'] === null && $mirrored->getAttribute('street') === '1 Main St');
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function it_raises_the_previous_model_events_of_a_primary_current_model_loaded_without_its_mapped_key(bool $strict): void
    {
        Model::preventAccessingMissingAttributes($strict);
        $this->mirror();
        $this->widget();
        Widget::query()->create(['title' => 'Second gadget']);
        $gadget = Gadget::query()->select(['name'])->where('name', 'Blue gadget')->firstOrFail();
        $gadget->setKeyName('name')->setKeyType('string')->setIncrementing(false);
        $this->recordMirroredEvents();

        $gadget->update(['price_cents' => 999]);

        $this->assertMirrored(Widget::class, ModelEvent::Updated, fn (Model $mirrored) => $mirrored->getAttribute('title') === 'Blue gadget' && $mirrored->getAttribute('price') === '9.99');
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function it_raises_the_current_model_events_of_a_previous_model_loaded_without_its_mapped_key(bool $strict): void
    {
        Model::preventAccessingMissingAttributes($strict);
        $this->mirror();
        $this->widget();
        $widget = Widget::query()->select(['uuid', 'title'])->firstOrFail();
        $widget->setKeyName('uuid')->setKeyType('string')->setIncrementing(false);
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Loaded without its id']);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Loaded without its id');
    }

    #[Test]
    public function it_rolls_back_the_whole_write_when_a_mirrored_listener_refuses(): void
    {
        $this->mirror();
        $widget = $this->widget();
        Event::listen('eloquent.updating: '.Gadget::class, fn () => false);

        $refusal = $this->assertWriteRefused(fn () => $widget->update(['title' => 'Refused']), "a 'updating' listener of Gadget");

        $this->assertStringContainsString('exclude the listener', $refusal->instead);
        $this->assertSame('Blue gadget', DB::table('widgets')->value('title'));
        $this->assertSame('Blue gadget', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_rolls_back_the_write_and_its_after_commit_work_when_a_mirrored_listener_throws(): void
    {
        $this->mirror();
        $widget = $this->widget();
        Event::listen('eloquent.saved: '.Gadget::class, function (): void {
            DB::afterCommit(function (): void {
                $this->committed = true;
            });
        });
        Event::listen('eloquent.saving: '.Gadget::class, function (Gadget $gadget): void {
            if ($gadget->name === 'Invalid') {
                throw ValidationException::withMessages(['name' => 'Invalid name.']);
            }
        });

        try {
            $widget->update(['title' => 'Invalid']);
            $this->fail('Expected the mirrored rule to refuse the write.');
        } catch (ValidationException) {
        }

        $this->assertSame('Blue gadget', DB::table('widgets')->value('title'));
        $this->assertSame(1, DB::transactionLevel());
        $this->assertFalse($this->committed);

        $widget->refresh()->update(['title' => 'Valid']);

        $this->assertTrue($this->committed);
        $this->assertSame('Valid', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_refuses_a_mirrored_listener_that_changes_the_mirrored_record(): void
    {
        $this->mirror();
        $widget = $this->widget();
        Event::listen('eloquent.saving: '.Gadget::class, function (Gadget $gadget): void {
            $gadget->name = 'Changed by a listener';
        });

        $this->assertWriteRefused(fn () => $widget->update(['title' => 'Renamed']), 'changed name');

        $this->assertSame('Blue gadget', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_refuses_a_mirrored_listener_change_to_a_number_that_a_float_cannot_tell_apart(): void
    {
        $this->mirror();
        $widget = $this->widget();
        Event::listen('eloquent.saving: '.GadgetSite::class, function (GadgetSite $site): void {
            $site->setAttribute('latitude', '47.600000000000001');
        });

        $this->assertWriteRefused(fn () => $widget->update(['latitude' => 47.6]), 'changed latitude');

        $this->assertSame('47.500000000000', DB::table('gadget_sites')->value('latitude'));
    }

    #[Test]
    public function it_accepts_a_mirrored_listener_that_rewrites_a_number_as_the_same_value(): void
    {
        $this->mirror();
        $widget = $this->widget();
        Event::listen('eloquent.saving: '.GadgetSite::class, function (GadgetSite $site): void {
            $site->setAttribute('latitude', (float) $site->getAttribute('latitude'));
        });
        $this->recordMirroredEvents();

        $widget->update(['latitude' => 47.6]);

        $this->assertMirrored(GadgetSite::class, ModelEvent::Updated);
    }

    #[Test]
    public function it_skips_an_excluded_listener_for_mirrored_events_only(): void
    {
        $this->mirror([ExcludedListener::of(Journal::class, 'The journal records only writes through the current model.')->forEvent(ModelEvent::Updated)]);
        $journal = new Journal;
        $this->app->instance(Journal::class, $journal);
        Event::listen('eloquent.updated: '.Gadget::class, [Journal::class, 'handle']);
        $widget = $this->widget();
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Through the previous model']);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated);
        $this->assertSame([], $journal->entries);

        Gadget::query()->sole()->update(['name' => 'Through the current model']);

        $this->assertCount(1, $journal->entries);
    }

    #[Test]
    public function it_skips_an_excluded_listener_and_records_each_mirrored_event_once_through_a_decorating_dispatcher(): void
    {
        $this->mirror([ExcludedListener::of(Journal::class, 'The journal records only writes through the current model.')->forEvent(ModelEvent::Updated)]);
        $journal = new Journal;
        $this->app->instance(Journal::class, $journal);
        Event::listen('eloquent.updated: '.Gadget::class, [Journal::class, 'handle']);
        $widget = $this->widget();
        $this->decorateTheModelDispatcher();

        $widget->update(['title' => 'Through the previous model']);

        $this->assertSame([], $journal->entries);
        $this->assertSame(
            array_map(fn (string $event) => "eloquent.{$event}: ".Gadget::class, ['saving', 'updating', 'updated', 'saved']),
            array_values(array_filter($this->recorded, fn (string $event) => str_ends_with($event, ': '.Gadget::class))),
        );

        Gadget::query()->sole()->update(['name' => 'Through the current model']);

        $this->assertCount(1, $journal->entries);
    }

    #[Test]
    public function it_skips_an_excluded_listener_for_a_mirrored_create_through_a_decorating_dispatcher_and_runs_it_for_a_native_create(): void
    {
        $this->mirror([ExcludedListener::of(Journal::class, 'The journal records only writes through the current model.')->forEvent(ModelEvent::Created)]);
        $journal = new Journal;
        $this->app->instance(Journal::class, $journal);
        Event::listen('eloquent.created: '.Gadget::class, [Journal::class, 'handle']);
        $this->decorateTheModelDispatcher();
        $this->recordMirroredEvents();

        $widget = $this->widget()->refresh();

        $this->assertMirrored(Gadget::class, ModelEvent::Created, fn (Model $gadget) => $gadget->getKey() === $widget->gadget_id);
        $this->assertSame([], $journal->entries);

        $gadget = Gadget::query()->create(['name' => 'Through the current model']);

        $this->assertSame(['Gadget '.json_encode($gadget->getKey())], $journal->entries);
    }

    #[Test]
    public function it_runs_an_excluded_listener_for_a_native_write_inside_a_mirrored_event(): void
    {
        $this->mirror([ExcludedListener::of(Journal::class, 'The journal records only writes through the current model.')->forEvent(ModelEvent::Updated)]);
        $journal = new Journal;
        $this->app->instance(Journal::class, $journal);
        $first = $this->widget();
        $second = Widget::query()->create(['title' => 'Second gadget'])->refresh();
        Event::listen('eloquent.updated: '.Gadget::class, function (Gadget $gadget) use ($second): void {
            if ($gadget->getKey() !== $second->gadget_id) {
                Gadget::query()->findOrFail($second->gadget_id)->update(['name' => 'Renamed natively']);
            }
        });
        Event::listen('eloquent.updated: '.Gadget::class, [Journal::class, 'handle']);

        $first->update(['title' => 'Through the previous model']);

        $this->assertSame(['Gadget '.json_encode($second->gadget_id)], $journal->entries);
    }

    #[Test]
    public function it_mirrors_a_previous_model_whose_key_name_changes_at_runtime(): void
    {
        $this->mirror();
        $widget = $this->widget()->refresh();
        $widget->setKeyName('uuid')->setKeyType('string')->setIncrementing(false);
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Keyed by uuid']);
        $widget->delete();
        $widget->restore();

        $this->assertSame(
            [[ModelEvent::Saving, ModelEvent::Updating, ModelEvent::Updated, ModelEvent::Saved], [ModelEvent::Deleting, ModelEvent::Trashed, ModelEvent::Deleted], [ModelEvent::Restoring, ModelEvent::Saving, ModelEvent::Updating, ModelEvent::Updated, ModelEvent::Saved, ModelEvent::Restored]],
            array_map(fn (RecordMirrored $recordMirrored) => $recordMirrored->events, $this->mirroredEvents()),
        );
        $this->assertSame('Keyed by uuid', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_fails_loudly_when_a_test_fakes_the_model_events(): void
    {
        $this->mirror();
        $widget = $this->widget();
        Event::fake();

        $this->assertThrows(
            fn () => $widget->update(['title' => 'Faked']),
            CompatibilityEventMirrorBypassed::class,
            'because Event::fake() replaced the model event dispatcher',
        );
    }

    #[Test]
    public function it_fails_loudly_for_each_relation_a_faked_dispatcher_bypasses(): void
    {
        $this->mirror();
        $widget = $this->widget();
        Event::fake();

        $this->assertThrows(fn () => $widget->update(['title' => 'Faked']), CompatibilityEventMirrorBypassed::class);
        $this->assertThrows(fn () => Gadget::query()->sole()->update(['name' => 'Faked natively']), CompatibilityEventMirrorBypassed::class);
    }

    #[Test]
    public function it_mirrors_under_a_fake_that_leaves_model_events_alone(): void
    {
        $this->mirror();
        $widget = $this->widget();
        Event::fake([ValidationException::class]);
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Partly faked']);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated);
    }

    #[Test]
    public function it_switches_the_mirror_off_only_when_a_test_says_so(): void
    {
        $this->mirror();
        $widget = $this->widget();
        Event::fake();
        $this->recordMirroredEvents();

        $this->withoutEventMirroring(fn () => $widget->update(['title' => 'Mirror off']));

        $this->assertNothingMirrored();
        $this->assertSame('Mirror off', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_lets_a_raw_write_through_after_a_faked_write_with_the_mirror_off(): void
    {
        $this->mirror();
        $widget = $this->widget();
        Event::fake();

        $this->withoutEventMirroring(fn () => $widget->update(['title' => 'Mirror off']));
        DB::update('update widgets set sku = ? where id = ?', ['RAW', $widget->getKey()]);

        $this->assertSame('RAW', DB::table('retained_widgets')->value('sku'));
    }

    #[Test]
    public function it_keeps_storage_correct_for_quiet_and_bulk_writes_and_raises_no_events(): void
    {
        $this->mirror();
        $widget = $this->widget();
        $this->recordMirroredEvents();

        $widget->title = 'Quietly';
        $widget->saveQuietly();
        Widget::query()->whereKey($widget->getKey())->update(['price' => '1.00']);
        DB::update('update widgets set sku = ? where id = ?', ['RAW', $widget->getKey()]);

        $this->assertNothingMirrored();
        $this->assertSame(['Quietly', 100], [DB::table('gadgets')->value('name'), DB::table('gadgets')->value('price_cents')]);
        $this->assertSame('RAW', DB::table('retained_widgets')->value('sku'));
    }

    #[Test]
    public function it_raises_nothing_for_a_write_that_changes_no_attribute_of_the_other_side(): void
    {
        $this->mirror();
        $widget = $this->widget();
        $widget->timestamps = false;
        $this->recordMirroredEvents();

        $widget->update(['sku' => 'retained only']);

        $this->assertNothingMirrored();
    }

    #[Test]
    public function it_raises_the_events_of_every_previous_model_over_one_current_record(): void
    {
        resolve(Installer::class)->install(new AccountCompatibilityMap);
        resolve(Installer::class)->install(new ProfileCompatibilityMap);
        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror(new AccountCompatibilityMap));
        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror(new ProfileCompatibilityMap));
        $account = Account::query()->create(['email' => 'one@example.test']);
        $this->recordMirroredEvents();

        $account->update(['email' => 'two@example.test']);

        $this->assertMirrored(Person::class, ModelEvent::Updated, fn (Model $person) => $person->getAttribute('email') === 'two@example.test');
        $this->assertMirrored(Profile::class, ModelEvent::Updated, fn (Model $profile, RecordMirrored $event) => $event->rowBefore['email'] === 'one@example.test' && $profile->getAttribute('email') === 'two@example.test');

        $this->recordMirroredEvents();
        Person::query()->sole()->update(['display_name' => 'Two']);

        $this->assertMirrored(Profile::class, ModelEvent::Updated, fn (Model $profile) => $profile->getAttribute('display_name') === 'Two');
        $this->assertCount(1, $this->mirroredEvents(), 'Only the profile shows display_name, so only the profile changed.');

        $this->recordMirroredEvents();
        Person::query()->sole()->update(['email' => 'three@example.test']);

        $this->assertMirrored(Account::class, ModelEvent::Updated);
        $this->assertMirrored(Profile::class, ModelEvent::Updated);
    }

    #[Test]
    public function it_raises_the_previous_model_events_of_a_write_to_a_current_table_whose_quoted_name_keeps_its_case_and_a_hyphen(): void
    {
        Tables::createItems();
        resolve(Installer::class)->install(new ItemCompatibilityMap);
        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror(new ItemCompatibilityMap));
        LegacyItem::query()->create(['label' => 'Quoted']);
        $this->recordMirroredEvents();

        GadgetItem::query()->sole()->update(['label' => 'Renamed']);

        $this->assertMirrored(LegacyItem::class, ModelEvent::Updated, fn (Model $legacyItem, RecordMirrored $event) => $event->rowBefore['label'] === 'Quoted' && $legacyItem->getAttribute('label') === 'Renamed');
    }

    #[Test]
    public function it_mirrors_a_write_a_mirrored_listener_makes_as_a_change_of_its_own(): void
    {
        $this->mirror();
        $widget = Widget::query()->create(['title' => 'No place yet']);
        Event::listen('eloquent.updated: '.Gadget::class, function (Gadget $gadget): void {
            GadgetPlace::query()->create(['gadget_id' => $gadget->getKey(), 'street' => 'Added by a listener']);
        });
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Renamed']);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated);
        $this->assertMirrored(Widget::class, ModelEvent::Updated, fn (Model $mirrored) => $mirrored->getAttribute('street') === 'Added by a listener');
        $this->assertSame('Added by a listener', DB::table('widgets')->value('street'));
        $this->assertSame(1, DB::transactionLevel());
    }

    private function mirrorWithSettings(): void
    {
        Schema::table('retained_widgets', function (Blueprint $table): void {
            $table->jsonb('settings')->default('{}');
        });
        $map = new WidgetCompatibilityMap(['sku' => Attribute::retained('settings')]);
        resolve(Installer::class)->recompile($map);
        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror($map));
    }

    /**
     * @param  class-string<Model>  $previous
     * @param  class-string<Model>  $primary
     */
    #[Test]
    #[TestWith(['\\Tests\\Fixtures\\Support\\Workbench\\Widget', '\\Tests\\Fixtures\\Support\\Workbench\\Gadget'])]
    #[TestWith(['tests\\fixtures\\support\\workbench\\widget', 'tests\\fixtures\\support\\workbench\\gadget'])]
    public function it_mirrors_the_writes_of_models_its_map_names_with_a_leading_backslash_or_in_another_letter_case(string $previous, string $primary): void
    {
        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror($this->respelled($previous, $primary)));
        $widget = $this->widget();
        $this->recordMirroredEvents();
        $this->watch(Gadget::class, 'updated', fn (Gadget $gadget) => ['name' => $gadget->name]);
        $this->watch(Widget::class, 'updated', fn (Widget $widget) => ['title' => $widget->title]);

        $widget->update(['title' => 'Renamed']);
        Gadget::query()->sole()->update(['name' => 'From the current model']);

        $this->assertSame([
            ['event' => 'updated', 'name' => 'Renamed'],
            ['event' => 'updated', 'title' => 'Renamed'],
            ['event' => 'updated', 'title' => 'From the current model'],
            ['event' => 'updated', 'name' => 'From the current model'],
        ], $this->seen);
        $this->assertMirrored(Gadget::class, ModelEvent::Updated);
        $this->assertMirrored(Widget::class, ModelEvent::Updated);
    }

    #[Test]
    #[TestWith(['updating', ModelEvent::Updated])]
    #[TestWith(['deleting', ModelEvent::Trashed])]
    public function it_mirrors_a_write_on_its_own_statement_and_not_on_a_later_listeners_write_to_the_same_table(string $event, ModelEvent $mirrored): void
    {
        $this->mirror();
        $widget = $this->widget()->refresh();
        $other = Widget::query()->create(['title' => 'Other gadget'])->refresh();
        Event::listen("eloquent.{$event}: ".Widget::class.'*', function (string $name, array $payload) use ($other): void {
            if ($payload[0] instanceof Widget && $payload[0]->isNot($other)) {
                Widget::query()->whereKey($other->getKey())->update(['sku' => $payload[0]->getKey()]);
            }
        });
        $this->recordMirroredEvents();

        $event === 'updating' ? $widget->update(['title' => 'Renamed']) : $widget->delete();

        $this->assertMirrored(Gadget::class, $mirrored, fn (Model $gadget) => $gadget->getKey() === $widget->gadget_id);
        $this->assertSame((string) $widget->getKey(), DB::table('retained_widgets')->where('id', $other->getKey())->value('sku'));
    }

    #[Test]
    #[TestWith(['updating', ModelEvent::Updated])]
    #[TestWith(['deleting', ModelEvent::Trashed])]
    public function it_mirrors_a_write_on_its_own_statement_and_not_on_a_later_listeners_write_to_the_same_record(string $event, ModelEvent $mirrored): void
    {
        $this->mirror();
        $widget = $this->widget()->refresh();
        Event::listen("eloquent.{$event}: ".Widget::class.'*', function (string $name, array $payload): void {
            if ($payload[0] instanceof Widget) {
                DB::table('widgets')->where('id', $payload[0]->getKey())->update(['sku' => 'Written by a listener']);
            }
        });
        $this->recordMirroredEvents();

        $event === 'updating' ? $widget->update(['title' => 'Renamed']) : $widget->delete();

        $this->assertMirrored(Gadget::class, $mirrored, fn (Model $gadget) => $gadget->getKey() === $widget->gadget_id);
        $this->assertSame('Written by a listener', DB::table('retained_widgets')->where('id', $widget->getKey())->value('sku'));
    }

    #[Test]
    public function it_mirrors_an_increment_whose_statement_also_sets_the_updated_at_column(): void
    {
        $this->mirror();
        $widget = $this->widget()->refresh();
        $this->recordMirroredEvents();

        $widget->increment('latitude', 1.5);

        $this->assertMirrored(GadgetSite::class, ModelEvent::Updated, fn (Model $site) => (float) $site->getAttribute('latitude') === 49.0);
    }

    /**
     * @param  array<string, string>  $extra
     */
    #[Test]
    #[TestWith(['increment', [], 49.0])]
    #[TestWith(['increment', ['sku' => 'Counted'], 49.0])]
    #[TestWith(['decrement', [], 46.0])]
    #[TestWith(['decrement', ['sku' => 'Counted'], 46.0])]
    public function it_mirrors_an_increment_or_decrement_of_a_model_that_holds_an_unsaved_change(string $method, array $extra, float $latitude): void
    {
        $this->mirror();
        $widget = $this->widget()->refresh();
        $widget->setAttribute('title', 'Never saved');
        $this->recordMirroredEvents();

        $widget->{$method}('latitude', 1.5, $extra);

        $this->assertMirrored(GadgetSite::class, ModelEvent::Updated, fn (Model $site) => (float) $site->getAttribute('latitude') === $latitude);
        $this->assertSame('Blue gadget', DB::table('gadgets')->where('id', $widget->gadget_id)->value('name'));
        $this->assertSame($extra['sku'] ?? null, DB::table('retained_widgets')->where('id', $widget->getKey())->value('sku'));
        $this->assertSame(1, DB::transactionLevel());
    }

    /**
     * @param  array<string, string>  $extra
     */
    #[RequiresMethod(Model::class, 'incrementOrDecrementEach')]
    #[Test]
    #[TestWith(['incrementEach', [], 49.0])]
    #[TestWith(['incrementEach', ['sku' => 'Counted'], 49.0])]
    #[TestWith(['decrementEach', [], 46.0])]
    #[TestWith(['decrementEach', ['sku' => 'Counted'], 46.0])]
    public function it_mirrors_an_increment_or_decrement_of_each_column_of_a_model_that_holds_an_unsaved_change(string $method, array $extra, float $latitude): void
    {
        $this->mirror();
        $widget = $this->widget()->refresh();
        $widget->setAttribute('title', 'Never saved');
        $this->recordMirroredEvents();

        $widget->{$method}(['latitude' => 1.5], $extra);

        $this->assertMirrored(GadgetSite::class, ModelEvent::Updated, fn (Model $site) => (float) $site->getAttribute('latitude') === $latitude);
        $this->assertSame('Blue gadget', DB::table('gadgets')->where('id', $widget->gadget_id)->value('name'));
        $this->assertSame($extra['sku'] ?? null, DB::table('retained_widgets')->where('id', $widget->getKey())->value('sku'));
        $this->assertSame(1, DB::transactionLevel());
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    #[Test]
    #[TestWith(['increment', ['widgets.status' => 'Counted'], 49.0, 'Counted', '{}'])]
    #[TestWith(['decrement', ['widgets.status' => 'Counted'], 46.0, 'Counted', '{}'])]
    #[TestWith(['increment', ['settings->active' => true], 49.0, 'Draft', '{"active": true}'])]
    #[TestWith(['decrement', ['settings->active' => true], 46.0, 'Draft', '{"active": true}'])]
    public function it_mirrors_an_increment_or_decrement_whose_extra_names_a_qualified_column_or_a_json_path(string $method, array $extra, float $latitude, string $status, string $settings): void
    {
        $this->mirrorWithSettings();
        $widget = $this->widget()->refresh();
        $this->recordMirroredEvents();

        $widget->{$method}('latitude', 1.5, $extra);

        $this->assertMirrored(GadgetSite::class, ModelEvent::Updated, fn (Model $site) => (float) $site->getAttribute('latitude') === $latitude);
        $this->assertSame([$status, $settings], array_values((array) DB::table('retained_widgets')->where('id', $widget->getKey())->first(['status', 'settings'])));
        $this->assertSame(1, DB::transactionLevel());
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    #[RequiresMethod(Model::class, 'incrementOrDecrementEach')]
    #[Test]
    #[TestWith(['incrementEach', ['widgets.status' => 'Counted'], 49.0, 'Counted', '{}'])]
    #[TestWith(['decrementEach', ['widgets.status' => 'Counted'], 46.0, 'Counted', '{}'])]
    #[TestWith(['incrementEach', ['settings->active' => true], 49.0, 'Draft', '{"active": true}'])]
    #[TestWith(['decrementEach', ['settings->active' => true], 46.0, 'Draft', '{"active": true}'])]
    public function it_mirrors_an_increment_or_decrement_of_each_column_whose_extra_names_a_qualified_column_or_a_json_path(string $method, array $extra, float $latitude, string $status, string $settings): void
    {
        $this->mirrorWithSettings();
        $widget = $this->widget()->refresh();
        $this->recordMirroredEvents();

        $widget->{$method}(['latitude' => 1.5], $extra);

        $this->assertMirrored(GadgetSite::class, ModelEvent::Updated, fn (Model $site) => (float) $site->getAttribute('latitude') === $latitude);
        $this->assertSame([$status, $settings], array_values((array) DB::table('retained_widgets')->where('id', $widget->getKey())->first(['status', 'settings'])));
        $this->assertSame(1, DB::transactionLevel());
    }

    /**
     * @param  array<string, mixed>  $values
     */
    #[Test]
    #[TestWith([['sku' => 'Written by a listener'], 49.0])]
    #[TestWith([['latitude' => 1.0], 2.5])]
    #[TestWith([['latitude' => 1.0, 'title' => 'Never saved'], 2.5])]
    #[TestWith([['latitude' => 1.0, 'updated_at' => '2026-01-01 00:00:00+00'], 2.5])]
    public function it_mirrors_an_increment_on_its_own_statement_and_not_on_a_later_listeners_write_to_the_same_record(array $values, float $latitude): void
    {
        $this->mirror();
        $widget = $this->widget()->refresh();
        $widget->setAttribute('title', 'Never saved');
        Event::listen('eloquent.updating: '.Widget::class.'*', function (string $name, array $payload) use ($values): void {
            if ($payload[0] instanceof Widget) {
                DB::table('widgets')->where('id', $payload[0]->getKey())->update($values);
            }
        });
        $this->recordMirroredEvents();

        $widget->increment('latitude', 1.5);

        $this->assertMirrored(GadgetSite::class, ModelEvent::Updated, fn (Model $site, RecordMirrored $event) => (float) $site->getAttribute('latitude') === $latitude && (float) ($event->rowBefore['latitude'] ?? null) === $latitude - 1.5);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    #[Test]
    #[TestWith([['latitude' => 1.0]])]
    #[TestWith([['title' => 'Written by a listener', 'latitude' => 1.0]])]
    public function it_mirrors_a_save_on_its_own_statement_and_not_on_a_later_listeners_write_of_some_or_all_of_its_changes(array $values): void
    {
        $this->mirror();
        $widget = $this->widget()->refresh();
        Event::listen('eloquent.updating: '.Widget::class.'*', function (string $name, array $payload) use ($values): void {
            if ($payload[0] instanceof Widget) {
                DB::table('widgets')->where('id', $payload[0]->getKey())->update($values);
            }
        });
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Renamed', 'latitude' => 2.0]);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed');
        $this->assertMirrored(GadgetSite::class, ModelEvent::Updated, fn (Model $site, RecordMirrored $event) => (float) $site->getAttribute('latitude') === 2.0 && (float) ($event->rowBefore['latitude'] ?? null) === 1.0);
    }

    #[Test]
    public function it_mirrors_the_soft_delete_of_a_model_that_holds_an_unsaved_change(): void
    {
        $this->mirror();
        $widget = $this->widget()->refresh();
        $widget->setAttribute('title', 'Never saved');
        $this->recordMirroredEvents();

        $widget->delete();

        $this->assertMirrored(Gadget::class, ModelEvent::Trashed, fn (Model $gadget) => $gadget->getKey() === $widget->gadget_id);
        $this->assertSame('Blue gadget', DB::table('gadgets')->where('id', $widget->gadget_id)->value('name'));
    }

    #[Test]
    public function it_mirrors_an_update_that_sets_a_raw_expression_holding_a_question_mark(): void
    {
        $this->mirror();
        $widget = $this->widget()->refresh();
        $this->recordMirroredEvents();

        $widget->update(['title' => DB::raw("'Why?'")]);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getKey() === $widget->gadget_id);
        $this->assertSame('Why?', DB::table('gadgets')->where('id', $widget->gadget_id)->value('name'));
    }

    #[Test]
    public function it_mirrors_an_update_that_sets_a_raw_literal_too_long_for_the_scan_to_backtrack(): void
    {
        $this->mirror();
        $widget = $this->widget()->refresh();
        $this->recordMirroredEvents();

        $widget->update(['title' => DB::raw("left('".str_repeat('a', 10_000)."', 5)")]);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getKey() === $widget->gadget_id);
        $this->assertSame('aaaaa', DB::table('gadgets')->where('id', $widget->gadget_id)->value('name'));
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function it_mirrors_a_create_on_its_own_insert_and_not_on_a_later_listeners_insert_into_the_same_table(bool $sameColumns): void
    {
        $this->mirror();
        Event::listen('eloquent.creating: '.Widget::class.'*', function (string $name, array $payload) use ($sameColumns): void {
            if ($payload[0] instanceof Widget && $payload[0]->getAttribute('title') === 'Blue gadget') {
                DB::table('widgets')->insert($sameColumns ? [...$payload[0]->getAttributes(), 'title' => 'Inserted by a listener'] : ['title' => 'Inserted by a listener']);
            }
        });
        $this->recordMirroredEvents();

        $this->widget();

        $this->assertMirrored(Gadget::class, ModelEvent::Created, fn (Model $gadget) => $gadget->getAttribute('name') === 'Blue gadget');
        $this->assertCount(1, array_filter($this->mirroredEvents(), fn (RecordMirrored $event) => $event->destination instanceof Gadget));
        $this->assertSame(2, DB::table('gadgets')->count());
    }

    #[Test]
    public function it_mirrors_both_writes_when_a_beginning_listener_writes_another_mapped_model_inside_a_callers_transaction(): void
    {
        $this->mirror();
        $widget = $this->widget();
        $other = Widget::query()->create(['title' => 'Other gadget']);
        Event::listen('eloquent.updating: '.Widget::class.'*', function (string $name, array $payload) use ($other): void {
            if ($payload[0] instanceof Widget && $payload[0]->isNot($other)) {
                $other->update(['title' => 'Renamed by a listener']);
            }
        });
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Renamed']);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed by a listener');
        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed');
        $this->assertSame(1, DB::transactionLevel());
    }

    #[Test]
    public function it_mirrors_the_outer_write_when_a_beginning_listener_catches_a_refused_write_to_another_mapped_model_inside_a_callers_transaction(): void
    {
        $this->mirror();
        $widget = $this->widget();
        $other = Widget::query()->create(['title' => 'Other gadget']);
        Event::listen('eloquent.updating: '.Widget::class.'*', function (string $name, array $payload) use ($other): void {
            if ($payload[0] instanceof Widget && $payload[0]->isNot($other)) {
                try {
                    DB::transaction(fn () => $other->update(['uuid' => '00000000-0000-0000-0000-000000000000']));
                } catch (QueryException) {
                }
            }
        });
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Renamed']);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed');
        $this->assertSame(1, DB::transactionLevel());
        $this->assertSame(['Renamed', 'Other gadget'], [$widget->refresh()->title, $other->refresh()->title]);
    }

    #[Test]
    public function it_mirrors_nothing_for_a_later_write_without_events_to_the_record_of_a_vetoed_save(): void
    {
        $this->mirror();
        $widget = $this->widget();
        Event::listen('eloquent.updating: '.Widget::class.'*', fn (string $name, array $payload) => $payload[0] instanceof Widget && $payload[0]->is($widget) ? false : null);
        $this->recordMirroredEvents();

        $this->assertFalse($widget->update(['title' => 'Vetoed']));
        Widget::query()->whereKey($widget->getKey())->update(['title' => 'Written without events']);

        $this->assertNothingMirrored();
        $this->assertSame('Written without events', DB::table('gadgets')->value('name'));
        $this->assertSame(1, DB::transactionLevel());
    }

    #[Test]
    public function it_records_a_faked_mirrored_event_and_runs_none_of_its_listeners_when_the_map_excludes_a_listener(): void
    {
        $this->mirror([ExcludedListener::of(Journal::class, 'The journal records only writes through the current model.')->forEvent(ModelEvent::Updated)]);
        $journal = new Journal;
        $this->app->instance(Journal::class, $journal);
        Event::listen('eloquent.updated: '.Gadget::class, [Journal::class, 'handle']);
        $this->watch(Gadget::class, 'updated', fn () => []);
        $widget = $this->widget();
        Event::fake(['eloquent.updated: '.Gadget::class]);

        $widget->update(['title' => 'Faked']);

        Event::assertDispatched('eloquent.updated: '.Gadget::class, 1);
        $this->assertSame([[], []], [$this->seen, $journal->entries]);
    }

    #[Test]
    #[TestWith(['previous'])]
    #[TestWith(['primary'])]
    #[TestWith(['dependent'])]
    public function it_refuses_to_mirror_a_map_that_names_a_model_whose_children_fire_events_under_their_own_classes(string $role): void
    {
        require_once dirname(__DIR__, 6).'/tests/Fixtures/Tightenco/Parental/HasChildren.php';
        /** @var class-string<Model> $parental */
        $parental = eval('return (new class extends \Illuminate\Database\Eloquent\Model { use \Tightenco\Parental\HasChildren; protected $table = "gadgets"; })::class;'); // @phpstan-ignore ergebnis.noEval
        $map = match ($role) {
            'previous' => $this->respelled($parental, Gadget::class),
            'primary' => $this->respelled(Widget::class, $parental),
            default => $this->respelled(Widget::class, Gadget::class, [DependentCurrentModel::for($parental)->aliasedAs('parental')->primaryForeignKey('id')]),
        };

        $this->assertThrows(
            fn () => resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror($map)),
            InvalidCompatibilityMap::class,
            "Compatibility map 'widgets' is declared wrongly: {$parental} uses Tightenco\\Parental\\HasChildren, so its child classes fire model events under their own names and the event bridge would mirror none of their writes",
        );

        $this->assertSame([], resolve(CompatibilityMaps::class)->all());
        $this->artisan('compatibility:check')
            ->expectsOutputToContain('No compatibility map is registered, so nothing was checked.')
            ->assertFailed();
    }

    #[Test]
    public function it_keeps_the_mirrored_map_registered_when_a_second_mirror_of_its_name_is_refused(): void
    {
        $this->mirror();
        $previous = (new class extends Model
        {
            protected $table = 'widgets';
        })::class;
        $respelled = $this->respelled($previous, Gadget::class);

        $this->assertThrows(
            fn () => resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror($respelled)),
            InvalidCompatibilityMap::class,
            "Compatibility map 'widgets' is declared wrongly: its event mirror is registered twice",
        );

        $this->assertSame([Widget::class], array_map(fn (ResolvedMap $resolvedMap) => (string) $resolvedMap->previousModel->model, resolve(CompatibilityMaps::class)->all()));
    }

    #[Test]
    public function it_leaves_the_callers_transaction_usable_after_a_database_refusal(): void
    {
        $this->mirror();
        $widget = $this->widget();

        $this->assertWriteRefused(fn () => $widget->update(['uuid' => '00000000-0000-0000-0000-000000000000']), 'refused a change to widgets.uuid');

        $this->assertSame(1, DB::transactionLevel());
        $widget->refresh()->update(['title' => 'After the refusal']);
        $this->assertSame('After the refusal', DB::table('gadgets')->value('name'));
    }

    /**
     * @param  list<ExcludedListener>  $excludedListeners
     */
    private function mirror(array $excludedListeners = []): void
    {
        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror(new WidgetCompatibilityMap, $excludedListeners));
    }

    /**
     * @param  class-string<Model>  $previous
     * @param  class-string<Model>  $primary
     * @param  list<DependentCurrentModel>  $dependents
     */
    private function respelled(string $previous, string $primary, array $dependents = []): CompatibilityMap
    {
        return new readonly class(new WidgetCompatibilityMap, $previous, $primary, $dependents) implements CompatibilityMap
        {
            /**
             * @param  class-string<Model>  $previous
             * @param  class-string<Model>  $primary
             * @param  list<DependentCurrentModel>  $dependents
             */
            public function __construct(private WidgetCompatibilityMap $base, private string $previous, private string $primary, private array $dependents) {}

            public function name(): string
            {
                return $this->base->name();
            }

            public function previousModel(): PreviousModel
            {
                return $this->base->previousModel()->forModel($this->previous);
            }

            public function primaryCurrentModel(): PrimaryCurrentModel
            {
                return PrimaryCurrentModel::for($this->primary)
                    ->aliasedAs('gadget')
                    ->fillWhenCreatedFromPrevious($this->base->primaryCurrentModel()->fillWhenCreatedFromPrevious);
            }

            public function dependentCurrentModels(): array
            {
                return [...$this->base->dependentCurrentModels(), ...$this->dependents];
            }

            public function attributes(): array
            {
                return $this->base->attributes();
            }

            public function unrepresentableStates(): array
            {
                return $this->base->unrepresentableStates();
            }
        };
    }

    private function decorateTheModelDispatcher(): void
    {
        $dispatcher = Model::getEventDispatcher();
        $this->assertNotNull($dispatcher);

        Model::setEventDispatcher(new class($dispatcher, fn (string $event) => $this->recorded[] = $event) implements Dispatcher
        {
            /**
             * @param  Closure(string): mixed  $record
             */
            public function __construct(
                private readonly Dispatcher $decorated,
                private readonly Closure $record,
            ) {}

            /**
             * @param  Closure|string|array<array-key, mixed>  $events
             * @param  null|Closure|string|array<array-key, mixed>  $listener
             */
            public function listen(mixed $events, mixed $listener = null): void
            {
                $this->decorated->listen($events, $listener);
            }

            public function hasListeners(mixed $eventName): bool
            {
                return $this->decorated->hasListeners($eventName);
            }

            public function subscribe(mixed $subscriber): void
            {
                $this->decorated->subscribe($subscriber);
            }

            public function until(mixed $event, mixed $payload = []): mixed
            {
                ($this->record)(is_object($event) ? $event::class : (string) $event);

                return $this->decorated->until($event, $payload);
            }

            /**
             * @return null|array<array-key, mixed>
             */
            public function dispatch(mixed $event, mixed $payload = [], mixed $halt = false): mixed
            {
                ($this->record)(is_object($event) ? $event::class : (string) $event);

                return $this->decorated->dispatch($event, $payload, $halt);
            }

            /**
             * @param  array<array-key, mixed>  $payload
             */
            public function push(mixed $event, mixed $payload = []): void
            {
                $this->decorated->push($event, $payload);
            }

            public function flush(mixed $event): void
            {
                $this->decorated->flush($event);
            }

            public function forget(mixed $event): void
            {
                $this->decorated->forget($event);
            }

            public function forgetPushed(): void
            {
                $this->decorated->forgetPushed();
            }

            /**
             * @param  array<array-key, mixed>  $parameters
             */
            public function __call(string $method, array $parameters): mixed
            {
                return $this->decorated->$method(...$parameters);
            }
        });
    }

    private function widget(): Widget
    {
        return Widget::query()->create(['title' => 'Blue gadget', 'price' => '12.50', 'street' => '1 Main St', 'latitude' => 47.5]);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  callable(TModel): array<string, mixed>  $capture
     */
    private function watch(string $model, string $event, callable $capture): void
    {
        Event::listen("eloquent.{$event}: {$model}", function (Model $record) use ($model, $event, $capture): void {
            $this->assertInstanceOf($model, $record);
            $this->seen[] = ['event' => $event, ...$capture($record)];
        });
    }
}
