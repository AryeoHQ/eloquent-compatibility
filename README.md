# eloquent-compatibility
Serve a previous Eloquent model as a read-only PostgreSQL view over its current models' tables, and split every write to it through the current Eloquent models.

## Installation
```bash
composer require aryeo/eloquent-compatibility
```

The package supports PostgreSQL 14, 16 and 18, on Laravel 12 and 13.

The package installs no database trigger. Reads go through the compatibility view; every write of a mapped model's Eloquent builder is split in PHP, through the real current models. A write that bypasses Eloquent is not split: through the view PostgreSQL refuses it, and on a current table it skips the previous model. [Adopting a map](docs/adopting-a-map.md#audit-every-write-that-bypasses-eloquent) lists what to audit first.

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
            Attribute::mapped('price')->asType('character varying(255)')
                ->readAs('gadget', "to_char(gadget.price_cents / 100.0, 'FM999999990.00')")
                ->writesTo('gadget', 'price_cents', fn (array $widget) => $widget['price'] === null ? null : (int) round((float) $widget['price'] * 100)),
            Attribute::mapped('street')->asType('character varying(255)')->storedAs('place', 'street'),
        ];
    }

    public function unrepresentableStates(): array
    {
        return [];
    }
}
```

Reads are SQL, because the compatibility view runs them. Writes are PHP closures over the previous model's raw attributes, because the write router runs them: `Attribute::writesTo()`, the fills, `DependentCurrentModel::createdWhen()` and `UnrepresentableState::checkedOnPreviousUpdate()`.

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

The trait gives the model an Eloquent builder that hands every write to the write router, and marks the model's own writes, which fire model events, apart from query builder writes, which fire none. A model with a custom builder names it through Laravel's `Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder` attribute, and the builder uses `Write\RoutesCompatibleWrites`. `WriteRouter::register()` refuses a map whose model lacks the interface or the trait, or declares its own `setKeysForSaveQuery()`, `getAttributesForInsert()` or `newEloquentBuilder()`. [Adopting a map](docs/adopting-a-map.md#mark-each-mapped-model) describes the models that cannot carry the mark.

### PHPStan rules
The package registers four PHPStan rules in `tooling/phpstan/rules.neon`. An application that runs `tooling:phpstan:analyze` loads them, so it finds a model that breaks the contract before `WriteRouter::register()` refuses it at boot:

- `CompatibleMustUseHasCompatibility`: a model that implements `Compatible` uses `HasCompatibility`.
- `HasCompatibilityMustImplementCompatible`: a model that uses `HasCompatibility` implements `Compatible`.
- `HasCompatibilityMustOnlyBeOnModel`: only a `Model` subclass implements `Compatible` or uses `HasCompatibility`.
- `HasCompatibilityMustNotRedeclareWriteHooks`: a class that uses `HasCompatibility`, or extends one that does, resolves `setKeysForSaveQuery()`, `getAttributesForInsert()` and `newEloquentBuilder()` to the trait's own methods. A declaration in the class or a parent fails, and so does a method another trait brings in.

The rules skip anonymous classes, which `WriteRouter::register()` still checks at boot.

### Install and register
A migration installs the compatibility view. A service provider registers the map with the write router, through an event mirror that names the map and the listeners to exclude from mirrored events:

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
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Support\Database\Eloquent\Compatibility\Write\WriteRouter;

(new Installer(DB::connection()))->install(new WidgetCompatibilityMap);

resolve(WriteRouter::class)->register(new WidgetEventMirror);
```

A map an earlier version installed with triggers moves over with `Installer::recompile()`, which drops them.

### How writes work
- **A write through the previous model**, such as `$widget->update(['title' => 'Renamed'])`, locks the record, reads it through the view, and saves each current model whose columns changed. On a model's own write the current models fire their own events, so their listeners may change a value or veto the whole write. A query builder write saves them quietly, as Eloquent's mass writes fire no events.
- **A write of a current model**, such as `$gadget->update(['name' => 'Renamed'])` or `Gadget::query()->create([...])`, creates or deletes the retained row, fills designations, checks refused states, and on a model's own write replays the previous model's events. A mirrored listener can refuse the write, but not change it.
- **Every routed write runs in one transaction**, or a savepoint inside the caller's, so a refusal rolls it all back with a `CompatibilityWriteRefused`.

`php artisan compatibility:check` names every record where the previous and current models disagree.

### Refused states
A refused state is a state one side can hold and the other cannot express, declared with `UnrepresentableState::refuse()`. After every insert or update through a model of the table its `UnrepresentableState::checkedOn()` condition names, the write router checks the written rows and refuses one in the state. On a dependent current model's table or the retained table, the router serializes the writers of one record first. So two concurrent writes to one record cannot both reach a state that compares the record's own rows. Raw SQL on the table skips the check, and `compatibility:check` reports the rows it leaves in the state.

The serialization has limits:

- **A state checked on the primary current model's table is not serialized.** Two concurrent writes can both reach it. Enforce a rule that compares rows of that table with a unique or exclusion constraint. `compatibility:check` still reports a row in the state.
- **A condition that compares rows of different records, or reads another table, can still race.**
- **Under REPEATABLE READ, the check after the wait misses the row the other writer committed.** The serialization holds under READ COMMITTED, PostgreSQL's default.
- **A writer that locks a dependent row itself and then writes through the router can deadlock a concurrent routed write.** PostgreSQL raises 40P01 for one of the two. The router locks the primary row before the dependent row, unless the application locked the dependent row first.

## Documentation
The full documentation is on the PHP Archetype standards site, on the eloquent-compatibility package page.

Maintainers and reviewers: start at [docs/architecture.md](docs/architecture.md).

Adopting a map in an application: read [docs/adopting-a-map.md](docs/adopting-a-map.md) first. It covers the writes to audit, the views to redirect, the tests to change, and the lost-connection and queued-job trade-offs.
