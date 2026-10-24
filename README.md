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

## Documentation
The full documentation is on the PHP Archetype standards site, on the eloquent-compatibility package page.
