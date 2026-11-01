<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\Attribute;
use Support\Database\Eloquent\Compatibility\Mapping\DependentCurrentModel;
use Support\Database\Eloquent\Compatibility\Mapping\ExcludedListener;
use Support\Database\Eloquent\Compatibility\Mapping\ModelEvent;
use Support\Database\Eloquent\Compatibility\Mapping\PreviousModel;
use Support\Database\Eloquent\Compatibility\Mapping\PrimaryCurrentModel;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Support\Database\Eloquent\Compatibility\Testing\InteractsWithCompatibilityMaps;
use Support\Database\Eloquent\Compatibility\Write\Events\RecordMirrored;
use Tests\Fixtures\Support\Workbench\Account;
use Tests\Fixtures\Support\Workbench\AccountCompatibilityMap;
use Tests\Fixtures\Support\Workbench\CompatibilityMapEventMirror;
use Tests\Fixtures\Support\Workbench\Gadget;
use Tests\Fixtures\Support\Workbench\GadgetPlace;
use Tests\Fixtures\Support\Workbench\GadgetSite;
use Tests\Fixtures\Support\Workbench\Person;
use Tests\Fixtures\Support\Workbench\Profile;
use Tests\Fixtures\Support\Workbench\ProfileCompatibilityMap;
use Tests\Fixtures\Support\Workbench\Tables;
use Tests\Fixtures\Support\Workbench\Widget;
use Tests\Fixtures\Support\Workbench\WidgetCompatibilityMap;
use Tests\TestCase;

#[CoversClass(WriteRouter::class)]
#[CoversClass(PreviousWriter::class)]
#[CoversClass(CurrentWriter::class)]
#[CoversClass(RoutesCompatibleWrites::class)]
#[CoversClass(Records::class)]
final class WriteRouterTest extends TestCase
{
    use InteractsWithCompatibilityMaps;

    /** @var list<string> */
    private array $fired = [];

    protected function setUp(): void
    {
        parent::setUp();

        Tables::create();
        resolve(Installer::class)->install(new WidgetCompatibilityMap);
        resolve(WriteRouter::class)->register(new CompatibilityMapEventMirror(new WidgetCompatibilityMap));
    }

    #[Test]
    public function it_creates_a_previous_model_through_its_current_models_and_reads_it_back_through_the_view(): void
    {
        $widget = Widget::query()->create(['sku' => 'W-1', 'title' => 'Blue widget', 'price' => '12.50', 'street' => '1 Main St', 'latitude' => 47.6]);

        $gadget = Gadget::query()->sole();
        $this->assertSame('Blue widget', $gadget->name);
        $this->assertSame(1250, $gadget->price_cents);
        $this->assertSame('1 Main St', GadgetPlace::query()->sole()->getAttribute('street'));
        $this->assertSame(47.6, (float) GadgetSite::query()->sole()->getAttribute('latitude'));
        $this->assertSame(['sku' => 'W-1', 'status' => 'Draft', 'gadget_id' => $gadget->id], (array) DB::table('retained_widgets')->select('sku', 'status', 'gadget_id')->first());

        $read = Widget::query()->findOrFail($widget->id);
        $this->assertSame(['Blue widget', '12.50', '1 Main St', 47.6, $gadget->id], [$read->title, $read->price, $read->street, (float) $read->latitude, $read->gadget_id]);
    }

    #[Test]
    public function it_creates_a_dependent_row_only_when_the_write_stores_a_value_in_it(): void
    {
        Widget::query()->create(['title' => 'Blue widget']);

        $this->assertSame(1, Gadget::query()->count());
        $this->assertSame(0, GadgetPlace::query()->count());
        $this->assertSame(0, GadgetSite::query()->count());
    }

    #[Test]
    public function it_fills_a_current_column_from_the_previous_model_on_create(): void
    {
        $id = '00000000-0000-4000-8000-0000000000aa';

        $widget = Widget::query()->create(['title' => 'Blue widget', 'gadget_id' => $id]);

        $this->assertSame($id, Gadget::query()->sole()->getKey());
        $this->assertSame($id, Widget::query()->findOrFail($widget->id)->gadget_id);
    }

    #[Test]
    public function it_fires_the_current_models_own_events_for_a_previous_models_own_write(): void
    {
        $this->watch(Gadget::class, ['creating', 'created', 'updating', 'updated']);
        $this->watch(GadgetPlace::class, ['created', 'updated']);
        $widget = Widget::query()->create(['title' => 'Blue widget', 'street' => '1 Main St']);

        $widget->update(['title' => 'Renamed']);

        $this->assertSame(['Gadget creating', 'Gadget created', 'GadgetPlace created', 'Gadget updating', 'Gadget updated'], $this->fired);
        $this->assertSame('Renamed', Gadget::query()->sole()->name);
    }

    #[Test]
    public function it_lets_a_current_models_listener_change_the_value_it_stores(): void
    {
        Gadget::saving(function (Gadget $gadget): void {
            $gadget->name = strtoupper($gadget->name);
        });

        $widget = Widget::query()->create(['title' => 'Blue widget']);

        $this->assertSame('BLUE WIDGET', Gadget::query()->sole()->name);
        $this->assertSame('BLUE WIDGET', $widget->fresh()?->title);
    }

    #[Test]
    public function it_refuses_the_whole_write_when_a_current_models_listener_vetoes_its_save(): void
    {
        $widget = Widget::query()->create(['title' => 'Blue widget', 'street' => '1 Main St']);
        GadgetPlace::updating(fn () => false);

        $refusal = $this->assertWriteRefused(fn () => $widget->update(['title' => 'Renamed', 'street' => '2 Second St']), 'a listener of GadgetPlace');

        $this->assertStringContainsString("Compatibility map 'widgets' refused this write", $refusal->refused);
        $this->assertSame('Blue widget', Gadget::query()->sole()->name);
    }

    #[Test]
    public function it_writes_only_the_attributes_that_changed(): void
    {
        $widget = Widget::query()->create(['title' => 'Blue widget', 'street' => '1 Main St', 'latitude' => 1]);
        $this->watch(GadgetPlace::class, ['updated']);
        $this->watch(GadgetSite::class, ['updated']);

        $widget->update(['latitude' => 2.5]);

        $this->assertSame(['GadgetSite updated'], $this->fired);
        $this->assertSame(2.5, (float) GadgetSite::query()->sole()->getAttribute('latitude'));
    }

    #[Test]
    public function it_creates_a_missing_dependent_row_on_an_update_that_stores_a_value_in_it(): void
    {
        $widget = Widget::query()->create(['title' => 'Blue widget']);

        $widget->update(['street' => '1 Main St']);

        $this->assertSame('1 Main St', GadgetPlace::query()->sole()->getAttribute('street'));
        $this->assertSame('1 Main St', $widget->fresh()?->street);
    }

    #[Test]
    public function it_refuses_a_change_to_a_read_only_attribute(): void
    {
        $widget = Widget::query()->create(['title' => 'Blue widget']);

        $refusal = $this->assertWriteRefused(fn () => Widget::query()->whereKey($widget->id)->update(['uuid' => '00000000-0000-4000-8000-000000000001']));

        $this->assertSame("Compatibility map 'widgets' refused a change to widgets.uuid on record {$widget->id}", $refusal->refused);
        $this->assertSame('A widget keeps the public id partners hold.', $refusal->because);
    }

    #[Test]
    public function it_refuses_an_update_the_compatibility_map_declares_unrepresentable(): void
    {
        $widget = Widget::query()->create(['title' => 'Blue widget', 'is_archived' => true]);

        $refusal = $this->assertWriteRefused(fn () => $widget->update(['status' => 'Live']));

        $this->assertSame("Compatibility map 'widgets' refused a status change on an archived gadget on widgets record {$widget->id}", $refusal->refused);
        $this->assertSame('Draft', DB::table('retained_widgets')->value('status'));
    }

    #[Test]
    public function it_refuses_a_refused_state_whoever_writes_it(): void
    {
        $widget = Widget::query()->create(['title' => 'Blue widget', 'street' => '1 Main St']);

        $refusal = $this->assertWriteRefused(fn () => GadgetPlace::query()->create(['gadget_id' => $widget->fresh()?->gadget_id, 'street' => '2 Second St']));

        $this->assertStringStartsWith("Compatibility map 'widgets' refused a second root place for one gadget in gadget_places record ", $refusal->refused);
        $this->assertSame(1, GadgetPlace::query()->count());
    }

    #[Test]
    public function it_refuses_an_attribute_the_compatibility_map_does_not_declare(): void
    {
        $refusal = $this->assertWriteRefused(fn () => Widget::query()->create(['title' => 'Blue widget', 'colour' => 'blue']));

        $this->assertSame("Compatibility map 'widgets' refused a write of colour through widgets", $refusal->refused);
        $this->assertSame(0, Gadget::query()->count());
    }

    #[Test]
    public function it_refuses_a_raw_sql_value_through_the_previous_model(): void
    {
        Widget::query()->create(['title' => 'Blue widget']);

        $this->assertWriteRefused(fn () => Widget::query()->update(['title' => DB::raw("'Raw'")]), 'refused a raw SQL value for widgets.title');
    }

    #[Test]
    public function it_soft_deletes_and_restores_the_primary_current_model_through_its_own_methods(): void
    {
        $widget = Widget::query()->create(['title' => 'Blue widget']);
        $this->watch(Gadget::class, ['deleting', 'trashed', 'deleted', 'restoring', 'restored']);

        $widget->delete();

        $this->assertSame(['Gadget deleting', 'Gadget trashed', 'Gadget deleted'], $this->fired);
        $this->assertTrue(Gadget::withTrashed()->sole()->trashed());
        $this->assertSame(Gadget::withTrashed()->sole()->deleted_at?->format('Y-m-d H:i:s'), Widget::withTrashed()->sole()->deleted_at?->format('Y-m-d H:i:s'));
        $this->assertNull(Widget::query()->find($widget->id));

        $this->fired = [];
        $widget->restore();

        $this->assertSame(['Gadget restoring', 'Gadget restored'], $this->fired);
        $this->assertFalse(Gadget::query()->sole()->trashed());
    }

    #[Test]
    public function it_force_deletes_every_current_row_and_the_retained_row(): void
    {
        $widget = Widget::query()->create(['title' => 'Blue widget', 'street' => '1 Main St', 'latitude' => 1]);
        GadgetPlace::query()->create(['gadget_id' => $widget->fresh()?->gadget_id, 'parent_id' => GadgetPlace::query()->sole()->id, 'street' => 'Unit 2']);

        $widget->forceDelete();

        $this->assertSame([0, 0, 0, 0], [Gadget::withTrashed()->count(), GadgetPlace::withTrashed()->count(), GadgetSite::query()->count(), DB::table('retained_widgets')->count()]);
    }

    #[Test]
    public function it_increments_from_the_value_the_locked_record_holds(): void
    {
        $widget = Widget::query()->create(['title' => 'Blue widget', 'latitude' => 1]);
        GadgetSite::query()->update(['latitude' => 10]);

        $widget->increment('latitude', 2);

        $this->assertSame(12.0, (float) GadgetSite::query()->sole()->getAttribute('latitude'));
    }

    #[Test]
    public function it_splits_a_query_builder_update_without_firing_the_current_models_events(): void
    {
        Widget::query()->create(['sku' => 'A', 'title' => 'First']);
        Widget::query()->create(['sku' => 'A', 'title' => 'Second']);
        Widget::query()->create(['sku' => 'B', 'title' => 'Third']);
        $this->watch(Gadget::class, ['updating', 'updated']);

        $count = Widget::query()->where('sku', 'A')->update(['title' => 'Renamed']);

        $this->assertSame(2, $count);
        $this->assertSame([], $this->fired);
        $this->assertSame(['Renamed', 'Renamed', 'Third'], Gadget::query()->orderBy('created_at')->orderBy('name')->pluck('name')->sort()->values()->all() === ['Renamed', 'Renamed', 'Third'] ? ['Renamed', 'Renamed', 'Third'] : Gadget::query()->pluck('name')->all());
        $this->assertSame(['A', 'A'], Widget::query()->where('title', 'Renamed')->pluck('sku')->all());
    }

    #[Test]
    public function it_honours_a_limit_on_a_query_builder_update(): void
    {
        foreach (['First', 'Second', 'Third'] as $title) {
            Widget::query()->create(['title' => $title]);
        }

        Widget::query()->orderBy('id')->limit(2)->update(['sku' => 'Limited']);

        $this->assertSame(['Limited', 'Limited', null], Widget::query()->orderBy('id')->pluck('sku')->all());
    }

    #[Test]
    public function it_soft_deletes_through_a_query_builder_delete(): void
    {
        Widget::query()->create(['title' => 'Blue widget']);

        Widget::query()->delete();

        $this->assertSame(0, Widget::query()->count());
        $this->assertTrue(Gadget::withTrashed()->sole()->trashed());
    }

    #[Test]
    public function it_inserts_through_the_query_builder_and_returns_the_new_key(): void
    {
        $id = Widget::query()->insertGetId(['title' => 'Blue widget', 'street' => '1 Main St']);

        $this->assertSame('1 Main St', Widget::query()->findOrFail($id)->street);
        $this->assertTrue(Widget::query()->insert([['title' => 'Second'], ['title' => 'Third']]));
        $this->assertSame(3, Gadget::query()->count());
    }

    #[Test]
    public function it_skips_a_conflicting_row_of_an_ignored_insert(): void
    {
        $uuid = '00000000-0000-4000-8000-000000000001';
        Widget::query()->create(['title' => 'First', 'uuid' => $uuid]);

        $inserted = Widget::query()->insertOrIgnore([['title' => 'Conflict', 'uuid' => $uuid], ['title' => 'Second']]);

        $this->assertSame(1, $inserted);
        $this->assertSame(['First', 'Second'], Gadget::query()->orderBy('name')->pluck('name')->all());
    }

    #[Test]
    public function it_refuses_a_set_based_insert_it_cannot_follow_row_by_row(): void
    {
        $this->assertWriteRefused(fn () => Widget::query()->upsert([['id' => 1, 'title' => 'Blue widget']], ['id']), 'refused upsert() on widgets');
        $this->assertWriteRefused(fn () => Gadget::query()->insertUsing(['name'], DB::table('people')->select('email')), 'refused insertUsing() on gadgets');
    }

    #[Test]
    public function it_leaves_a_write_that_bypasses_eloquent_to_the_read_only_view(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('cannot insert into view "widgets"');

        DB::table('widgets')->insert(['title' => 'Blue widget']);
    }

    #[Test]
    public function it_creates_the_retained_row_of_a_primary_current_model_created_through_its_own_model(): void
    {
        $this->recordMirroredEvents();

        $gadget = Gadget::query()->create(['name' => 'Blue gadget', 'archived' => true]);

        $this->assertSame(['gadget_id' => $gadget->id, 'status' => 'Archived'], (array) DB::table('retained_widgets')->select('gadget_id', 'status')->first());
        $this->assertSame('Blue gadget', Widget::query()->sole()->title);
        $this->assertMirrored(Widget::class, ModelEvent::Created, fn (Widget $widget) => $widget->title === 'Blue gadget');
    }

    #[Test]
    public function it_creates_retained_rows_for_a_query_builder_insert_of_primary_current_models(): void
    {
        $this->recordMirroredEvents();

        Gadget::query()->insert([['name' => 'First'], ['name' => 'Second']]);

        $this->assertSame(['First', 'Second'], Widget::query()->orderBy('title')->pluck('title')->all());
        $this->assertNothingMirrored();
    }

    #[Test]
    public function it_mirrors_the_previous_models_events_for_a_current_models_own_update(): void
    {
        Widget::query()->create(['title' => 'Blue widget', 'street' => '1 Main St']);
        $this->recordMirroredEvents();

        GadgetPlace::query()->sole()->update(['street' => '2 Second St']);

        $this->assertMirrored(Widget::class, ModelEvent::Updating, fn (Widget $widget, RecordMirrored $mirrored) => $widget->street === '2 Second St' && ($mirrored->rowBefore['street'] ?? null) === '1 Main St');
        $this->assertMirrored(Widget::class, ModelEvent::Updated);
    }

    #[Test]
    public function it_refuses_a_current_write_a_previous_models_listener_refuses(): void
    {
        Widget::query()->create(['title' => 'Blue widget']);
        Widget::updating(fn () => false);

        $this->assertWriteRefused(fn () => Gadget::query()->sole()->update(['name' => 'Renamed']), "a 'updating' listener of Widget");

        $this->assertSame('Blue widget', Gadget::query()->sole()->name);
    }

    #[Test]
    public function it_mirrors_no_events_without_mirroring_but_still_keeps_the_models_in_step(): void
    {
        $this->recordMirroredEvents();

        $gadget = $this->withoutEventMirroring(fn () => Gadget::query()->create(['name' => 'Blue gadget']));

        $this->assertNothingMirrored();
        $this->assertSame($gadget->id, DB::table('retained_widgets')->value('gadget_id'));
    }

    #[Test]
    public function it_mirrors_the_deletion_of_a_primary_current_model_and_deletes_its_retained_row(): void
    {
        Widget::query()->create(['title' => 'Blue widget']);
        $this->recordMirroredEvents();

        Gadget::query()->sole()->forceDelete();

        $this->assertSame(0, DB::table('retained_widgets')->count());
        $this->assertMirrored(Widget::class, ModelEvent::Deleted);
    }

    #[Test]
    public function it_mirrors_nothing_for_the_current_models_a_previous_model_writes(): void
    {
        $this->recordMirroredEvents();

        $widget = Widget::query()->create(['title' => 'Blue widget', 'street' => '1 Main St']);
        $widget->update(['title' => 'Renamed']);
        $widget->delete();

        $this->assertNothingMirrored();
        $this->assertSame(1, DB::table('retained_widgets')->count());
    }

    #[Test]
    public function it_lets_a_current_models_listener_create_another_record_inside_a_previous_write(): void
    {
        Gadget::created(function (Gadget $gadget): void {
            if ($gadget->name === 'Blue widget') {
                Gadget::query()->create(['name' => 'Companion']);
            }
        });

        Widget::query()->create(['title' => 'Blue widget']);

        $this->assertSame(['Blue widget', 'Companion'], Widget::query()->orderBy('title')->pluck('title')->all());
    }

    #[Test]
    public function it_fills_an_empty_designation_when_a_readable_dependent_row_is_written(): void
    {
        $this->registerDesignatedMap();
        $widget = Widget::query()->create(['title' => 'Blue widget', 'street' => '1 Main St']);
        $first = GadgetPlace::query()->sole();

        $this->assertSame($first->id, DB::table('retained_widgets')->value('place_id'));

        DB::table('retained_widgets')->update(['place_id' => null]);
        $first->update(['street' => '1 Main Street']);

        $this->assertSame($first->id, DB::table('retained_widgets')->value('place_id'));
        $this->assertSame('1 Main Street', $widget->fresh()?->street);
    }

    #[Test]
    public function it_refuses_a_write_to_a_record_whose_designated_dependent_row_is_unreadable(): void
    {
        $this->registerDesignatedMap();
        $widget = Widget::query()->create(['title' => 'Blue widget', 'street' => '1 Main St']);
        DB::table('gadget_places')->update(['deleted_at' => now()]);

        $this->assertWriteRefused(fn () => $widget->update(['street' => '2 Second St']), 'its designated gadget_places row is not one the previous model reads');
    }

    #[Test]
    public function it_keeps_every_previous_model_over_a_shared_current_table_in_step(): void
    {
        $this->app->forgetInstance(WriteRouter::class);
        $this->app->singleton(WriteRouter::class);
        resolve(Installer::class)->install(new AccountCompatibilityMap);
        resolve(Installer::class)->install(new ProfileCompatibilityMap);
        resolve(WriteRouter::class)->register(new CompatibilityMapEventMirror(new AccountCompatibilityMap));
        resolve(WriteRouter::class)->register(new CompatibilityMapEventMirror(new ProfileCompatibilityMap));
        $this->recordMirroredEvents();

        $account = Account::query()->create(['email' => 'a@example.com', 'password_hint' => 'pets']);

        $this->assertSame(1, DB::table('retained_profiles')->count());
        $this->assertSame('a@example.com', Profile::query()->sole()->email);
        $this->assertMirrored(Profile::class, ModelEvent::Created);

        $account->update(['email' => 'b@example.com']);

        $this->assertMirrored(Profile::class, ModelEvent::Updated, fn (Profile $profile) => $profile->email === 'b@example.com');
        $this->assertSame('b@example.com', Person::query()->sole()->email);
    }

    #[Test]
    public function it_skips_an_excluded_listener_for_mirrored_events_only(): void
    {
        $this->app->forgetInstance(WriteRouter::class);
        $this->app->singleton(WriteRouter::class);
        resolve(WriteRouter::class)->register(new CompatibilityMapEventMirror(new WidgetCompatibilityMap, [ExcludedListener::of(VetoingListener::class, 'It guards the API only.')]));
        Event::listen('eloquent.updating: '.Widget::class, VetoingListener::class);
        $widget = Widget::query()->create(['title' => 'Blue widget']);

        Gadget::query()->sole()->update(['name' => 'Renamed']);

        $this->assertSame('Renamed', $widget->fresh()?->title);
    }

    #[Test]
    public function it_refuses_to_register_a_map_twice_or_a_model_without_the_trait(): void
    {
        $this->expectException(InvalidCompatibilityMap::class);
        $this->expectExceptionMessage('it is registered with the write router twice');

        resolve(WriteRouter::class)->register(new CompatibilityMapEventMirror(new WidgetCompatibilityMap));
    }

    #[Test]
    public function it_refuses_a_model_that_declares_its_own_builder(): void
    {
        $map = new class implements CompatibilityMap
        {
            public function name(): string
            {
                return 'plain';
            }

            public function previousModel(): PreviousModel
            {
                return PreviousModel::servedAsView('plain_widgets')->retainedInTable('retained_widgets')->forModel(OwnBuilderWidget::class)->aliasedAs('widget')->primaryForeignKey('gadget_id');
            }

            public function primaryCurrentModel(): PrimaryCurrentModel
            {
                return PrimaryCurrentModel::for(Gadget::class)->aliasedAs('gadget');
            }

            public function dependentCurrentModels(): array
            {
                return [];
            }

            public function attributes(): array
            {
                return [
                    Attribute::retained('id')->readOnlyBecause('A widget keeps its id.'),
                    Attribute::mapped('title')->asType('text')->storedAs('gadget', 'name'),
                    Attribute::retained('gadget_id')->readOnlyBecause('A widget stays tied to its gadget.'),
                ];
            }

            public function unrepresentableStates(): array
            {
                return [];
            }
        };

        $this->expectException(InvalidCompatibilityMap::class);
        $this->expectExceptionMessage('resolves newEloquentBuilder() to '.OwnBuilderWidget::class.'::newEloquentBuilder()');

        resolve(WriteRouter::class)->register(new CompatibilityMapEventMirror($map));
    }

    private function registerDesignatedMap(): void
    {
        $this->app->forgetInstance(WriteRouter::class);
        $this->app->singleton(WriteRouter::class);
        resolve(WriteRouter::class)->register(new CompatibilityMapEventMirror(new DesignatedWidgetCompatibilityMap));
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $events
     */
    private function watch(string $model, array $events): void
    {
        foreach ($events as $event) {
            Event::listen("eloquent.{$event}: {$model}", function () use ($model, $event): void {
                $this->fired[] = class_basename($model).' '.$event;
            });
        }
    }
}

final class VetoingListener
{
    public function handle(): bool
    {
        return false;
    }
}

final class DesignatedWidgetCompatibilityMap implements CompatibilityMap
{
    private WidgetCompatibilityMap $widgets;

    public function __construct()
    {
        $this->widgets = new WidgetCompatibilityMap;
    }

    public function name(): string
    {
        return $this->widgets->name();
    }

    public function previousModel(): PreviousModel
    {
        return $this->widgets->previousModel();
    }

    public function primaryCurrentModel(): PrimaryCurrentModel
    {
        return $this->widgets->primaryCurrentModel();
    }

    public function dependentCurrentModels(): array
    {
        return [
            DependentCurrentModel::for(GadgetPlace::class)
                ->aliasedAs('place')
                ->primaryForeignKey('gadget_id')
                ->readableWhen('place.parent_id IS NULL AND place.deleted_at IS NULL')
                ->designatedBy('place_id'),
            DependentCurrentModel::for(GadgetSite::class)->aliasedAs('site')->primaryForeignKey('gadget_id'),
        ];
    }

    public function attributes(): array
    {
        return $this->widgets->attributes();
    }

    public function unrepresentableStates(): array
    {
        return [];
    }
}

final class OwnBuilderWidget extends Model implements \Support\Database\Eloquent\Compatibility\Contracts\Compatible
{
    use \Support\Database\Eloquent\Compatibility\Provides\HasCompatibility;

    protected $table = 'plain_widgets';

    public function newEloquentBuilder($query)
    {
        return new \Illuminate\Database\Eloquent\Builder($query);
    }
}
