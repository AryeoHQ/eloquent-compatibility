# eloquent-compatibility
Serve a previous Eloquent model as a PostgreSQL view over its current models' tables

## Installation
```bash
composer require aryeo/eloquent-compatibility
```

The package supports PostgreSQL 14, 16 and 18, on Laravel 12 and 13.

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

A migration installs the compatibility view and its triggers. A service provider registers an event mirror, a class that implements `Contracts\EventMirror` and names the map, so each side's model events fire for writes through the other:

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

`php artisan compatibility:check` names every record where the previous and current models disagree.

### Refused states
`UnrepresentableState::refuse()` declares a state one side can hold and the other cannot express. Its `UnrepresentableState::checkedOn()` condition compiles to a trigger that refuses every insert or update reaching the state, whoever writes it. On a dependent current model's table or the retained table, that trigger serializes the writers of one record. So two concurrent writes to one record cannot both reach a state that compares the record's own rows.

The serialization has limits:

- **A state checked on the primary current model's table is not serialized.** Two concurrent writes can both reach it. Enforce a rule that compares rows of that table with a unique or exclusion constraint. `compatibility:check` still reports a row in the state.
- **A condition that compares rows of different records, or reads another table, can still race.**
- **Under REPEATABLE READ, the check after the wait misses the row the other writer committed.** The serialization holds under READ COMMITTED, PostgreSQL's default.
- **A writer that locks a dependent row and then updates it can deadlock a concurrent write through the view.** PostgreSQL raises 40P01 for one of the two, as it does for the same pattern through the event bridge.

## Documentation
The full documentation is on the PHP Archetype standards site, on the eloquent-compatibility package page.

Maintainers and reviewers: start at [docs/architecture.md](docs/architecture.md).
