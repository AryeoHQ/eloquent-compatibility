<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Bridge\Events\Mirrored;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityEventMirrorBypassed;
use Support\Database\Eloquent\Compatibility\Mapping\ExcludedListener;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Support\Database\Eloquent\Compatibility\Testing\InteractsWithCompatibilityMaps;
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
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(EventBridge::class)]
#[CoversClass(Replay::class)]
#[CoversClass(Footprint::class)]
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
        $this->assertMirrored(Gadget::class, 'updated');
        $this->assertCount(1, $this->mirroredEvents());
        $this->assertSame(['saving', 'updating', 'updated', 'saved'], $this->mirroredEvents()[0]->events);
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
            $this->assertMirrored($model, 'created', fn (Model $record) => $record->exists);
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

        $this->assertMirrored(Gadget::class, 'trashed', fn (Model $gadget) => $gadget->getAttribute('deleted_at') !== null);
        $this->assertSame(['deleting', 'trashed', 'deleted'], $this->mirroredEvents()[0]->events);

        $widget->restore();

        $this->assertMirrored(Gadget::class, 'restored', fn (Model $gadget) => $gadget->getAttribute('deleted_at') === null);
        $this->assertSame(['restoring', 'saving', 'updating', 'updated', 'saved', 'restored'], $this->mirroredEvents()[1]->events);
    }

    #[Test]
    public function it_raises_the_force_delete_events_of_every_current_record(): void
    {
        $this->mirror();
        $widget = $this->widget();
        $this->recordMirroredEvents();

        $widget->forceDelete();

        $this->assertMirrored(Gadget::class, 'forceDeleted', fn (Model $gadget) => ! $gadget->exists);
        $this->assertMirrored(GadgetPlace::class, 'forceDeleted');
        $this->assertMirrored(GadgetSite::class, 'deleted');
        $this->assertSame(0, Gadget::withTrashed()->count());
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
        $this->assertMirrored(Widget::class, 'updated');
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

        $this->assertMirrored(Widget::class, 'updated', fn (Model $mirrored, Mirrored $event) => $mirrored->getAttribute('street') === '3 New St' && $event->before['street'] === null);
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
    public function it_skips_an_excluded_listener_for_mirrored_events_only(): void
    {
        $this->mirror([ExcludedListener::of(Journal::class, 'The journal records only writes through the current model.')->forEvent('updated')]);
        $journal = new Journal;
        $this->app->instance(Journal::class, $journal);
        Event::listen('eloquent.updated: '.Gadget::class, [Journal::class, 'handle']);
        $widget = $this->widget();
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Through the previous model']);

        $this->assertMirrored(Gadget::class, 'updated');
        $this->assertSame([], $journal->entries);

        Gadget::query()->sole()->update(['name' => 'Through the current model']);

        $this->assertCount(1, $journal->entries);
    }

    #[Test]
    public function it_skips_an_excluded_listener_and_records_each_mirrored_event_once_through_a_decorating_dispatcher(): void
    {
        $this->mirror([ExcludedListener::of(Journal::class, 'The journal records only writes through the current model.')->forEvent('updated')]);
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
        $this->mirror([ExcludedListener::of(Journal::class, 'The journal records only writes through the current model.')->forEvent('created')]);
        $journal = new Journal;
        $this->app->instance(Journal::class, $journal);
        Event::listen('eloquent.created: '.Gadget::class, [Journal::class, 'handle']);
        $this->decorateTheModelDispatcher();
        $this->recordMirroredEvents();

        $widget = $this->widget()->refresh();

        $this->assertMirrored(Gadget::class, 'created', fn (Model $gadget) => $gadget->getKey() === $widget->gadget_id);
        $this->assertSame([], $journal->entries);

        $gadget = Gadget::query()->create(['name' => 'Through the current model']);

        $this->assertSame(['Gadget '.json_encode($gadget->getKey())], $journal->entries);
    }

    #[Test]
    public function it_runs_an_excluded_listener_for_a_native_write_inside_a_mirrored_event(): void
    {
        $this->mirror([ExcludedListener::of(Journal::class, 'The journal records only writes through the current model.')->forEvent('updated')]);
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
            [['saving', 'updating', 'updated', 'saved'], ['deleting', 'trashed', 'deleted'], ['restoring', 'saving', 'updating', 'updated', 'saved', 'restored']],
            array_map(fn (Mirrored $mirrored) => $mirrored->events, $this->mirroredEvents()),
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
    public function it_mirrors_under_a_fake_that_leaves_model_events_alone(): void
    {
        $this->mirror();
        $widget = $this->widget();
        Event::fake([ValidationException::class]);
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Partly faked']);

        $this->assertMirrored(Gadget::class, 'updated');
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

        $this->assertMirrored(Person::class, 'updated', fn (Model $person) => $person->getAttribute('email') === 'two@example.test');
        $this->assertMirrored(Profile::class, 'updated', fn (Model $profile, Mirrored $event) => $event->before['email'] === 'one@example.test' && $profile->getAttribute('email') === 'two@example.test');

        $this->recordMirroredEvents();
        Person::query()->sole()->update(['display_name' => 'Two']);

        $this->assertMirrored(Profile::class, 'updated', fn (Model $profile) => $profile->getAttribute('display_name') === 'Two');
        $this->assertCount(1, $this->mirroredEvents(), 'Only the profile shows display_name, so only the profile changed.');

        $this->recordMirroredEvents();
        Person::query()->sole()->update(['email' => 'three@example.test']);

        $this->assertMirrored(Account::class, 'updated');
        $this->assertMirrored(Profile::class, 'updated');
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

        $this->assertMirrored(Gadget::class, 'updated');
        $this->assertMirrored(Widget::class, 'updated', fn (Model $mirrored) => $mirrored->getAttribute('street') === 'Added by a listener');
        $this->assertSame('Added by a listener', DB::table('widgets')->value('street'));
        $this->assertSame(1, DB::transactionLevel());
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
