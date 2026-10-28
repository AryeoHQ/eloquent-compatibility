<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Support\Database\Eloquent\Compatibility\Bridge\Events\RecordMirrored;
use Support\Database\Eloquent\Compatibility\CompatibilityMaps;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Mapping\ExcludedListener;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Support\Database\Eloquent\Compatibility\Testing\InteractsWithCompatibilityMaps;
use Tests\Fixtures\Support\Workbench\Account;
use Tests\Fixtures\Support\Workbench\AccountCompatibilityMap;
use Tests\Fixtures\Support\Workbench\CastGadget;
use Tests\Fixtures\Support\Workbench\CompatibilityMapEventMirror;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\GadgetSite;
use Tests\Fixtures\Support\Workbench\Journal;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

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
