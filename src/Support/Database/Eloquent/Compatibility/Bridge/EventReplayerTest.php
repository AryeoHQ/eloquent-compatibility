<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Closure;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Support\Database\Eloquent\Compatibility\Bridge\Events\RecordMirrored;
use Support\Database\Eloquent\Compatibility\CompatibilityMaps;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Mapping\ExcludedListener;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Support\Database\Eloquent\Compatibility\Mapping\PrimaryCurrentModel;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Support\Database\Eloquent\Compatibility\Testing\InteractsWithCompatibilityMaps;
use Support\Database\Eloquent\StateMachines\Attributes\Transitions\Exceptions\Invalid;
use Support\Database\Eloquent\StateMachines\Triggers\Exceptions\Duplicate;
use Support\Database\Eloquent\StateMachines\Triggers\Trigger;
use Tests\Fixtures\Support\Workbench\Account;
use Tests\Fixtures\Support\Workbench\AccountCompatibilityMap;
use Tests\Fixtures\Support\Workbench\Article;
use Tests\Fixtures\Support\Workbench\ArticleCompatibilityMap;
use Tests\Fixtures\Support\Workbench\Bulletin;
use Tests\Fixtures\Support\Workbench\BulletinCompatibilityMap;
use Tests\Fixtures\Support\Workbench\CastGadget;
use Tests\Fixtures\Support\Workbench\CompatibilityMapEventMirror;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\GadgetSite;
use Tests\Fixtures\Support\Workbench\Journal;
use Tests\Fixtures\Support\Workbench\Publication;
use Tests\Fixtures\Support\Workbench\PublicationState\Events\Transitioning;
use Tests\Fixtures\Support\Workbench\PublicationState\PublicationState;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Archive;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Credit;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Embargo;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Feature;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Grade;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Pin;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Publish;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Release;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Reopen;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Reserve;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Restore;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\ReturnToPublished;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Schedule;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Tag;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Withdraw;
use Tests\Fixtures\Support\Workbench\PublicationState\Triggers\Withdrawal;
use Tests\Fixtures\Support\Workbench\ReviewState\Triggers\Approve;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;
use Throwable;

#[CoversClass(EventReplayer::class)]
final class EventReplayerTest extends TestCase
{
    use InteractsWithCompatibilityMaps;

    private MirrorIndex $mirrorIndex;

    private EventReplayer $eventReplayer;

    private Widget $source;

    /** @var list<array<string, mixed>> */
    private array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();

        Tables::create();
        $this->mirror();
        $this->source = new Widget;
        $this->recordMirroredEvents();
    }

    #[Test]
    public function it_replays_a_create_with_the_events_and_the_state_a_native_create_reports(): void
    {
        $id = $this->gadget();
        $after = $this->row('gadgets', $id);
        $this->watch(Gadget::class, 'creating', fn (Gadget $gadget) => ['key' => $gadget->getKey(), 'exists' => $gadget->exists, 'name' => $gadget->name]);
        $this->watch(Gadget::class, 'created', fn (Gadget $gadget) => ['exists' => $gadget->exists, 'recent' => $gadget->wasRecentlyCreated]);

        $this->eventReplayer->replay($this->gadgetRecord($id), null, $after, $this->source, 'replica');

        $this->assertSame([
            ['event' => 'creating', 'key' => $id, 'exists' => false, 'name' => 'Blue gadget'],
            ['event' => 'created', 'exists' => true, 'recent' => true],
        ], $this->seen);
        $recordMirrored = $this->sole();
        $this->assertSame([ModelEvent::Saving, ModelEvent::Creating, ModelEvent::Created, ModelEvent::Saved], $recordMirrored->events);
        $this->assertSame(['widgets', $this->source, null, $after], [$recordMirrored->map, $recordMirrored->source, $recordMirrored->rowBefore, $recordMirrored->rowAfter]);
        $this->assertInstanceOf(Gadget::class, $recordMirrored->destination);
        $this->assertSame([$id, true, 'replica', $after, false], [$recordMirrored->destination->getKey(), $recordMirrored->destination->exists, $recordMirrored->destination->getConnectionName(), $recordMirrored->destination->getAttributes(), $recordMirrored->destination->isDirty()]);
    }

    #[Test]
    public function it_hides_an_incrementing_key_from_the_creating_listeners_as_a_native_create_does(): void
    {
        resolve(Installer::class)->install(new AccountCompatibilityMap);
        $account = Account::query()->create(['email' => 'one@example.test']);
        $after = $this->row('accounts', $account->getKey());
        $this->watch(Account::class, 'creating', fn (Account $account) => ['key' => $account->getKey()]);
        $this->watch(Account::class, 'created', fn (Account $account) => ['key' => $account->getKey()]);

        $this->eventReplayer->replay($this->mirrorIndex->keyTranslator('accounts')->previousRecord($account->getKey()), null, $after, $this->source, 'pgsql');

        $this->assertSame([['event' => 'creating', 'key' => null], ['event' => 'created', 'key' => $account->getKey()]], $this->seen);
    }

    #[Test]
    public function it_replays_an_update_with_the_original_and_changed_attributes_a_native_update_reports(): void
    {
        $id = $this->gadget();
        $before = $this->row('gadgets', $id);
        DB::table('gadgets')->where('id', $id)->update(['name' => 'Renamed']);
        $after = $this->row('gadgets', $id);

        foreach (['updating', 'updated', 'saved'] as $event) {
            $this->watch(Gadget::class, $event, fn (Gadget $gadget) => ['dirty' => $gadget->isDirty('name'), 'original' => $gadget->getOriginal('name'), 'name' => $gadget->name, 'changed' => $gadget->wasChanged('name')]);
        }

        $this->eventReplayer->replay($this->gadgetRecord($id), $before, $after, $this->source, 'pgsql');

        $this->assertSame([
            ['event' => 'updating', 'dirty' => true, 'original' => 'Blue gadget', 'name' => 'Renamed', 'changed' => false],
            ['event' => 'updated', 'dirty' => true, 'original' => 'Blue gadget', 'name' => 'Renamed', 'changed' => true],
            ['event' => 'saved', 'dirty' => true, 'original' => 'Blue gadget', 'name' => 'Renamed', 'changed' => true],
        ], $this->seen);
        $recordMirrored = $this->sole();
        $this->assertSame([ModelEvent::Saving, ModelEvent::Updating, ModelEvent::Updated, ModelEvent::Saved], $recordMirrored->events);
        $this->assertSame([$before, $after], [$recordMirrored->rowBefore, $recordMirrored->rowAfter]);
        $this->assertSame(['Renamed', false], [$recordMirrored->destination->getOriginal('name'), $recordMirrored->destination->isDirty()]);
    }

    #[Test]
    public function it_replays_a_soft_delete_as_a_trashed_delete(): void
    {
        $id = $this->gadget();
        $before = $this->row('gadgets', $id);
        DB::table('gadgets')->where('id', $id)->update(['deleted_at' => now()]);
        $after = $this->row('gadgets', $id);

        foreach (['deleting', 'trashed', 'deleted'] as $event) {
            $this->watch(Gadget::class, $event, fn (Gadget $gadget) => ['exists' => $gadget->exists, 'trashed' => $gadget->trashed()]);
        }

        $this->eventReplayer->replay($this->gadgetRecord($id), $before, $after, $this->source, 'pgsql');

        $this->assertSame([
            ['event' => 'deleting', 'exists' => true, 'trashed' => false],
            ['event' => 'trashed', 'exists' => true, 'trashed' => true],
            ['event' => 'deleted', 'exists' => true, 'trashed' => true],
        ], $this->seen);
        $this->assertSame([ModelEvent::Deleting, ModelEvent::Trashed, ModelEvent::Deleted], $this->sole()->events);
    }

    #[Test]
    public function it_replays_a_restore_as_a_native_restore_reports(): void
    {
        $id = $this->gadget(['deleted_at' => now()]);
        $before = $this->row('gadgets', $id);
        DB::table('gadgets')->where('id', $id)->update(['deleted_at' => null]);
        $after = $this->row('gadgets', $id);

        foreach (['restoring', 'updated', 'restored'] as $event) {
            $this->watch(Gadget::class, $event, fn (Gadget $gadget) => ['trashed' => $gadget->trashed(), 'changed' => $gadget->wasChanged('deleted_at')]);
        }

        $this->eventReplayer->replay($this->gadgetRecord($id), $before, $after, $this->source, 'pgsql');

        $this->assertSame([
            ['event' => 'restoring', 'trashed' => true, 'changed' => false],
            ['event' => 'updated', 'trashed' => false, 'changed' => true],
            ['event' => 'restored', 'trashed' => false, 'changed' => true],
        ], $this->seen);
        $this->assertSame([ModelEvent::Restoring, ModelEvent::Saving, ModelEvent::Updating, ModelEvent::Updated, ModelEvent::Saved, ModelEvent::Restored], $this->sole()->events);
    }

    #[Test]
    public function it_replays_a_removed_row_of_a_model_without_soft_deletes_as_a_delete(): void
    {
        $gadget = $this->gadget();
        $id = (string) Str::uuid();
        DB::table('gadget_sites')->insert(['id' => $id, 'gadget_id' => $gadget, 'latitude' => 47.5]);
        $before = $this->row('gadget_sites', $id);

        foreach (['deleting', 'deleted'] as $event) {
            $this->watch(GadgetSite::class, $event, fn (GadgetSite $site) => ['exists' => $site->exists, 'key' => $site->getKey()]);
        }

        $this->eventReplayer->replay($this->mirrorIndex->keyTranslator('widgets')->currentRecord('site', $id), $before, null, $this->source, 'pgsql');

        $this->assertSame([
            ['event' => 'deleting', 'exists' => true, 'key' => $id],
            ['event' => 'deleted', 'exists' => false, 'key' => $id],
        ], $this->seen);
        $recordMirrored = $this->sole();
        $this->assertSame([ModelEvent::Deleting, ModelEvent::Deleted], $recordMirrored->events);
        $this->assertSame([$before, null], [$recordMirrored->rowBefore, $recordMirrored->rowAfter]);
    }

    #[Test]
    public function it_replays_a_removed_row_of_a_soft_deleting_model_as_a_native_force_delete_reports(): void
    {
        $id = $this->gadget();
        $before = $this->row('gadgets', $id);

        foreach (['forceDeleting', 'deleting', 'deleted', 'forceDeleted'] as $event) {
            $this->watch(Gadget::class, $event, fn (Gadget $gadget) => ['exists' => $gadget->exists, 'forceDeleting' => $gadget->isForceDeleting()]);
        }

        $this->eventReplayer->replay($this->gadgetRecord($id), $before, null, $this->source, 'pgsql');

        $this->assertSame([
            ['event' => 'forceDeleting', 'exists' => true, 'forceDeleting' => false],
            ['event' => 'deleting', 'exists' => true, 'forceDeleting' => true],
            ['event' => 'deleted', 'exists' => false, 'forceDeleting' => true],
            ['event' => 'forceDeleted', 'exists' => false, 'forceDeleting' => false],
        ], $this->seen);
        $this->assertSame([ModelEvent::ForceDeleting, ModelEvent::Deleting, ModelEvent::Deleted, ModelEvent::ForceDeleted], $this->sole()->events);
    }

    #[Test]
    public function it_replays_nothing_for_a_record_whose_rows_are_equal(): void
    {
        $id = $this->gadget();
        $row = $this->row('gadgets', $id);
        $this->watch(Gadget::class, 'saving', fn () => []);

        $this->eventReplayer->replay($this->gadgetRecord($id), $row, $row, $this->source, 'pgsql');
        $this->eventReplayer->replay($this->gadgetRecord($id), null, null, $this->source, 'pgsql');

        $this->assertSame([], $this->seen);
        $this->assertNothingMirrored();
    }

    #[Test]
    public function it_refuses_the_write_when_a_mirrored_listener_of_an_ing_event_returns_false(): void
    {
        [$id, $before, $after] = $this->renamed();
        Event::listen('eloquent.updating: '.Gadget::class, fn () => false);
        $this->watch(Gadget::class, 'updated', fn () => []);

        $refusal = $this->refusal(fn () => $this->eventReplayer->replay($this->gadgetRecord($id), $before, $after, $this->source, 'pgsql'));

        $this->assertSame("Compatibility map 'widgets' refused this write, because a 'updating' listener of Gadget #{$id} returned false", $refusal->refused);
        $this->assertSame([], $this->seen);
        $this->assertNothingMirrored();
    }

    #[Test]
    public function it_lets_a_mirrored_listener_of_an_ed_event_return_false(): void
    {
        [$id, $before, $after] = $this->renamed();
        Event::listen('eloquent.updated: '.Gadget::class, fn () => false);

        $this->eventReplayer->replay($this->gadgetRecord($id), $before, $after, $this->source, 'pgsql');

        $this->assertSame([ModelEvent::Saving, ModelEvent::Updating, ModelEvent::Updated, ModelEvent::Saved], $this->sole()->events);
    }

    #[Test]
    public function it_refuses_the_write_when_a_mirrored_listener_changes_the_record(): void
    {
        [$id, $before, $after] = $this->renamed();
        Event::listen('eloquent.saving: '.Gadget::class, function (Gadget $gadget): void {
            $gadget->name = 'Changed by a listener';
        });

        $refusal = $this->refusal(fn () => $this->eventReplayer->replay($this->gadgetRecord($id), $before, $after, $this->source, 'pgsql'));

        $this->assertSame("Compatibility map 'widgets' refused this write, because a 'saving' listener of Gadget #{$id} changed name", $refusal->refused);
        $this->assertNothingMirrored();
    }

    #[Test]
    public function it_refuses_a_listener_change_to_a_number_that_a_float_cannot_tell_apart(): void
    {
        [$id, $before, $after] = $this->movedSite();
        Event::listen('eloquent.saving: '.GadgetSite::class, function (GadgetSite $site): void {
            $site->setAttribute('latitude', '47.600000000000001');
        });

        $refusal = $this->refusal(fn () => $this->eventReplayer->replay($this->mirrorIndex->keyTranslator('widgets')->currentRecord('site', $id), $before, $after, $this->source, 'pgsql'));

        $this->assertStringEndsWith('changed latitude', $refusal->refused);
    }

    #[Test]
    public function it_accepts_a_listener_rewrite_to_the_same_number_in_a_numeric_column_or_the_same_json_in_a_json_column(): void
    {
        [$id, $before, $after] = $this->movedSite();
        Event::listen('eloquent.saving: '.GadgetSite::class, function (GadgetSite $site): void {
            $site->setAttribute('latitude', (float) $site->getAttribute('latitude'));
            $site->setAttribute('longitude', '0.0');
        });

        $this->eventReplayer->replay($this->mirrorIndex->keyTranslator('widgets')->currentRecord('site', $id), $before, $after, $this->source, 'pgsql');

        Schema::table('gadgets', function (Blueprint $table): void {
            $table->jsonb('specs')->nullable();
        });
        $gadget = $this->gadget(['specs' => '{"color": "blue"}']);
        $before = $this->row('gadgets', $gadget);
        DB::table('gadgets')->where('id', $gadget)->update(['price_cents' => 1250]);
        Event::listen('eloquent.updating: '.Gadget::class, function (Gadget $gadget): void {
            $gadget->setAttribute('specs', '{"color":"blue"}');
            $gadget->setAttribute('price_cents', '1250');
        });

        $this->eventReplayer->replay($this->gadgetRecord($gadget), $before, $this->row('gadgets', $gadget), $this->source, 'pgsql');

        $this->assertCount(2, $this->mirroredEvents());
    }

    #[Test]
    public function it_accepts_a_listener_rewrite_of_a_json_column_that_only_reorders_the_keys_of_its_objects(): void
    {
        Schema::table('gadgets', function (Blueprint $table): void {
            $table->jsonb('specs')->nullable();
        });
        $id = $this->gadget(['specs' => '{"color": "blue", "parts": {"left": 1, "right": 2}}']);
        $before = $this->row('gadgets', $id);
        DB::table('gadgets')->where('id', $id)->update(['price_cents' => 1250]);
        Event::listen('eloquent.updating: '.Gadget::class, function (Gadget $gadget): void {
            $gadget->setAttribute('specs', '{"parts":{"right":2,"left":1},"color":"blue"}');
        });

        $this->eventReplayer->replay($this->gadgetRecord($id), $before, $this->row('gadgets', $id), $this->source, 'pgsql');

        $this->assertCount(1, $this->mirroredEvents());
    }

    #[Test]
    #[TestWith(['{}', '[]'])]
    #[TestWith(['[]', '{}'])]
    #[TestWith(['{"parts": {}}', '{"parts": []}'])]
    #[TestWith(['[{"parts": []}]', '[{"parts": {}}]'])]
    public function it_refuses_a_listener_change_of_a_json_column_between_an_object_and_an_array(string $stored, string $changed): void
    {
        Schema::table('gadgets', function (Blueprint $table): void {
            $table->jsonb('specs')->nullable();
        });
        $id = $this->gadget(['specs' => $stored]);
        $before = $this->row('gadgets', $id);
        DB::table('gadgets')->where('id', $id)->update(['price_cents' => 1250]);
        Event::listen('eloquent.updating: '.Gadget::class, function (Gadget $gadget) use ($changed): void {
            $gadget->setAttribute('specs', $changed);
        });

        $refusal = $this->refusal(fn () => $this->eventReplayer->replay($this->gadgetRecord($id), $before, $this->row('gadgets', $id), $this->source, 'pgsql'));

        $this->assertStringEndsWith('changed specs', $refusal->refused);
    }

    #[Test]
    #[TestWith(['1', '001'])]
    #[TestWith(['1.50', '1.5'])]
    #[TestWith(['{"color": "blue"}', '{"color":"blue"}'])]
    public function it_refuses_a_listener_change_to_a_text_column_that_reads_as_the_same_number_or_json(string $stored, string $changed): void
    {
        $id = $this->gadget(['name' => $stored]);
        $before = $this->row('gadgets', $id);
        DB::table('gadgets')->where('id', $id)->update(['price_cents' => 1250]);
        Event::listen('eloquent.updating: '.Gadget::class, function (Gadget $gadget) use ($changed): void {
            $gadget->setAttribute('name', $changed);
        });

        $refusal = $this->refusal(fn () => $this->eventReplayer->replay($this->gadgetRecord($id), $before, $this->row('gadgets', $id), $this->source, 'pgsql'));

        $this->assertStringEndsWith('changed name', $refusal->refused);
    }

    #[Test]
    public function it_accepts_a_listener_rewrite_to_the_same_value_of_a_text_column_cast_to_a_number_or_to_json(): void
    {
        Schema::table('gadgets', function (Blueprint $table): void {
            $table->string('code')->nullable();
        });
        $id = $this->gadget(['name' => '{"color": "blue"}', 'code' => '001']);
        $before = $this->row('gadgets', $id);
        DB::table('gadgets')->where('id', $id)->update(['price_cents' => 1250]);
        Event::listen('eloquent.updating: '.CastGadget::class, function (CastGadget $gadget): void {
            $gadget->setAttribute('name', ['color' => 'blue']);
            $gadget->setAttribute('code', 1);
        });

        $this->eventReplayer->replay(new AffectedRecord('widgets', CastGadget::class, 'gadgets', 'gadgets', 'id', $id, RecordRole::Primary), $before, $this->row('gadgets', $id), $this->source, 'pgsql');

        $this->assertSame([ModelEvent::Saving, ModelEvent::Updating, ModelEvent::Updated, ModelEvent::Saved], $this->sole()->events);
    }

    #[Test]
    public function it_records_a_faked_event_without_running_its_listeners_when_the_map_excludes_a_listener(): void
    {
        $this->mirror([ExcludedListener::of(Journal::class, 'The journal records only writes through the current model.')->forEvent(ModelEvent::Updated)]);
        $journal = new Journal;
        $this->app->instance(Journal::class, $journal);
        Event::listen('eloquent.updated: '.Gadget::class, [Journal::class, 'handle']);
        $this->watch(Gadget::class, 'updated', fn () => []);
        [$id, $before, $after] = $this->renamed();
        Event::fake(['eloquent.updated: '.Gadget::class]);

        $this->eventReplayer->replay($this->gadgetRecord($id), $before, $after, $this->source, 'pgsql');

        Event::assertDispatched('eloquent.updated: '.Gadget::class, 1);
        $this->assertSame([[], []], [$this->seen, $journal->entries]);
    }

    #[Test]
    public function it_skips_an_excluded_listener_for_its_replayed_event_only(): void
    {
        $this->mirror([ExcludedListener::of(Journal::class, 'The journal records only writes through the current model.')->forEvent(ModelEvent::Updated)]);
        $journal = new Journal;
        $this->app->instance(Journal::class, $journal);
        Event::listen('eloquent.updated: '.Gadget::class, [Journal::class, 'handle']);
        Event::listen('eloquent.saved: '.Gadget::class, [Journal::class, 'handle']);
        [$id, $before, $after] = $this->renamed();

        $this->eventReplayer->replay($this->gadgetRecord($id), $before, $after, $this->source, 'pgsql');

        $this->assertSame(['Gadget '.json_encode($id)], $journal->entries);

        Gadget::query()->findOrFail($id)->update(['name' => 'Renamed natively']);

        $this->assertSame(array_fill(0, 3, 'Gadget '.json_encode($id)), $journal->entries);
    }

    #[Test]
    public function it_skips_an_excluded_listener_under_a_fake_that_leaves_model_events_alone(): void
    {
        $this->mirror([ExcludedListener::of(Journal::class, 'The journal records only writes through the current model.')]);
        $journal = new Journal;
        $this->app->instance(Journal::class, $journal);
        Event::listen('eloquent.updated: '.Gadget::class, [Journal::class, 'handle']);
        $this->watch(Gadget::class, 'updated', fn () => []);
        [$id, $before, $after] = $this->renamed();
        Event::fake([ValidationException::class]);

        $this->eventReplayer->replay($this->gadgetRecord($id), $before, $after, $this->source, 'pgsql');

        $this->assertSame([], $journal->entries);
        $this->assertSame([['event' => 'updated']], $this->seen);
    }

    #[Test]
    public function it_reports_the_events_it_replays_when_models_have_no_event_dispatcher(): void
    {
        [$id, $before, $after] = $this->renamed();
        $dispatcher = Model::getEventDispatcher();
        $this->assertNotNull($dispatcher);
        Model::unsetEventDispatcher();

        try {
            $this->eventReplayer->replay($this->gadgetRecord($id), $before, $after, $this->source, 'pgsql');
        } finally {
            Model::setEventDispatcher($dispatcher);
        }

        $this->assertSame([ModelEvent::Saving, ModelEvent::Updating, ModelEvent::Updated, ModelEvent::Saved], $this->sole()->events);
    }

    #[Test]
    public function it_finds_no_state_column_to_replay_on_a_model_that_casts_none_or_for_a_create_or_a_delete(): void
    {
        [$id, $before, $after] = $this->renamed();
        $record = $this->gadgetRecord($id);

        $this->assertSame([], $this->eventReplayer->states($record, $before, $after, 'pgsql'));
        $this->assertSame([], $this->eventReplayer->states($record, null, $after, 'pgsql'));
        $this->assertSame([], $this->eventReplayer->states($record, $before, null, 'pgsql'));
    }

    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    public function it_finds_each_state_column_a_change_moves_in_the_order_the_model_casts_them(): void
    {
        [$record, $before] = $this->publication();

        $this->assertSame([], $this->eventReplayer->states($record, $before, [...$before, 'title' => 'Renamed'], 'pgsql'));
        $this->assertSame(['state'], $this->eventReplayer->states($record, $before, [...$before, 'state' => 'pending_review'], 'pgsql'));
        $this->assertSame(['state', 'review'], $this->eventReplayer->states($record, $before, [...$before, 'review' => 'approved', 'state' => 'published'], 'pgsql'));
        $this->assertSame([], $this->eventReplayer->states($record, null, $before, 'pgsql'));
        $this->assertSame([], $this->eventReplayer->states($record, $before, null, 'pgsql'));
    }

    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    public function it_finds_a_state_column_of_text_moved_to_a_value_that_reads_as_the_same_number_or_the_same_json(): void
    {
        [$record, $before] = $this->publication();

        $this->assertSame(['state'], $this->eventReplayer->states($record, [...$before, 'state' => '1'], [...$before, 'state' => '01'], 'pgsql'));
        $this->assertSame(['state'], $this->eventReplayer->states($record, [...$before, 'state' => '{"a":1}'], [...$before, 'state' => '{"a": 1}'], 'pgsql'));
    }

    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    public function it_runs_the_trigger_of_a_stored_state_on_the_stored_row_with_the_source_state_and_lets_it_save_only_what_handle_changed(): void
    {
        $journal = $this->journal();
        $this->bridge(new ArticleCompatibilityMap);
        $article = Article::query()->create(['title' => 'Draft publication'])->refresh();
        $statements = [];
        Event::listen(Transitioning::class, fn (Transitioning $event) => $this->seen[] = ['event' => 'before', 'state' => $event->publication->getAttributes()['state'] ?? null, 'original' => $event->publication->getRawOriginal('state'), 'title' => $event->publication->getAttribute('title'), 'connection' => $event->publication->getConnectionName()]);
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $article->update(['status' => 'published', 'title' => 'Renamed']);

        $this->assertSame(['Publication '.json_encode($article->publication_id)], $journal->entries);
        $this->assertSame([['event' => 'before', 'state' => 'draft', 'original' => 'draft', 'title' => 'Renamed', 'connection' => 'pgsql']], $this->seen);
        $this->assertSame(['update "publications" set "published_at" = ?, "updated_at" = ? where "id" = ?'], array_values(array_filter($statements, fn (string $sql) => str_starts_with($sql, 'update "publications"'))));
        $this->assertSame(['published', 'Renamed', true], [DB::table('publications')->value('state'), DB::table('publications')->value('title'), DB::table('publications')->value('published_at') !== null]);
    }

    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    public function it_runs_the_trigger_of_each_moved_state_column_in_turn_through_its_own_column(): void
    {
        $journal = $this->journal();
        $this->bridge(new BulletinCompatibilityMap);
        $bulletin = Bulletin::query()->create(['title' => 'Draft bulletin', 'slot' => 'side'])->refresh();

        $bulletin->update(['status' => 'published', 'review_status' => 'approved']);

        $this->assertSame(['Publication '.json_encode($bulletin->publication_id)], $journal->entries);
        $this->assertSame(['published', 'approved', true, 'front'], [DB::table('publications')->value('state'), DB::table('publications')->value('review'), DB::table('publications')->value('published_at') !== null, DB::table('placements')->value('slot')]);
    }

    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    public function it_calls_a_trigger_whose_class_name_has_several_words_by_the_method_name_its_proxy_accepts(): void
    {
        $this->bridge(new ArticleCompatibilityMap);
        $article = Article::query()->create(['title' => 'Draft publication'])->refresh();
        DB::table('publications')->update(['state' => 'featured', 'featured_at' => '2020-01-01 00:00:00']);

        $article->refresh()->update(['status' => 'published']);

        $this->assertSame(['published', null], [DB::table('publications')->value('state'), DB::table('publications')->value('featured_at')]);
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: null|string, 3: string, 4: class-string<Throwable>}>
     */
    public static function unreplayableChanges(): array
    {
        return [
            'no matching transition' => ['published', false, 'draft', 'Invalid transition from [Published] to [Draft] on [Publication].', Invalid::class],
            'two matching transitions' => ['archived', false, 'draft', 'Multiple [Draft] triggers defined on [Archived]', Duplicate::class],
            'a blocked transition' => ['draft', true, 'published', 'Invalid transition from [Draft] to [Published] on [Publication].', Invalid::class],
            'a trigger the container cannot build' => ['draft', false, 'archived', 'Unresolvable dependency resolving', BindingResolutionException::class],
            'a state the enum has no case for' => ['draft', false, 'pending_review', "['pending_review'] is not a case of [PublicationState].", InvalidArgumentException::class],
            'no state' => ['draft', false, null, '[NULL] is not a case of [PublicationState].', InvalidArgumentException::class],
        ];
    }

    /**
     * @param  class-string<Throwable>  $previous
     */
    #[DataProvider('unreplayableChanges')]
    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    public function it_refuses_a_state_change_its_state_machine_cannot_run_a_trigger_for_before_handle_runs(string $stored, bool $locked, null|string $written, string $because, string $previous): void
    {
        $journal = $this->journal();
        [$record, $before] = $this->publication(['state' => $stored, 'locked' => $locked]);
        $after = $this->stored($record, ['state' => $written]);
        Event::listen(Transitioning::class, fn () => $this->seen[] = ['event' => 'before']);

        $refusal = $this->refusal(fn () => $this->eventReplayer->transition($record, $before, $after, 'pgsql', ['state'], new Article));

        $this->assertStringContainsString("refused this write, because it changes status of Article, stored as state of Publication #{$record->keyValue}, in a way", $refusal->refused);
        $this->assertStringContainsString($because, $refusal->because);
        $this->assertInstanceOf($previous, $refusal->getPrevious());
        $this->assertSame([], $journal->entries);
        $this->assertSame([], $this->seen);
        $this->assertSame([$written, null], [DB::table('publications')->value('state'), DB::table('publications')->value('published_at')]);
    }

    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    public function it_refuses_a_state_value_of_a_type_the_enum_cannot_take(): void
    {
        $journal = $this->journal();
        [$record, $before] = $this->publication();

        $refusal = $this->refusal(fn () => $this->eventReplayer->transition($record, $before, [...$before, 'state' => 1], 'pgsql', ['state'], new Article));

        $this->assertStringContainsString('[1] is not a case of [PublicationState].', $refusal->because);
        $this->assertInstanceOf(InvalidArgumentException::class, $refusal->getPrevious());
        $this->assertSame([], $journal->entries);
        $this->assertSame(['draft', null], [DB::table('publications')->value('state'), DB::table('publications')->value('published_at')]);
    }

    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    public function it_lists_each_trigger_of_the_state_machines_a_model_casts_once(): void
    {
        $twice = new class extends Model
        {
            protected $casts = ['state' => PublicationState::class, 'next_state' => PublicationState::class, 'locked' => 'boolean'];
        };

        $this->assertSame([Publish::class, Archive::class, Schedule::class, Pin::class, Embargo::class, Reserve::class, Tag::class, Credit::class, Grade::class, Feature::class, Withdraw::class, ReturnToPublished::class, Restore::class, Reopen::class, Release::class, Withdrawal\Release::class, Approve::class], EventReplayer::triggers(new Publication));
        $this->assertSame([Publish::class, Archive::class, Schedule::class, Pin::class, Embargo::class, Reserve::class, Tag::class, Credit::class, Grade::class, Feature::class, Withdraw::class, ReturnToPublished::class, Restore::class, Reopen::class, Release::class, Withdrawal\Release::class], EventReplayer::triggers($twice));
        $this->assertSame([], EventReplayer::triggers(new Gadget));
    }

    #[Test]
    public function it_lists_no_triggers_without_eloquent_state_machines(): void
    {
        if (method_exists(Trigger::class, 'withoutTransition')) { // @phpstan-ignore function.alreadyNarrowedType
            $this->markTestSkipped('aryeo/eloquent-state-machines is installed.');
        }

        $this->assertNull(EventReplayer::triggers(new Gadget));
    }

    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    public function it_runs_the_trigger_with_the_arguments_the_map_builds_from_the_stored_row_with_the_source_state_and_from_the_source(): void
    {
        $received = [];
        $this->bridge($this->archivedWith(['reason' => function (Publication $publication, Bulletin $bulletin) use (&$received): string {
            $received = [$publication::class, $publication->getKey(), $bulletin, $publication->getAttributes()['state'] ?? null, $publication->getRawOriginal('state'), $publication->getAttribute('title'), DB::table('publications')->value('state')];

            return 'retired';
        }]));
        $bulletin = Bulletin::query()->create(['title' => 'Draft bulletin', 'slot' => 'side'])->refresh();

        $bulletin->update(['status' => 'archived', 'title' => 'Renamed']);

        $this->assertSame([Publication::class, $bulletin->publication_id, $bulletin, 'draft', 'draft', 'Renamed', 'archived'], $received);
        $this->assertSame(['archived', 'Renamed (retired)'], [DB::table('publications')->value('state'), DB::table('publications')->value('title')]);
    }

    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    public function it_runs_a_trigger_the_map_declares_no_arguments_for_without_arguments(): void
    {
        $calls = 0;
        $this->bridge($this->archivedWith(['reason' => function () use (&$calls): string {
            $calls++;

            return 'unused';
        }]));
        $bulletin = Bulletin::query()->create(['title' => 'Draft bulletin', 'slot' => 'side'])->refresh();

        $bulletin->update(['status' => 'published']);

        $this->assertSame(0, $calls);
        $this->assertSame(['published', 'Draft bulletin', true], [DB::table('publications')->value('state'), DB::table('publications')->value('title'), DB::table('publications')->value('published_at') !== null]);
    }

    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    public function it_builds_the_arguments_of_a_later_state_column_from_the_row_the_earlier_trigger_stored(): void
    {
        $received = [];
        $publication = PrimaryCurrentModel::for(Publication::class)->aliasedAs('publication')->triggerArguments(Approve::class, ['by' => function (Publication $publication) use (&$received): string {
            $received = [$publication->getAttributes()['state'] ?? null, $publication->getAttributes()['review'] ?? null, $publication->getAttribute('published_at') !== null];

            return 'the desk';
        }]);
        $this->bridge(new BulletinCompatibilityMap(publication: $publication));
        $bulletin = Bulletin::query()->create(['title' => 'Draft bulletin', 'slot' => 'side'])->refresh();

        $bulletin->update(['status' => 'published', 'review_status' => 'approved']);

        $this->assertSame(['published', 'pending', true], $received);
        $this->assertSame(['published', 'approved', 'front'], [DB::table('publications')->value('state'), DB::table('publications')->value('review'), DB::table('placements')->value('slot')]);
    }

    #[RequiresMethod(Trigger::class, 'withoutTransition')]
    #[Test]
    public function it_refuses_the_write_before_it_builds_the_trigger_when_a_declared_argument_throws(): void
    {
        $journal = $this->journal();
        $thrown = new RuntimeException('No reason.');
        [$record, $before] = $this->publication(map: $this->archivedWith(['reason' => fn (): string => throw $thrown]));
        $after = $this->stored($record, ['state' => 'archived']);
        Event::listen(Transitioning::class, fn () => $this->seen[] = ['event' => 'before']);

        $refusal = $this->refusal(fn () => $this->eventReplayer->transition($record, $before, $after, 'pgsql', ['state'], new Bulletin));

        $this->assertSame("Compatibility map 'bulletins' refused this write, because its triggerArguments() closure for argument 'reason' of ".Archive::class." threw for Publication #{$record->keyValue}", $refusal->refused);
        $this->assertSame($thrown, $refusal->getPrevious());
        $this->assertSame([], $journal->entries);
        $this->assertSame([], $this->seen);
        $this->assertSame(['archived', 'Draft publication'], [DB::table('publications')->value('state'), DB::table('publications')->value('title')]);
    }

    /**
     * @param  list<ExcludedListener>  $excludedListeners
     */
    private function mirror(array $excludedListeners = []): void
    {
        $this->mirrorIndex = new MirrorIndex;
        $this->mirrorIndex->add(resolve(CompatibilityMaps::class)->register(new WidgetCompatibilityMap), new CompatibilityMapEventMirror(new WidgetCompatibilityMap, $excludedListeners));
        $this->mirrorIndex->add(resolve(CompatibilityMaps::class)->register(new AccountCompatibilityMap), new CompatibilityMapEventMirror(new AccountCompatibilityMap));
        $this->eventReplayer = new EventReplayer($this->mirrorIndex);
    }

    private function bridge(CompatibilityMap $map): void
    {
        resolve(Installer::class)->install($map);
        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror($map));
    }

    private function journal(): Journal
    {
        $journal = new Journal;
        $this->app->instance(Journal::class, $journal);

        return $journal;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{AffectedRecord, array<string, mixed>}
     */
    private function publication(array $attributes = [], CompatibilityMap $map = new ArticleCompatibilityMap): array
    {
        $this->mirrorIndex->add(resolve(CompatibilityMaps::class)->register($map), new CompatibilityMapEventMirror($map));
        $id = (string) Str::uuid();
        DB::table('publications')->insert(['id' => $id, 'title' => 'Draft publication', ...$attributes]);

        return [$this->mirrorIndex->keyTranslator($map->name())->currentRecord('publication', $id), $this->row('publications', $id)];
    }

    /**
     * @param  array<string, Closure>  $arguments
     */
    private function archivedWith(array $arguments): BulletinCompatibilityMap
    {
        return new BulletinCompatibilityMap(publication: PrimaryCurrentModel::for(Publication::class)->aliasedAs('publication')->triggerArguments(Archive::class, $arguments));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function stored(AffectedRecord $record, array $attributes): array
    {
        DB::table('publications')->where('id', $record->keyValue)->update($attributes);

        return $this->row('publications', $record->keyValue);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function gadget(array $attributes = []): string
    {
        $id = (string) Str::uuid();
        DB::table('gadgets')->insert(['id' => $id, 'name' => 'Blue gadget', ...$attributes]);

        return $id;
    }

    private function gadgetRecord(string $id): AffectedRecord
    {
        return $this->mirrorIndex->keyTranslator('widgets')->currentRecord('gadget', $id);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $table, mixed $id): array
    {
        $row = DB::table($table)->where('id', $id)->first();
        $this->assertNotNull($row);

        return get_object_vars($row);
    }

    /**
     * @return array{string, array<string, mixed>, array<string, mixed>}
     */
    private function renamed(): array
    {
        $id = $this->gadget();
        $before = $this->row('gadgets', $id);
        DB::table('gadgets')->where('id', $id)->update(['name' => 'Renamed']);

        return [$id, $before, $this->row('gadgets', $id)];
    }

    /**
     * @return array{string, array<string, mixed>, array<string, mixed>}
     */
    private function movedSite(): array
    {
        $id = (string) Str::uuid();
        DB::table('gadget_sites')->insert(['id' => $id, 'gadget_id' => $this->gadget(), 'latitude' => 47.5]);
        $before = $this->row('gadget_sites', $id);
        DB::table('gadget_sites')->where('id', $id)->update(['latitude' => 47.6]);

        return [$id, $before, $this->row('gadget_sites', $id)];
    }

    /**
     * @param  callable(): mixed  $record
     */
    private function refusal(callable $record): CompatibilityWriteRefused
    {
        try {
            $record();
        } catch (CompatibilityWriteRefused $refusal) {
            return $refusal;
        }

        $this->fail('Expected the replay to refuse the write.');
    }

    private function sole(): RecordMirrored
    {
        $this->assertCount(1, $this->mirroredEvents());

        return $this->mirroredEvents()[0];
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
