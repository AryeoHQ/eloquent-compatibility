<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Provides;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionClass;
use ReflectionMethod;
use Support\Database\Eloquent\Compatibility\Bridge\EventBridge;
use Support\Database\Eloquent\Compatibility\Bridge\Events\RecordMirrored;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityEventMirrorBypassed;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Support\Database\Eloquent\Compatibility\Testing\InteractsWithCompatibilityMaps;
use Tests\Fixtures\Support\Workbench\Account;
use Tests\Fixtures\Support\Workbench\AccountCompatibilityMap;
use Tests\Fixtures\Support\Workbench\CompatibilityMapEventMirror;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\GadgetSite;
use Tests\Fixtures\Support\Workbench\Person;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(EventBridge::class)]
#[CoversTrait(HasCompatibility::class)]
final class HasCompatibilityTest extends TestCase
{
    use InteractsWithCompatibilityMaps;

    private const array WRITE_METHODS = [
        'save' => [
            'it_mirrors_a_save_that_inserts_on_its_own_statement_and_not_on_a_listeners_insert_through_the_same_instance',
            'it_mirrors_a_save_that_updates_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance',
        ],
        'saveOrFail' => ['it_mirrors_save_or_fail_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance'],
        'saveOrIgnore' => ['it_mirrors_save_or_ignore_on_its_own_statement_and_not_on_a_listeners_insert_through_the_same_instance'],
        'update' => ['it_mirrors_update_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance'],
        'updateOrFail' => ['it_mirrors_update_or_fail_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance'],
        'push' => ['it_mirrors_push_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance'],
        'increment' => ['it_mirrors_increment_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance'],
        'decrement' => ['it_mirrors_decrement_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance'],
        'incrementEach' => ['it_mirrors_increment_each_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance'],
        'decrementEach' => ['it_mirrors_decrement_each_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance'],
        'delete' => [
            'it_mirrors_delete_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance',
            'it_mirrors_a_soft_delete_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance',
        ],
        'deleteOrFail' => ['it_mirrors_delete_or_fail_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance'],
        'forceDelete' => [
            'it_mirrors_force_delete_of_a_model_without_soft_deletes_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance',
            'it_mirrors_force_delete_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance',
        ],
        'restore' => ['it_mirrors_restore_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance'],
        'touch' => ['it_mirrors_touch_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance'],
        'saveQuietly' => ['it_stores_each_quiet_write_and_mirrors_none_of_them'],
        'updateQuietly' => ['it_stores_each_quiet_write_and_mirrors_none_of_them'],
        'pushQuietly' => ['it_stores_each_quiet_write_and_mirrors_none_of_them'],
        'incrementQuietly' => ['it_stores_each_quiet_write_and_mirrors_none_of_them'],
        'decrementQuietly' => ['it_stores_each_quiet_write_and_mirrors_none_of_them'],
        'touchQuietly' => ['it_stores_each_quiet_write_and_mirrors_none_of_them'],
        'deleteQuietly' => ['it_stores_each_quiet_write_and_mirrors_none_of_them'],
        'restoreQuietly' => ['it_stores_each_quiet_write_and_mirrors_none_of_them'],
        'forceDeleteQuietly' => ['it_stores_each_quiet_write_and_mirrors_none_of_them'],
        'incrementEachQuietly' => ['it_stores_each_quiet_write_of_each_column_and_mirrors_none_of_them'],
        'decrementEachQuietly' => ['it_stores_each_quiet_write_of_each_column_and_mirrors_none_of_them'],
    ];

    private const array NOT_INSTANCE_WRITES = [
        'touches' => 'it reports whether the model touches a relation, and writes nothing',
        'touchOwners' => "it touches each owner through its relation's query, which fires no model event, so the owner's map sees a bulk write",
        'updateTimestamps' => 'it sets the timestamp attributes, and sends no statement',
    ];

    private bool $listenerWrote = false;

    private bool $selected = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();
        Tables::create();
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror(new WidgetCompatibilityMap));
    }

    #[Test]
    public function it_covers_each_public_instance_write_method_of_eloquent_with_a_test_of_its_own(): void
    {
        $writes = [];

        foreach ([Model::class, SoftDeletes::class] as $class) {
            foreach (new ReflectionClass($class)->getMethods() as $method) {
                if (! $method->isStatic() && ($method->isPublic() || in_array($method->getName(), $this->forwardedByMagicCall(), true)) && preg_match('/^(?:save|update|insert|upsert|increment|decrement|delete|forceDelete|restore|touch|push)/', $method->getName()) === 1) {
                    $writes[] = $method->getName();
                }
            }
        }

        $this->assertContains('increment', $writes);
        $this->assertSame(
            [],
            array_values(array_diff(array_unique($writes), array_keys(self::WRITE_METHODS), array_keys(self::NOT_INSTANCE_WRITES))),
            'This Laravel version adds an instance write method that no test of HasCompatibility covers. Add a test that writes through it with a listener that writes through the same instance, and list it in WRITE_METHODS.',
        );

        foreach (array_intersect_key(self::WRITE_METHODS, array_flip($writes)) as $tests) {
            foreach ($tests as $test) {
                $this->assertTrue(method_exists($this, $test), "WRITE_METHODS names {$test}, which this test class does not declare.");
            }
        }
    }

    #[Test]
    public function it_mirrors_a_save_that_inserts_on_its_own_statement_and_not_on_a_listeners_insert_through_the_same_instance(): void
    {
        $this->writeThroughTheSameInstance('creating', Widget::class, fn (Model $widget) => $widget->newQuery()->insert(['title' => 'Inserted by a listener']));
        $this->recordMirroredEvents();

        (new Widget(['title' => 'Blue gadget']))->save();

        $this->assertMirrored(Gadget::class, ModelEvent::Created, fn (Model $gadget) => $gadget->getAttribute('name') === 'Blue gadget');
        $this->assertCount(1, array_filter($this->mirroredEvents(), fn (RecordMirrored $event) => $event->destination instanceof Gadget));
        $this->assertSame(['Blue gadget', 'Inserted by a listener'], DB::table('gadgets')->orderBy('name')->pluck('name')->all());
    }

    #[Test]
    public function it_mirrors_a_save_that_updates_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance(): void
    {
        $widget = $this->widget();
        $this->updateLatitudeThroughTheSameInstance('updating', Widget::class);
        $this->recordMirroredEvents();

        $widget->title = 'Renamed';
        $widget->save();

        $this->assertRenamedAndNotRelocated();
    }

    #[Test]
    public function it_mirrors_save_or_fail_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance(): void
    {
        $widget = $this->widget();
        $this->updateLatitudeThroughTheSameInstance('updating', Widget::class);
        $this->recordMirroredEvents();

        $widget->title = 'Renamed';
        $widget->saveOrFail();

        $this->assertRenamedAndNotRelocated();
    }

    #[RequiresMethod(Model::class, 'saveOrIgnore')]
    #[Test]
    public function it_mirrors_save_or_ignore_on_its_own_statement_and_not_on_a_listeners_insert_through_the_same_instance(): void
    {
        $this->writeThroughTheSameInstance('creating', Gadget::class, fn (Model $gadget) => $gadget->newQuery()->insert(['name' => 'Inserted by a listener']));
        $this->recordMirroredEvents();

        $saved = (new Gadget(['name' => 'Blue gadget']))->saveOrIgnore();

        $this->assertTrue($saved);
        $this->assertMirrored(Widget::class, ModelEvent::Created, fn (Model $widget) => $widget->getAttribute('title') === 'Blue gadget');
        $this->assertCount(1, $this->mirroredEvents());
        $this->assertSame(['Blue gadget', 'Inserted by a listener'], DB::table('widgets')->orderBy('title')->pluck('title')->all());
    }

    #[Test]
    public function it_mirrors_update_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance(): void
    {
        $widget = $this->widget();
        $this->updateLatitudeThroughTheSameInstance('updating', Widget::class);
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Renamed']);

        $this->assertRenamedAndNotRelocated();
    }

    #[Test]
    public function it_mirrors_update_or_fail_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance(): void
    {
        $widget = $this->widget();
        $this->updateLatitudeThroughTheSameInstance('updating', Widget::class);
        $this->recordMirroredEvents();

        $widget->updateOrFail(['title' => 'Renamed']);

        $this->assertRenamedAndNotRelocated();
    }

    #[Test]
    public function it_mirrors_push_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance(): void
    {
        $widget = $this->widget();
        $this->updateLatitudeThroughTheSameInstance('updating', Widget::class);
        $this->recordMirroredEvents();

        $widget->title = 'Renamed';
        $widget->push();

        $this->assertRenamedAndNotRelocated();
    }

    #[Test]
    public function it_mirrors_increment_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance(): void
    {
        $widget = $this->widget();
        $this->updateLatitudeThroughTheSameInstance('updating', Widget::class);
        $this->recordMirroredEvents();

        $widget->increment('latitude', 1.5);

        $this->assertRelocatedFromTheListenersLatitudeTo(2.5);
    }

    #[Test]
    public function it_mirrors_decrement_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance(): void
    {
        $widget = $this->widget();
        $this->updateLatitudeThroughTheSameInstance('updating', Widget::class);
        $this->recordMirroredEvents();

        $widget->decrement('latitude', 1.5);

        $this->assertRelocatedFromTheListenersLatitudeTo(-0.5);
    }

    #[RequiresMethod(Model::class, 'incrementOrDecrementEach')]
    #[Test]
    public function it_mirrors_increment_each_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance(): void
    {
        $widget = $this->widget();
        $this->updateLatitudeThroughTheSameInstance('updating', Widget::class);
        $this->recordMirroredEvents();

        $widget->incrementEach(['latitude' => 1.5]);

        $this->assertRelocatedFromTheListenersLatitudeTo(2.5);
    }

    #[RequiresMethod(Model::class, 'incrementOrDecrementEach')]
    #[Test]
    public function it_mirrors_decrement_each_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance(): void
    {
        $widget = $this->widget();
        $this->updateLatitudeThroughTheSameInstance('updating', Widget::class);
        $this->recordMirroredEvents();

        $widget->decrementEach(['latitude' => 1.5]);

        $this->assertRelocatedFromTheListenersLatitudeTo(-0.5);
    }

    #[Test]
    public function it_mirrors_delete_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance(): void
    {
        $account = $this->account();
        $this->writeThroughTheSameInstance('deleting', Account::class, fn (Model $account) => $account->newQuery()->whereKey($account->getKey())->update(['email' => 'listener@example.test']));
        $this->recordMirroredEvents();

        $account->delete();

        $this->assertDeletedAfterTheListenersEmail();
    }

    #[Test]
    public function it_mirrors_force_delete_of_a_model_without_soft_deletes_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance(): void
    {
        $account = $this->account();
        $this->writeThroughTheSameInstance('deleting', Account::class, fn (Model $account) => $account->newQuery()->whereKey($account->getKey())->update(['email' => 'listener@example.test']));
        $this->recordMirroredEvents();

        $account->forceDelete();

        $this->assertDeletedAfterTheListenersEmail();
    }

    #[Test]
    public function it_mirrors_a_soft_delete_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance(): void
    {
        $widget = $this->widget();
        $this->updateLatitudeThroughTheSameInstance('deleting', Widget::class);
        $this->recordMirroredEvents();

        $widget->delete();

        $this->assertTrashedAndNotRelocated();
    }

    #[Test]
    public function it_mirrors_delete_or_fail_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance(): void
    {
        $widget = $this->widget();
        $this->updateLatitudeThroughTheSameInstance('deleting', Widget::class);
        $this->recordMirroredEvents();

        $widget->deleteOrFail();

        $this->assertTrashedAndNotRelocated();
    }

    #[Test]
    public function it_mirrors_force_delete_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance(): void
    {
        $widget = $this->widget();
        $this->updateLatitudeThroughTheSameInstance('deleting', Widget::class);
        $this->recordMirroredEvents();

        $widget->forceDelete();

        $this->assertMirrored(Gadget::class, ModelEvent::ForceDeleted, fn (Model $gadget) => ! $gadget->exists);
        $this->assertMirrored(GadgetSite::class, ModelEvent::Deleted, fn (Model $site, RecordMirrored $event) => (float) ($event->rowBefore['latitude'] ?? null) === 1.0);
        $this->assertSame(0, DB::table('gadget_sites')->count());
    }

    #[Test]
    public function it_mirrors_restore_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance(): void
    {
        $widget = $this->widget();
        $widget->delete();
        $this->writeThroughTheSameInstance('updating', Widget::class, fn (Model $widget) => $widget->newQuery()->withoutGlobalScopes()->whereKey($widget->getKey())->update(['latitude' => 1.0]));
        $this->recordMirroredEvents();

        $widget->restore();

        $this->assertMirrored(Gadget::class, ModelEvent::Restored, fn (Model $gadget) => $gadget->getAttribute('deleted_at') === null);
        $this->assertNotMirrored(GadgetSite::class);
        $this->assertSame(1.0, (float) DB::table('gadget_sites')->value('latitude'));
    }

    #[Test]
    public function it_mirrors_touch_on_its_own_statement_and_not_on_a_listeners_update_through_the_same_instance(): void
    {
        $widget = $this->widget();
        $this->writeThroughTheSameInstance('updating', Widget::class, fn (Model $widget) => $widget->newQuery()->toBase()->where('id', $widget->getKey())->update(['latitude' => 1.0]));
        $this->travel(1)->minute();
        $this->recordMirroredEvents();

        $widget->touch();

        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget, RecordMirrored $event) => $event->rowBefore['updated_at'] !== $event->rowAfter['updated_at']);
        $this->assertNotMirrored(GadgetSite::class);
        $this->assertSame(1.0, (float) DB::table('gadget_sites')->value('latitude'));
    }

    #[Test]
    public function it_stores_each_quiet_write_and_mirrors_none_of_them(): void
    {
        $widget = $this->widget();
        $this->updateLatitudeThroughTheSameInstance('updating', Widget::class);
        $this->updateLatitudeThroughTheSameInstance('deleting', Widget::class);
        $this->recordMirroredEvents();

        $widget->title = 'Saved quietly';
        $widget->saveQuietly();
        $widget->updateQuietly(['title' => 'Updated quietly']);
        $widget->title = 'Pushed quietly';
        $widget->pushQuietly();
        $widget->incrementQuietly('latitude', 2.5); // @phpstan-ignore method.protected
        $widget->decrementQuietly('latitude', 0.5); // @phpstan-ignore method.protected
        $this->travel(1)->minute();
        $widget->touchQuietly();
        $widget->deleteQuietly();
        $deletedAt = DB::table('gadgets')->value('deleted_at');
        $widget->restoreQuietly();
        $restoredAt = DB::table('gadgets')->value('deleted_at');
        $widget->forceDeleteQuietly();

        $this->assertNothingMirrored();
        $this->assertFalse($this->listenerWrote);
        $this->assertNotNull($deletedAt);
        $this->assertNull($restoredAt);
        $this->assertSame(0, DB::table('gadgets')->count());
    }

    #[RequiresMethod(Model::class, 'incrementOrDecrementEach')]
    #[Test]
    public function it_stores_each_quiet_write_of_each_column_and_mirrors_none_of_them(): void
    {
        $widget = $this->widget();
        $this->updateLatitudeThroughTheSameInstance('updating', Widget::class);
        $this->recordMirroredEvents();

        $widget->incrementEachQuietly(['latitude' => 2.5]); // @phpstan-ignore method.protected
        $widget->decrementEachQuietly(['latitude' => 0.5]); // @phpstan-ignore method.protected

        $this->assertNothingMirrored();
        $this->assertFalse($this->listenerWrote);
        $this->assertSame(49.5, (float) DB::table('gadget_sites')->value('latitude'));
    }

    #[Test]
    public function it_mirrors_a_write_on_its_own_statement_and_not_on_a_quiet_write_of_the_same_instance_inside_its_listener(): void
    {
        $widget = $this->widget();
        $this->writeThroughTheSameInstance('updating', Widget::class, fn (Model $widget) => Widget::withoutEvents(fn () => $widget->increment('latitude', 1.5)));
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Renamed']);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed');
        $this->assertNotMirrored(GadgetSite::class);
        $this->assertSame(49.0, (float) DB::table('gadget_sites')->value('latitude'));
    }

    #[Test]
    public function it_mirrors_a_write_on_its_own_statement_and_not_on_a_write_of_the_same_instance_its_listener_makes_without_mirroring(): void
    {
        $widget = $this->widget();
        $this->writeThroughTheSameInstance('updating', Widget::class, fn (Model $widget) => $this->withoutEventMirroring(fn () => $widget->increment('latitude', 1.5)));
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Renamed']);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed');
        $this->assertNotMirrored(GadgetSite::class);
        $this->assertSame(49.0, (float) DB::table('gadget_sites')->value('latitude'));
    }

    #[Test]
    public function it_mirrors_both_updates_when_an_updating_listener_saves_the_same_instance_and_then_changes_another_attribute(): void
    {
        $widget = $this->widget();
        $this->writeThroughTheSameInstance('updating', Widget::class, function (Model $widget): void {
            $widget->save();
            $widget->setAttribute('latitude', 2.0);
        });
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Renamed']);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed');
        $this->assertMirrored(GadgetSite::class, ModelEvent::Updated, fn (Model $site, RecordMirrored $event) => (float) ($event->rowBefore['latitude'] ?? null) === 47.5 && (float) $site->getAttribute('latitude') === 2.0);
        $this->assertCount(2, $this->mirroredEvents());
        $this->assertSame(['Renamed', 2.0], [DB::table('gadgets')->value('name'), (float) DB::table('gadget_sites')->value('latitude')]);
    }

    #[Test]
    public function it_opens_an_armed_write_on_its_own_update_and_not_on_a_select_an_earlier_callback_runs_first(): void
    {
        DB::connection()->beforeExecuting(function (string $query): void {
            if (! $this->selected && str_starts_with($query, 'update "widgets"')) {
                $this->selected = true;
                DB::select('select 1');
            }
        });
        $widget = $this->widget();
        $this->recordMirroredEvents();

        $widget->update(['title' => 'Renamed']);

        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget, RecordMirrored $event) => $event->rowBefore['name'] === 'Blue gadget' && $gadget->getAttribute('name') === 'Renamed');
    }

    #[Test]
    #[TestWith(['creating', 'created'])]
    #[TestWith(['updating', 'updated'])]
    #[TestWith(['deleting', 'deleted'])]
    public function it_refuses_a_write_whose_statement_ran_without_its_mark_and_rolls_it_back_inside_a_transaction(string $beginning, string $finishing): void
    {
        $widget = $beginning === 'creating' ? new Widget(['title' => 'Unmarked']) : $this->widget();
        $this->recordMirroredEvents();

        $this->assertThrows(
            fn () => DB::transaction(function () use ($widget, $beginning, $finishing): void {
                Event::until("eloquent.{$beginning}: ".Widget::class, $widget);
                match ($beginning) {
                    'creating' => Widget::query()->insert(['title' => 'Unmarked']),
                    'updating' => Widget::query()->whereKey($widget->getKey())->update(['title' => 'Unmarked']),
                    default => Widget::query()->whereKey($widget->getKey())->delete(),
                };
                Event::dispatch("eloquent.{$finishing}: ".Widget::class, $widget);
            }),
            CompatibilityEventMirrorBypassed::class,
            'The '.Widget::class." write that fired '{$finishing}' reached the database on a statement HasCompatibility never marked, so the event bridge mirrored none of it.",
        );

        $this->assertNothingMirrored();
        $this->assertSame($beginning === 'creating' ? [] : [['Blue gadget', null]], DB::table('gadgets')->get(['name', 'deleted_at'])->map(fn (object $gadget) => array_values((array) $gadget))->all());
    }

    #[Test]
    public function it_stops_a_write_under_a_faked_dispatcher_before_its_statement_reaches_the_database(): void
    {
        $widget = $this->widget();
        Event::fake();

        $this->assertThrows(fn () => $widget->update(['title' => 'Faked']), CompatibilityEventMirrorBypassed::class, "A write to 'widgets' was stopped before it reached the database");
        $this->assertThrows(fn () => (new Widget(['title' => 'Faked']))->save(), CompatibilityEventMirrorBypassed::class, "A write to 'widgets' was stopped before it reached the database");
        $this->assertThrows(fn () => $widget->delete(), CompatibilityEventMirrorBypassed::class, "A write to 'widgets' was stopped before it reached the database");

        $this->assertSame([['Blue gadget', null]], DB::table('gadgets')->get(['name', 'deleted_at'])->map(fn (object $gadget) => array_values((array) $gadget))->all());
    }

    #[Test]
    public function it_leaves_the_writes_of_a_model_in_no_mirrored_map_alone_under_a_fake(): void
    {
        $unmapped = new class(['password_hint' => 'Unmapped']) extends Model implements Compatible
        {
            use HasCompatibility;

            public $timestamps = false;

            protected $table = 'retained_accounts';

            protected $guarded = [];
        };
        Event::fake();

        $this->assertTrue($unmapped->save());
        $this->assertSame(['Unmapped'], DB::table('retained_accounts')->pluck('password_hint')->all());
        $this->assertTrue($unmapped->delete());
        $this->assertSame(0, DB::table('retained_accounts')->count());
    }

    /**
     * @return list<string>
     */
    private function forwardedByMagicCall(): array
    {
        $call = new ReflectionMethod(Model::class, '__call');
        $source = implode('', array_slice((array) file((string) $call->getFileName()), $call->getStartLine() - 1, $call->getEndLine() - $call->getStartLine() + 1));
        preg_match('/in_array\(\$method, \[([^\]]*)\]\)/', $source, $forwarded);
        preg_match_all("/'([A-Za-z]+)'/", $forwarded[1] ?? '', $names);

        return $names[1];
    }

    private function widget(): Widget
    {
        return Widget::query()->create(['title' => 'Blue gadget', 'street' => '1 Main St', 'latitude' => 47.5])->refresh();
    }

    private function account(): Account
    {
        resolve(Installer::class)->install(new AccountCompatibilityMap);
        resolve(EventBridge::class)->mirror(new CompatibilityMapEventMirror(new AccountCompatibilityMap));

        return Account::query()->create(['email' => 'one@example.test']);
    }

    /**
     * @param  class-string<Model>  $model
     * @param  Closure(Model): mixed  $write
     */
    private function writeThroughTheSameInstance(string $event, string $model, Closure $write): void
    {
        $fired = "eloquent.{$event}: {$model}";

        Event::listen($fired.'*', function (string $name, array $payload) use ($fired, $write): void {
            if ($name === $fired && $payload[0] instanceof Model && ! $this->listenerWrote) {
                $this->listenerWrote = true;
                $write($payload[0]);
            }
        });
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function updateLatitudeThroughTheSameInstance(string $event, string $model): void
    {
        $this->writeThroughTheSameInstance($event, $model, fn (Model $record) => $record->newQuery()->whereKey($record->getKey())->update(['latitude' => 1.0]));
    }

    private function assertRenamedAndNotRelocated(): void
    {
        $this->assertMirrored(Gadget::class, ModelEvent::Updated, fn (Model $gadget) => $gadget->getAttribute('name') === 'Renamed');
        $this->assertNotMirrored(GadgetSite::class);
        $this->assertSame(1.0, (float) DB::table('gadget_sites')->value('latitude'));
    }

    private function assertRelocatedFromTheListenersLatitudeTo(float $latitude): void
    {
        $this->assertMirrored(GadgetSite::class, ModelEvent::Updated, fn (Model $site, RecordMirrored $event) => (float) ($event->rowBefore['latitude'] ?? null) === 1.0 && (float) $site->getAttribute('latitude') === $latitude);
        $this->assertCount(1, $this->mirroredEvents());
    }

    private function assertTrashedAndNotRelocated(): void
    {
        $this->assertMirrored(Gadget::class, ModelEvent::Trashed, fn (Model $gadget) => $gadget->getAttribute('deleted_at') !== null);
        $this->assertNotMirrored(GadgetSite::class);
        $this->assertSame(1.0, (float) DB::table('gadget_sites')->value('latitude'));
    }

    private function assertDeletedAfterTheListenersEmail(): void
    {
        $this->assertMirrored(Person::class, ModelEvent::Deleted, fn (Model $person, RecordMirrored $event) => $event->rowBefore['email'] === 'listener@example.test');
        $this->assertCount(1, $this->mirroredEvents());
        $this->assertSame(0, DB::table('people')->count());
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function assertNotMirrored(string $model): void
    {
        $this->assertSame([], array_values(array_filter($this->mirroredEvents(), fn (RecordMirrored $event) => $event->destination instanceof $model)));
    }
}
