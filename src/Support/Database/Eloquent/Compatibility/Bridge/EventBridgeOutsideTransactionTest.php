<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Bridge;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Support\Database\Eloquent\Compatibility\Bridge\Events\RecordMirrored;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Support\Database\Eloquent\Compatibility\Testing\InteractsWithCompatibilityMaps;
use Tests\Fixtures\Support\Workbench\CompatibilityMapEventMirror;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\GadgetPlace;
use Tests\Fixtures\Support\Workbench\GadgetSite;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(EventBridge::class)]
final class EventBridgeOutsideTransactionTest extends TestCase
{
    use InteractsWithCompatibilityMaps;

    private null|string $concurrentOutcome = null;

    protected function setUp(): void
    {
        parent::setUp();

        Tables::drop();
        Tables::create();
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror(new WidgetCompatibilityMap));
    }

    protected function tearDown(): void
    {
        Model::preventAccessingMissingAttributes(false);
        DB::rollBack(0);
        Tables::drop();

        parent::tearDown();
    }

    /** @return list<string> */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function it_commits_a_mirrored_write_made_outside_a_transaction(): void
    {
        $widget = $this->widget();
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Committed']);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('Committed', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_rolls_back_a_write_made_outside_a_transaction_when_a_mirrored_listener_refuses(): void
    {
        $widget = $this->widget();
        Event::listen('eloquent.updating: '.Gadget::class, fn () => false);

        try {
            $widget->update(['title' => 'Refused']);
            $this->fail('Expected the mirrored listener to refuse the write.');
        } catch (CompatibilityWriteRefused $refusal) {
            $this->assertStringContainsString("a 'updating' listener of Gadget", $refusal->refused);
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('Blue gadget', DB::table('widgets')->value('title'));
    }

    #[Test]
    public function it_rolls_back_its_transaction_before_the_next_statement_after_a_database_refusal_outside_a_transaction(): void
    {
        $widget = $this->widget();
        $this->refuseUuidChange($widget);

        $this->assertSame(1, DB::transactionLevel(), 'Declared limit: the event bridge sees a failed statement only when the next statement starts, so until then the connection still counts its transaction.');

        $this->assertSame(1, DB::table('gadgets')->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('Blue gadget', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_leaves_a_caller_transaction_aborted_after_a_database_refusal_inside_it_as_a_native_write_would(): void
    {
        $widget = $this->widget();
        DB::beginTransaction();
        $this->refuseUuidChange($widget);

        try {
            DB::table('gadgets')->count();
            $this->fail('Expected the caller transaction to stay aborted, as it would after a native failed statement.');
        } catch (QueryException $e) {
            $this->assertSame('25P02', $e->getCode());
        }

        DB::rollBack();
        $this->assertSame(0, DB::transactionLevel());
    }

    #[Test]
    public function it_rolls_back_its_transaction_when_a_queued_bridged_write_fails_so_the_worker_records_the_failed_job(): void
    {
        $widget = $this->widget();
        $queue = 'compatibility-'.Str::random(8);
        $this->isolateTheDatabaseQueue();
        $id = $widget->id;

        dispatch(static fn () => Widget::query()->findOrFail($id)->update(['uuid' => '00000000-0000-0000-0000-000000000000']))
            ->onConnection('isolated')
            ->onQueue($queue);

        $this->artisan('queue:work', ['connection' => 'isolated', '--queue' => $queue, '--once' => true, '--tries' => 1])->assertSuccessful();

        $failed = DB::table('failed_jobs')->where('queue', $queue)->sole();
        $this->assertStringContainsString('CM001', (string) $failed->exception);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('Blue gadget', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_releases_the_transaction_left_by_a_database_refusal_when_the_application_terminates(): void
    {
        $this->refuseUuidChange($this->widget());

        $this->app->terminate();

        $this->assertSame(0, DB::transactionLevel());
    }

    #[Test]
    public function it_releases_the_transaction_left_by_a_database_refusal_on_the_next_bridged_write(): void
    {
        $widget = $this->widget();
        $this->refuseUuidChange($widget);
        $widget->syncOriginalAttribute('uuid');
        $widget->title = 'Next write';

        $widget->save();

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('Next write', DB::table('gadgets')->value('name'));
    }

    #[Test]
    public function it_raises_the_force_delete_events_of_a_dependent_row_committed_before_the_primary_record_is_locked(): void
    {
        $widget = $this->widget()->refresh();
        $root = DB::table('gadget_places')->value('id');
        $this->recordMirroredEvents();
        $this->concurrently(
            fn (string $query) => str_ends_with($query, ' for update'),
            fn (Connection $concurrent) => $concurrent->table('gadget_places')->insert(['gadget_id' => $widget->gadget_id, 'parent_id' => $root, 'street' => '2 Child St']),
        );

        $widget->forceDelete();

        $this->assertSame('committed', $this->concurrentOutcome);
        $this->assertMirrored(GadgetPlace::class, ModelEvent::ForceDeleted, times: 2);
        $this->assertSame(0, DB::table('gadget_places')->count());
    }

    #[Test]
    public function it_raises_the_update_events_of_a_readable_dependent_row_committed_before_the_primary_record_is_locked(): void
    {
        $widget = Widget::query()->create(['title' => 'No place yet'])->refresh();
        $this->recordMirroredEvents();
        $this->concurrently(
            fn (string $query) => str_ends_with($query, ' for update'),
            fn (Connection $concurrent) => $concurrent->table('gadget_places')->insert(['gadget_id' => $widget->gadget_id, 'street' => '2 Concurrent St']),
        );

        $widget->update(['street' => '3 Updated St']);

        $this->assertSame('committed', $this->concurrentOutcome);
        $this->assertMirrored(GadgetPlace::class, ModelEvent::Updated, fn (Model $place, RecordMirrored $event) => $event->rowBefore['street'] === '2 Concurrent St' && $place->getAttribute('street') === '3 Updated St');
        $this->assertSame('3 Updated St', DB::table('gadget_places')->value('street'));
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function it_locks_the_own_record_of_a_model_loaded_without_its_mapped_key_before_the_statement(bool $strict): void
    {
        Model::preventAccessingMissingAttributes($strict);
        $id = $this->widget()->getKey();
        $widget = Widget::query()->select(['uuid', 'title'])->firstOrFail();
        $widget->setKeyName('uuid')->setKeyType('string')->setIncrementing(false);
        $this->concurrently(
            fn (string $query) => str_starts_with($query, 'update "widgets"'),
            fn (Connection $concurrent) => $concurrent->table('retained_widgets')->where('id', $id)->update(['sku' => 'Concurrent']),
        );

        $widget->update(['title' => 'Loaded without its id']);

        $this->assertSame('55P03', $this->concurrentOutcome);
        $this->assertNull(DB::table('retained_widgets')->value('sku'));
        $this->assertSame('Loaded without its id', DB::table('gadgets')->value('name'));
    }

    /**
     * @param  array<string, string>  $extra
     */
    #[Test]
    #[TestWith(['increment', [], 49.0])]
    #[TestWith(['increment', ['sku' => 'Counted'], 49.0])]
    #[TestWith(['decrement', [], 46.0])]
    #[TestWith(['decrement', ['sku' => 'Counted'], 46.0])]
    public function it_locks_and_mirrors_an_increment_or_decrement_of_a_model_that_holds_an_unsaved_change_outside_a_transaction(string $method, array $extra, float $latitude): void
    {
        $widget = $this->locatedWidget();

        $widget->{$method}('latitude', 1.5, $extra);

        $this->assertLockedAndMirrored($widget, $extra, $latitude);
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
    public function it_locks_and_mirrors_an_increment_or_decrement_of_each_column_of_a_model_that_holds_an_unsaved_change_outside_a_transaction(string $method, array $extra, float $latitude): void
    {
        $widget = $this->locatedWidget();

        $widget->{$method}(['latitude' => 1.5], $extra);

        $this->assertLockedAndMirrored($widget, $extra, $latitude);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    #[Test]
    #[TestWith(['increment', ['latitude' => 1.0, 'updated_at' => '2026-01-01 00:00:00+00']])]
    #[TestWith(['update', ['title' => 'Written by a listener', 'latitude' => 1.0]])]
    public function it_locks_and_mirrors_a_write_on_its_own_statement_and_not_on_a_listeners_update_of_the_same_columns_outside_a_transaction(string $method, array $values): void
    {
        $widget = $this->locatedWidget(2);
        Event::listen('eloquent.updating: '.Widget::class.'*', function (string $name, array $payload) use ($values): void {
            if ($payload[0] instanceof Widget) {
                DB::table('widgets')->where('id', $payload[0]->getKey())->update($values);
            }
        });

        $method === 'increment' ? $widget->increment('latitude', 1.5) : $widget->update(['latitude' => 2.5]);

        $this->assertSame('55P03', $this->concurrentOutcome);
        $this->assertMirrored(GadgetSite::class, ModelEvent::Updated, fn (Model $site, RecordMirrored $event) => (float) $site->getAttribute('latitude') === 2.5 && (float) ($event->rowBefore['latitude'] ?? null) === 1.0);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertNull(DB::table('retained_widgets')->where('id', $widget->getKey())->value('sku'));
    }

    #[Test]
    public function it_mirrors_both_writes_when_a_beginning_listener_writes_another_mapped_model_outside_a_transaction(): void
    {
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
        $this->assertSame(0, DB::transactionLevel());
    }

    #[Test]
    public function it_mirrors_the_outer_write_when_a_beginning_listener_catches_a_refused_write_to_another_mapped_model_outside_a_transaction(): void
    {
        $widget = $this->widget();
        $other = Widget::query()->create(['title' => 'Other gadget']);
        Event::listen('eloquent.updating: '.Widget::class.'*', function (string $name, array $payload) use ($other): void {
            if ($payload[0] instanceof Widget && $payload[0]->isNot($other)) {
                try {
                    $other->update(['uuid' => '00000000-0000-0000-0000-000000000000']);
                } catch (QueryException) {
                }
            }
        });
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Renamed']);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed');
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(['Renamed', 'Other gadget'], [$widget->refresh()->title, $other->refresh()->title]);
    }

    #[Test]
    public function it_opens_no_transaction_and_takes_no_lock_when_a_listener_throws_before_the_statement(): void
    {
        $widget = $this->widget()->refresh();
        Event::listen('eloquent.updating: '.Widget::class.'*', function (): void {
            throw new RuntimeException('Thrown before the statement.');
        });

        $this->assertThrows(fn () => $widget->update(['title' => 'Never written']), RuntimeException::class, 'Thrown before the statement.');

        $this->assertSame(0, DB::transactionLevel());
        config(['database.connections.concurrent' => config('database.connections.'.config('database.default'))]);
        $this->beforeApplicationDestroyed(fn () => DB::purge('concurrent'));
        $concurrent = DB::connection('concurrent');
        $concurrent->statement("set lock_timeout = '200ms'");
        $locked = $concurrent->transaction(fn () => $concurrent->table('gadgets')->where('id', $widget->gadget_id)->lockForUpdate()->value('name'));
        $this->assertSame('Blue gadget', $locked);
        $this->recordMirroredEvents();
        Widget::query()->whereKey($widget->getKey())->update(['title' => 'Written without events']);
        $this->assertNothingMirrored();
        $this->assertSame(0, DB::transactionLevel());
    }

    #[Test]
    public function it_rolls_back_a_mirrored_write_whose_commit_fails_and_leaves_the_connection_outside_a_transaction(): void
    {
        $widget = $this->widget();
        DB::unprepared("create function refuse_at_commit() returns trigger language plpgsql as \$refuse\$ begin if NEW.name = 'Refused at commit' then raise exception 'refused at commit'; end if; return NEW; end \$refuse\$");
        DB::statement('create constraint trigger refuse_at_commit after update on gadgets deferrable initially deferred for each row execute function refuse_at_commit()');
        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('drop function if exists refuse_at_commit() cascade');
        });

        $this->assertThrows(fn () => $widget->update(['title' => 'Refused at commit']), PDOException::class, 'refused at commit');

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('Blue gadget', DB::table('gadgets')->value('name'));
        $this->recordMirroredEvents();
        $widget->refresh()->update(['title' => 'After a failed commit']);
        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'After a failed commit');
        $this->assertSame(0, DB::transactionLevel());
    }

    /**
     * @param  Closure(string): bool  $before
     * @param  Closure(Connection): mixed  $write
     */
    private function concurrently(Closure $before, Closure $write): void
    {
        config(['database.connections.concurrent' => config('database.connections.'.config('database.default'))]);
        $this->beforeApplicationDestroyed(fn () => DB::purge('concurrent'));
        $concurrent = DB::connection('concurrent');
        $concurrent->statement("set lock_timeout = '200ms'");

        DB::connection()->beforeExecuting(function (string $query) use ($before, $write, $concurrent): void {
            if ($this->concurrentOutcome !== null || ! $before($query)) {
                return;
            }

            try {
                $write($concurrent);
                $this->concurrentOutcome = 'committed';
            } catch (QueryException $e) {
                $this->concurrentOutcome = (string) $e->getCode();
            }
        });
    }

    private function widget(): Widget
    {
        return Widget::query()->create(['title' => 'Blue gadget', 'street' => '1 Main St']);
    }

    private function locatedWidget(int $update = 1): Widget
    {
        $widget = Widget::query()->create(['title' => 'Blue gadget', 'street' => '1 Main St', 'latitude' => 47.5])->refresh();
        $widget->setAttribute('title', 'Never saved');
        $this->recordMirroredEvents();
        $updates = 0;
        $this->concurrently(
            function (string $query) use ($update, &$updates): bool {
                return str_starts_with($query, 'update "widgets"') && ++$updates === $update;
            },
            fn (Connection $concurrent) => $concurrent->table('retained_widgets')->where('id', $widget->getKey())->update(['sku' => 'Concurrent']),
        );

        return $widget;
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function assertLockedAndMirrored(Widget $widget, array $extra, float $latitude): void
    {
        $this->assertSame('55P03', $this->concurrentOutcome);
        $this->assertMirrored(GadgetSite::class, ModelEvent::Updated, fn (Model $site) => (float) $site->getAttribute('latitude') === $latitude);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($extra['sku'] ?? null, DB::table('retained_widgets')->where('id', $widget->getKey())->value('sku'));
        $this->assertSame('Blue gadget', DB::table('gadgets')->value('name'));
    }

    private function refuseUuidChange(Widget $widget): void
    {
        try {
            $widget->update(['uuid' => '00000000-0000-0000-0000-000000000000']);
            $this->fail('Expected the database to refuse the change.');
        } catch (QueryException $e) {
            $this->assertTrue(CompatibilityWriteRefused::isRefusal($e));
        }
    }

    private function isolateTheDatabaseQueue(): void
    {
        $default = (string) config('database.default');

        config([
            'database.connections.isolated' => config("database.connections.{$default}"),
            'queue.connections.isolated' => ['driver' => 'database', 'connection' => 'isolated', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90],
            'queue.failed' => ['driver' => 'database-uuids', 'database' => $default, 'table' => 'failed_jobs'],
        ]);

        Schema::dropIfExists('jobs');
        Schema::dropIfExists('failed_jobs');

        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        $this->beforeApplicationDestroyed(function (): void {
            DB::rollBack(0);
            Schema::dropIfExists('jobs');
            Schema::dropIfExists('failed_jobs');
            DB::purge('isolated');
        });
    }
}
