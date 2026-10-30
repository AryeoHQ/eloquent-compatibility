# eloquent-compatibility
Serve a previous Eloquent model as a PostgreSQL view over its current models' tables

## Installation
```bash
composer require aryeo/eloquent-compatibility
```

The package supports PostgreSQL 14, 16 and 18, on Laravel 12 and 13.

Install each map in a schema where only trusted roles can create objects. The compiled trigger functions run with their owner's rights, and they look names up in that schema. A function or an operator another role creates there can replace a built-in one inside them. PostgreSQL 14 lets every role create objects in `public` by default, and `REVOKE CREATE ON SCHEMA public FROM PUBLIC` withdraws that. [Adopting a map](docs/adopting-a-map.md#before-the-install-who-can-create-objects-in-the-installation-schema) describes the requirement.

## Usage
A compatibility map declares how the previous model's attributes map onto its current models. One record of the previous model becomes a primary current model and any number of dependent current models.

```php
use App\Models\Gadget;
use App\Models\GadgetPlace;
use App\Models\Widget;
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Mapping\Attribute;
use Support\Database\Eloquent\Compatibility\Mapping\DependentCurrentModel;
use Support\Database\Eloquent\Compatibility\Mapping\PreviousModel;
use Support\Database\Eloquent\Compatibility\Mapping\PrimaryCurrentModel;

final class WidgetCompatibilityMap implements CompatibilityMap
{
    public function name(): string
    {
        return 'widgets';
    }

    public function previousModel(): PreviousModel
    {
        return PreviousModel::servedAsView('widgets')
            ->retainedInTable('retained_widgets')
            ->forModel(Widget::class)
            ->aliasedAs('widget')
            ->primaryForeignKey('gadget_id');
    }

    public function primaryCurrentModel(): PrimaryCurrentModel
    {
        return PrimaryCurrentModel::for(Gadget::class)->aliasedAs('gadget');
    }

    public function dependentCurrentModels(): array
    {
        return [
            DependentCurrentModel::for(GadgetPlace::class)
                ->aliasedAs('place')
                ->primaryForeignKey('gadget_id')
                ->readableWhen('place.parent_id IS NULL'),
        ];
    }

    public function attributes(): array
    {
        return [
            Attribute::retained('id')->readOnlyBecause('A widget keeps the id other records hold.'),
            Attribute::retained('gadget_id')->readOnlyBecause('A widget stays tied to its gadget.'),
            Attribute::mapped('title')->asType('character varying(255)')->storedAs('gadget', 'name'),
            Attribute::mapped('street')->asType('character varying(255)')->storedAs('place', 'street'),
        ];
    }

    public function unrepresentableStates(): array
    {
        return [];
    }
}
```

Every model the map names, the previous model and each current model, implements `Contracts\Compatible` and uses `Provides\HasCompatibility`:

```php
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Contracts\Compatible;
use Support\Database\Eloquent\Compatibility\Provides\HasCompatibility;

final class Widget extends Model implements Compatible
{
    use HasCompatibility;
}
```

The trait marks each statement the model's own write methods send, so the event bridge mirrors that statement and no listener's. It also hands the bridge every model event. `EventBridge::mirror()` refuses a map whose model lacks the interface or the trait, or declares its own `setKeysForSaveQuery()`, `getAttributesForInsert()` or `fireModelEvent()`. [Adopting a map](docs/adopting-a-map.md#mark-each-mapped-model) describes the models that cannot carry the mark.

### PHPStan rules
The package registers four PHPStan rules in `tooling/phpstan/rules.neon`. An application that runs `tooling:phpstan:analyze` loads them, so it finds a model that breaks the contract before `EventBridge::mirror()` refuses it at boot:

- `CompatibleMustUseHasCompatibility`: a model that implements `Compatible` uses `HasCompatibility`.
- `HasCompatibilityMustImplementCompatible`: a model that uses `HasCompatibility` implements `Compatible`.
- `HasCompatibilityMustOnlyBeOnModel`: only a `Model` subclass implements `Compatible` or uses `HasCompatibility`.
- `HasCompatibilityMustNotRedeclareWriteHooks`: a class that uses `HasCompatibility`, or extends one that does, resolves `setKeysForSaveQuery()`, `getAttributesForInsert()` and `fireModelEvent()` to the trait's own methods. A declaration in the class or a parent fails, and so does a method another trait brings in.

The rules skip anonymous classes, which `EventBridge::mirror()` still checks at boot.

### Install and mirror
A migration installs the compatibility view and its triggers. A service provider registers an event mirror, so each side's model events fire for writes through the other. An event mirror implements `Contracts\EventMirror` and names the map:

```php
use Support\Database\Eloquent\Compatibility\Contracts\CompatibilityMap;
use Support\Database\Eloquent\Compatibility\Contracts\EventMirror;

final class WidgetEventMirror implements EventMirror
{
    public function compatibilityMap(): CompatibilityMap
    {
        return new WidgetCompatibilityMap;
    }

    public function excludedListeners(): array
    {
        return [];
    }
}
```

```php
use Illuminate\Support\Facades\DB;
use Support\Database\Eloquent\Compatibility\Bridge\EventBridge;
use Support\Database\Eloquent\Compatibility\Schema\Installer;

(new Installer(DB::connection()))->install(new WidgetCompatibilityMap);

resolve(EventBridge::class)->mirror(new WidgetEventMirror);
```

### Planner support
A mapped attribute read as its column's own type, such as a `boolean` column read as a `boolean`, keeps the column's statistics and indexes, because PostgreSQL drops that identity cast. A conversion does not: a `timestamp with time zone` column read as a `timestamp without time zone` is an expression with no statistics, and no index on the column serves it. A query that filters or sorts on it then plans from guesses. The map declares what the planner needs, and the package builds it from the same parts that build the view column:

```php
use Support\Database\Eloquent\Compatibility\Mapping\PlannerIndex;

PrimaryCurrentModel::for(Gadget::class)
    ->aliasedAs('gadget')
    ->indexedBy(
        PlannerIndex::named('newest_first')
            ->on('is_archived')
            ->on('created_at', descending: true)
            ->whereNull('deleted_at'),
    );

Attribute::mapped('created_at')
    ->asType('timestamp(0) without time zone')
    ->storedAs('gadget', 'created_at')
    ->inTimeZone('UTC');
```

- `Attribute::inTimeZone()` says the stored column is a `timestamp with time zone` and the attribute is a `timestamp without time zone`. The view reads the column `AT TIME ZONE` that zone, and a write converts back. A plain cast between the two depends on the session's time zone, which PostgreSQL cannot index. The conversion is immutable, so the expression can carry an index. Only that conversion needs a zone: an attribute of its column's own type, `timestamp with time zone` included, declares none. Only an attribute stored on the primary current model takes a zone, because the statistics object covers that table alone, so `ResolvedMap::of()` refuses one stored on the retained table or a dependent. The zone must be a name in `pg_timezone_names` or an abbreviation in `pg_timezone_abbrevs`, in any letter case, and the install refuses any other.
- The view's default for a zoned attribute is the stored column's default converted to the zone. An `Attribute::defaultsTo()` expression is taken as written, so it must already be in the zone, such as `now() AT TIME ZONE 'UTC'`.
- `PrimaryCurrentModel::indexedBy()` declares one or more `PlannerIndex` values. `PlannerIndex::on()` adds a column, with `descending: true` for a descending one. `PlannerIndex::whereNull()` or `PlannerIndex::whereNotNull()` makes the index partial. Each name is an attribute of the map that `Attribute::storedAs()` stores on the primary current model. The index records every call, so `ResolvedMap::of()` refuses a column named twice and a second filter rather than drop one.
- Install and recompile plan the planner statements from the catalog. They build each declared index and the statistics object over the zoned attributes when the catalog lacks it, and rebuild the statistics object when its sorted expressions changed or another table holds it. They then run `ANALYZE` on the primary current model's table, and last mark each object with a comment. An object without its marker, such as one a stopped prepare left, gets the `ANALYZE` and its marker on the next run. A run whose objects all carry their markers emits nothing. They refuse an invalid index, with a hint for an ordinary or a partitioned one. The statistics object holds each distinct expression once, and PostgreSQL allows at most 8, so `ResolvedMap::of()` refuses a map whose zoned attributes give more. A map that declares no index and no `Attribute::inTimeZone()` emits nothing.
- `Installer::prepare()` plans the same way and builds with `CREATE INDEX CONCURRENTLY`, outside a transaction, ahead of the install, and ahead of a recompile that adds or renames an index or changes a zoned attribute. The install or recompile then lists no planner statement and runs no `ANALYZE`. [Adopting a map](docs/adopting-a-map.md#before-the-install-prebuild-the-planner-objects) describes the migration.
- `Installer::uninstall()` drops the map's indexes and statistics with the rest of its objects.

[Compiling and installing the schema](docs/flows/compiling-and-installing-the-schema.md#planner-support) lists what each action emits, how the catalog decides it, and what that leaves alone.

`php artisan compatibility:check` names every record where the previous and current models disagree.

`php artisan compatibility:sql` prints the statements the Installer runs for a registered compatibility map, so a reviewer or a database administrator can read them before a migration runs. It runs only catalog reads.

```bash
php artisan compatibility:sql widgets                          # install, to stdout
php artisan compatibility:sql widgets --for=recompile          # or --for=uninstall, or --for=prepare
php artisan compatibility:sql widgets --retained-table-is-currently=widgets --output=widgets.sql
```

`--retained-table-is-currently` compiles the map before the migration renames the retained table: the command reads the table's columns and grants from its current name. Before an install the view does not exist yet, so the command derives its grants from the retained table's grants and the map's attribute columns. The header comment names the map, the database and schema it read, the PostgreSQL version and the time. An invalid map, a missing table, or a map the Installer refuses exits non-zero with the reason.

### Refused states
A refused state is a state one side can hold and the other cannot express, declared with `UnrepresentableState::refuse()`. Its `UnrepresentableState::checkedOn()` condition compiles to a trigger that refuses every insert or update reaching the state, whoever writes it. On a dependent current model's table or the retained table, that trigger serializes the writers of one record. So two concurrent writes to one record cannot both reach a state that compares the record's own rows.

The serialization has limits:

- **A state checked on the primary current model's table is not serialized.** Two concurrent writes can both reach it. Enforce a rule that compares rows of that table with a unique or exclusion constraint. `compatibility:check` still reports a row in the state.
- **A condition that compares rows of different records, or reads another table, can still race.**
- **Under REPEATABLE READ, the check after the wait misses the row the other writer committed.** The serialization holds under READ COMMITTED, PostgreSQL's default.
- **A writer that locks a dependent row and then updates it can deadlock a concurrent write through the view.** PostgreSQL raises 40P01 for one of the two. A write through the event bridge locks the primary row first and waits instead, unless the application locked the dependent row itself.

### State machines
A current model may cast a column to a state machine enum from `aryeo/eloquent-state-machines`. The package suggests that dependency and does not require it. A write through the previous model that changes such a column runs the state machine's trigger, as a native transition does:

- After the statement stores the new state, and before the current model's events, the event bridge runs the trigger of the one `#[Transition]` that leads from the stored state to the new one. It calls the trigger through the column's proxy with `Trigger::withoutTransition()`, so the trigger does not write the state column again.
- The trigger's checks, `allowed()`, the before event and `handle()` each run once, inside the write's transaction. The after event fires once, after the outermost commit.
- The trigger saves what `handle()` changed. The bridge mirrors that save as a native write, and its own events report the new state and the write's other changes. Each change is therefore reported once on each side.
- A listener that the map's event mirror excludes is skipped for every model event of the trigger's own instance while the trigger runs. It still runs for any other model that `handle()` saves.
- The previous model's instance holds what the trigger saved before its own `updated` and `saved` events. Those events report the write's own changes, except a column that `handle()` changed too.
- A column that both the write and `handle()` change is reported once on each side, from the value the write stored. The previous model's own `getChanges()` does not list that column.
- A previous model may cast its mapped column to the same state machine.
- The bridge refuses the whole write with `CompatibilityWriteRefused` and rolls it back in eleven cases: the stored or the new state is not a case of the enum, no `#[Transition]` leads from the stored state to the new one, more than one does, two transitions of the stored state use triggers whose classes share a basename, the trigger refuses the transition, the container cannot build the trigger, the trigger's constructor takes a model argument without a default that the map does not declare, a closure of `triggerArguments()` throws, a middleware of the trigger does not pass it on, another run holds the trigger's unique lock, or a `saving` or `updating` listener vetoes the trigger's save. The bridge tells a veto by the trigger's last save of the instance. A save or an `increment()` that a listener makes inside that save does not count, and neither does an earlier save from `handle()`, vetoed or completed. So a `saved` listener that changes the instance without saving it, or that returns false, keeps the write, as it does natively. A declining middleware throws the Interrupted exception of aryeo/actions, and a held unique lock throws its Prevented exception. Triggers that share a basename make the proxy throw the Duplicate exception of eloquent-state-machines. The refusal keeps each one as `previous`. A declared argument of a type the trigger's constructor rejects throws a `\TypeError` while the proxy builds the trigger, and the refusal keeps that as `previous` too. An `Invalid` or a `BindingResolutionException` thrown inside `handle()` also refuses the write, with the original exception as `previous`. Any other exception from the trigger rolls the write back and reaches the caller unchanged.
- Under `Illuminate\Support\Facades\Bus::fake()`, a bus fake that would record the trigger instead of running it makes the write throw `CompatibilityEventMirrorBypassed`, and the bridge rolls the write back. A bus fake that fakes only other jobs runs the trigger.

A write through the previous model differs from a native transition in these ways:

- **The database holds the new state while the trigger runs.** The model holds the previous state in its attribute until the trigger's phase, as on a native run. A read of the database inside `allowed()` or `handle()` sees the new state, and so does `failed()` after a rollback. An `allowed()` that compares such a read with the model's state therefore refuses every write through the previous model.
- **The trigger takes only the arguments the map declares.** The proxy builds it with `make()`, so an optional constructor argument that the map does not declare takes its default. A required argument that the map does not declare and the container cannot resolve refuses the write. So does a constructor argument typed as a model, nullable or not, that has no default and that the map does not declare. The refusal comes before the trigger runs, because the container would build that model without attributes.
- **Each side raises two sets of events.** The trigger's save reports `handle()`'s changes, and the bridge's events then report the new state. A native transition reports both in one set. The trigger always saves, so its `saving` and `saved` events fire even when `handle()` changes nothing. That save then writes nothing, and fires no `updating` or `updated` event.
- **`updated_at` moves only with a write.** The bridge writes nothing itself. When the previous model does not write `updated_at` and `handle()` changes nothing, the current model's `updated_at` stays as it was.
- **`failed()` cannot keep its writes.** A throw from the trigger rolls back the whole write, and the writes of `failed()` with it.

A repair can set a state that no `#[Transition]` reaches. Two routes skip the trigger:

- Write the current model directly. The bridge mirrors that native write to the previous model and runs no trigger.
- Write the previous model inside `resolve(EventBridge::class)->withoutMirroring(fn () => $article->update([...]))`. The bridge runs no trigger and raises no events on the current model.

To pass a trigger its constructor arguments, declare them on the current model that casts the state column. Give one closure per argument name:

```php
PrimaryCurrentModel::for(Delivery::class)
    ->aliasedAs('delivery')
    ->triggerArguments(Deliver::class, [
        'deliveredAt' => fn (Delivery $delivery, Order $order) => $order->delivered_at,
    ]);
```

- Call `triggerArguments()` once for each trigger. A second call for the same trigger replaces the first.
- Each closure receives the current model and the caller's previous-model instance. The bridge passes the result to the proxy's trigger method as the named argument.
- The current model holds the row the write stored, but its state column still holds the stored state, as `handle()` sees it. The database already holds the new state.
- When one write moves two state columns, a closure of the second column's trigger sees the first column at its new state. The first trigger has run by then.
- The bridge runs the closures after it finds the transition and before it calls the trigger. A closure that throws refuses the write, with the original exception as `previous`. A deadlock or a serialization failure that the bound `Illuminate\Contracts\Database\ConcurrencyErrorDetector` recognises reaches the caller unchanged instead, so `Connection::transaction()` with more than one attempt retries the write.
- A trigger that has no declared arguments is called without arguments.

Registration refuses the map with `InvalidCompatibilityMap`, and registers nothing, in five cases:

- The trigger is not the `using` of a `#[Transition]` on a state machine that one of the model's columns casts to. The class name must match exactly.
- An argument name is not a parameter of the trigger's constructor. The check ignores case, and refuses two names that differ only in case. It also refuses a constructor with two parameter names that differ only in case, because `Trigger::make()` cannot tell them apart.
- An argument names a variadic parameter. The container passes the closure's result as one element of it, so declare an array parameter instead.
- The closures leave out a required parameter that the container cannot resolve: one that has no default, is not nullable, has no class or interface type, and carries no contextual attribute such as `#[Config]`. An enum type counts as no class type, nullable or not, because the container cannot build an enum. A model type without a default counts as unresolvable too, nullable or not, because the container builds the model without attributes. Registration cannot see a contextual binding such as `when()->needs('$reason')->give()`, because a provider may register it later. Pass such a parameter with a closure.
- eloquent-state-machines is missing, or it has no `Trigger::withoutTransition()`.

A map whose current models cast no column to a state machine behaves as it does without the package. So does every map that declares no `triggerArguments()`, with a release of eloquent-state-machines that has no `Trigger::withoutTransition()`. The trigger runs from the first installed release that has `Trigger::withoutTransition()`, so the upgrade to that release changes what a write through the previous model does.

### Failed statements
Outside a caller's transaction, the event bridge opens its own transaction for a mirrored write. When the database refuses that write, the bridge rolls its transaction back as soon as the statement fails. For that, the bridge requires `Connections\DispatchesQueryFailed` on every connection it mirrors a write on. It refuses every mirrored write on a connection whose class does not use the trait, with a `CompatibilityConnectionUnsupported` that names the connection, its class and the trait. `CompatibilityWriteRefused::isRefusal()` rejects it, because it refuses no rule of the map.

Add the trait to the connection class the application already resolves. When the application resolves none, register a resolver as below, in a service provider's `register()`:

```php
use Illuminate\Database\Connection;
use Illuminate\Database\PostgresConnection;
use Support\Database\Eloquent\Compatibility\Connections\DispatchesQueryFailed;

final class AppPostgresConnection extends PostgresConnection
{
    use DispatchesQueryFailed;
}

Connection::resolverFor('pgsql', fn ($pdo, $database, $prefix, $config) => new AppPostgresConnection($pdo, $database, $prefix, $config));
```

The trait raises `Connections\Events\QueryFailed` once for each statement that fails with a `QueryException`, inside a transaction or outside one:

- The event fires after Laravel's lost-connection retry, and never for a statement that a retry recovers.
- A statement that fails inside a `QueryFailed` listener, on the same connection, raises no second event.
- `listenForFailures()` registers a listener, as `listen()` does for `QueryExecuted`.
- The event carries the SQL, the bindings, the connection and its name, the exception, and the read/write type. Its `time` is null.
- A listener's throwable does not replace the statement's `QueryException`. The trait reports it, and throws the `QueryException`. A throw from that report does not replace it either.
- The trait overrides `handleQueryException()`, `beforeExecuting()` and `runQueryCallback()`. It therefore composes with a connection that overrides `run()` or `tryAgainIfCausedByLostConnection()`. The event bridge refuses a connection class that declares any of those methods itself, or the trait's `registerEventBridge()`.
- Its `beforeExecuting()` keeps the event bridge's two callbacks first and last. So every other `beforeExecuting` callback runs before a mirrored write opens, and its statements never take the write's place.
- Before it raises `QueryFailed`, the trait calls the event bridge, which rolls back a mirrored write whose own statement failed. No listener can stop that.

### Concurrency revision handling

The compiler owns the `concurrency_revision` column of every table a map writes. A compiled mirror `INSERT` into such a table sets that column to 1, whatever a mapped attribute, a retained attribute or a fill gives it. A compiled mirror `UPDATE` drops any assignment to the column. It raises the revision by 1 only when the statement changes a stored value. That includes the `UPDATE` that sets a designation column.

A change to `updated_at` or to a `*_concurrency_revision` column alone raises nothing. A change to `deleted_at` raises the revision. A value that a mapping stores as the same number, such as a rounded price, raises nothing. A map may still expose the column through the view, and a write through the view cannot set it. So the mirrored write passes a refusing trigger that enforces application-raised revisions, as `refuse_unraised_concurrency_revision()` from eloquent-concurrency does.

The compiler reads the catalog when it compiles a map. Recompile the map with `Installer::recompile()` after a mapped table gains or loses `concurrency_revision`, and after you upgrade to this release. Until then, every mirrored write into a table that gained the column fails its refusing trigger.

Mirroring into an ordered item table's watched columns is refused at commit by eloquent-ordering's deferred check, because the compatibility map does not raise the order revision.

## Documentation
The full documentation is on the PHP Archetype standards site, on the eloquent-compatibility package page.

Maintainers and reviewers: start at [docs/architecture.md](docs/architecture.md).

Adopting a map in an application: read [docs/adopting-a-map.md](docs/adopting-a-map.md) first. It covers the connection class, the views to redirect, the tests to change, and the lost-connection and queued-job trade-offs.
