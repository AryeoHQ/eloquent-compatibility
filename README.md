# eloquent-compatibility
Serve a previous Eloquent model as a read-only PostgreSQL view over its current models' tables, and split every write to it through the current Eloquent models.

## Installation
```bash
composer require aryeo/eloquent-compatibility
```

The package supports PostgreSQL 14, 16 and 18, on Laravel 12 and 13.

The package installs no database trigger. Reads go through the compatibility view; every write of a mapped model's Eloquent builder is split in PHP, through the real current models. A write that bypasses Eloquent is not split: through the view PostgreSQL refuses it, and on a current table it skips the previous model. [Adopting a map](docs/adopting-a-map.md#audit-every-write-that-bypasses-eloquent) lists what to audit first.

## Usage
An application replaced one model, the previous model, with several current models. Here a `listings` table was split into `jobs`, `places` and `job_sites`, and renamed to `listings_retained`, which keeps what moved nowhere. Legacy code keeps using `Listing`.

### Declare the map on the previous model
The previous model implements `Contracts\DefinesCompatibility` and returns a `Compatibility` builder. Each attribute is a path from the retained model, written the way Eloquent's `with()` writes one:

```php
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Support\Database\Eloquent\Compatibility\Compatibility;
use Support\Database\Eloquent\Compatibility\Contracts\DefinesCompatibility;
use Support\Database\Eloquent\Compatibility\Provides\HasCompatibility;

#[UseEloquentBuilder(ListingBuilder::class)]
final class Listing extends Model implements DefinesCompatibility
{
    use HasCompatibility, SoftDeletes;

    protected function casts(): array
    {
        return ['price' => CentsAsDollars::class, 'is_published' => PublishedFlag::class];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public static function compatibility(Compatibility $map): Compatibility
    {
        return $map
            ->identifiedBy(ListingRetained::class)               // a model over the renamed table
            ->attributes([
                'mls_number'   => 'mls_number',                   // stays on the retained model
                'status'       => 'status',
                'title'        => 'job.name',                     // ListingRetained::job(): BelongsTo
                'price'        => 'job.price_cents',              // cents; CentsAsDollars shows dollars
                'is_published' => 'job.published_at',
                'street'       => 'job.rootPlace.street',         // Job::rootPlace(): HasOne
                'city'         => 'job.rootPlace.city',
                'latitude'     => 'job.site.latitude',
            ])
            ->timestampsOn('job')
            ->readOnly('uuid', because: 'Partners hold the listing uuid.')
            ->creating('job', fn (Job $job, Listing $listing) => $job->source = 'legacy-listing')
            ->createWhen('job.site', fn (Listing $listing) => $listing->latitude !== null)
            ->designatedBy('job.rootPlace', 'place_id')
            ->oneForEach('job', fn (ListingRetained $listing, Job $job) => $listing->status = $job->archived ? 'Archived' : 'Draft')
            ->hide(fn (Builder $retained) => $retained->whereNull('job_id')->where('status', 'Retired'))
            ->guard('sold_price_frozen',
                fn (Listing $after, Listing $before) => $before->status === 'Sold' && $after->price !== $before->price,
                because: 'A sold listing keeps the price it sold for.', instead: 'Change the price before the sale.')
            ->refuse('second_root_place', on: Place::class,
                when: fn (Place $place) => $place->parent_id === null
                    && $place->job->places()->whereNull('parent_id')->whereKeyNot($place->getKey())->exists(),
                because: 'A listing has one street.', instead: 'Give the new place a parent_id.',
                candidates: fn (Builder $places) => $places->whereNull('parent_id'))
            ->excludeFromMirroredEvents(SyncListingToMls::class, because: 'It follows only edits made through the Listing.');
    }
}
```

The relations carry the rest. `Job::rootPlace()` decides which place a listing reads, through its own constraints and order, and fills a created place through `withAttributes()`:

```php
public function rootPlace(): HasOne
{
    return $this->hasOne(Place::class)->withAttributes(['parent_id' => null])->oldest('id');
}
```

| Declaration | What it does |
| :-- | :-- |
| `identifiedBy()` | names the model over the retained table; its key is the previous model's key |
| `attributes()` | maps each attribute to `column`, `relation.column` or `relation.relation.column`. The first hop is a `BelongsTo` to the primary current model, the second a `HasOne` of it. Column types come from the catalog. The key and the primary relation's foreign key are added, read-only |
| `timestampsOn()` | stores created-at, updated-at and deleted-at in the primary current model's own columns |
| `readOnly()` | refuses a change to an attribute, with the reason |
| `creating()` | sets attributes on a row a write through the previous model creates, with both models in hand |
| `createWhen()` | decides whether a write creates a missing dependent row; by default, when it stores a value in it |
| `designatedBy()` | pins the dependent row the previous model reads with a retained column |
| `oneForEach()` | fills the retained row created for each primary current row created through its own model |
| `hide()` | hides the retained rows an Eloquent scope selects, from the view and the drift check |
| `guard()` | refuses an update through the previous model when a closure over the models after and before says so |
| `refuse()` | refuses every write through a model that leaves a row in a state a closure describes |
| `excludeFromMirroredEvents()` | keeps a listener from running for the previous model's replayed events |
| `degrade()`, `named()`, `servedAs()` | document a lossy state, name the map, and name the view |

A relation that cannot serve one row refuses at registration: a `HasMany`, an "of many" relation, which joins, and a related model with a global scope other than `SoftDeletes`, which the view would freeze at install time. Registration reads no database.

### Install and register
A migration installs the compatibility view, and a service provider registers the map with the write router:

```php
use Illuminate\Support\Facades\DB;
use Support\Database\Eloquent\Compatibility\Schema\Installer;
use Support\Database\Eloquent\Compatibility\Write\WriteRouter;

(new Installer(DB::connection()))->install(Listing::class);   // in a migration

resolve(WriteRouter::class)->register(Listing::class);         // in a service provider's boot()
```

A change to a mapped relation, scope or attribute changes the view the map compiles to. `Installer::recompile()` installs it, and `php artisan compatibility:check` reports an installed view that no longer matches. A map an earlier version installed with triggers moves over with `Installer::recompile()`, which drops them.

### Filters on converted attributes
The view stores what the current models store, so `price` holds cents. The previous model's builder translates queries, so legacy code keeps filtering in dollars:

```php
Listing::where('price', '>', 400000)->get();       // where "price" > 40000000
Listing::whereBetween('price', ['400000', '600000'])->orderByDesc('price')->get();
Listing::sum('price');                              // summed in cents, returned as dollars
$listing->increment('price', 1000);                 // +100000 cents
```

A cast that implements `Contracts\QueryableCast` gets that for free. `QueryableCast::preservesOrder()` allows ranges, `orderBy()`, `min()` and `max()`; `QueryableCast::isProportional()` allows `sum()`, `avg()` and increments.

A value no cast can translate, such as a boolean read from a timestamp, gets a translation in the previous model's builder, written with Eloquent calls:

```php
use Support\Database\Eloquent\Compatibility\Write\CompatibleBuilder;

final class ListingBuilder extends CompatibleBuilder
{
    protected function filterTranslations(): array
    {
        return [
            'is_published' => fn (self $query, string $operator, mixed $value) => $value
                ? $query->whereRelation('job', 'published_at', '!=', null)
                : $query->whereRelation('job', 'published_at', '=', null),
        ];
    }

    public function published(): self
    {
        return $this->where('is_published', true);
    }
}
```

A query neither can translate refuses with `CompatibilityQueryRefused`. Raw SQL bypasses the builder and compares the stored value without an error, so `whereRaw('price > 400000')` returns every listing: audit it as [adopting a map](docs/adopting-a-map.md#raw-sql-reads-compare-stored-values) describes.

### How writes work
- **A write through the previous model**, such as `$listing->update(['title' => 'Renamed'])`, locks the record, reads it through the view, and saves each current model whose columns changed. On a model's own write the current models fire their own events, so their listeners may change a value or veto the whole write. A query builder write saves them quietly, as Eloquent's mass writes fire no events.
- **A write of a current model**, such as `$job->update(['name' => 'Renamed'])` or `Job::query()->create([...])`, creates or deletes the retained row, fills designations, checks refused states, and on a model's own write replays the previous model's events. A mirrored listener can refuse the write, but not change it.
- **Every routed write runs in one transaction**, or a savepoint inside the caller's, so a refusal rolls it all back with a `CompatibilityWriteRefused`.

### Refused states
A refused state is a state one side can hold and the other cannot express. After every insert or update through a model of the table it is checked on, the write router checks the written rows and refuses one in the state. On a dependent current model's table or the retained table, the router serializes the writers of one record first. So two concurrent writes to one record cannot both reach a state that compares the record's own rows. Raw SQL on the table skips the check, and `compatibility:check` reports the rows it leaves in the state.

The serialization has limits:

- **A state checked on the primary current model's table is not serialized.** Two concurrent writes can both reach it. Enforce a rule that compares rows of that table with a unique or exclusion constraint. `compatibility:check` still reports a row in the state.
- **A condition that compares rows of different records, or reads another table, can still race.**
- **Under REPEATABLE READ, the check after the wait misses the row the other writer committed.** The serialization holds under READ COMMITTED, PostgreSQL's default.
- **A writer that locks a dependent row itself and then writes through the router can deadlock a concurrent routed write.** PostgreSQL raises 40P01 for one of the two. The router locks the primary row before the dependent row, unless the application locked the dependent row first.

### The model contract
Every model the map names, the previous model and each current model, implements `Contracts\Compatible` and uses `Provides\HasCompatibility`. `Contracts\DefinesCompatibility` extends `Compatible`. The retained model needs neither.

The trait gives the model an Eloquent builder that hands every write to the write router, and marks the model's own writes, which fire model events, apart from query builder writes, which fire none. A model with a custom builder names it through Laravel's `Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder` attribute, and the builder extends `Write\CompatibleBuilder` or uses `Write\RoutesCompatibleWrites`. `WriteRouter::register()` refuses a map whose model lacks the interface or the trait, or declares its own `setKeysForSaveQuery()`, `getAttributesForInsert()` or `newEloquentBuilder()`. [Adopting a map](docs/adopting-a-map.md#mark-each-mapped-model) describes the models that cannot carry the mark.

The package registers four PHPStan rules in `tooling/phpstan/rules.neon`. An application that runs `tooling:phpstan:analyze` loads them, so it finds a model that breaks the contract before `WriteRouter::register()` refuses it at boot:

- `CompatibleMustUseHasCompatibility`: a model that implements `Compatible` uses `HasCompatibility`.
- `HasCompatibilityMustImplementCompatible`: a model that uses `HasCompatibility` implements `Compatible`.
- `HasCompatibilityMustOnlyBeOnModel`: only a `Model` subclass implements `Compatible` or uses `HasCompatibility`.
- `HasCompatibilityMustNotRedeclareWriteHooks`: a class that uses `HasCompatibility`, or extends one that does, resolves `setKeysForSaveQuery()`, `getAttributesForInsert()` and `newEloquentBuilder()` to the trait's own methods. A declaration in the class or a parent fails, and so does a method another trait brings in.

The rules skip anonymous classes, which `WriteRouter::register()` still checks at boot.

### Hand-written maps
`Compatibility` compiles into a `Contracts\CompatibilityMap`, the lower-level form every part of the package reads. A map that needs what relations cannot express, such as a value the view computes in SQL with `Attribute::readAs()`, implements `CompatibilityMap` directly with the builders of the Mapping namespace, and registers through an `Contracts\EventMirror`. The workbench [WidgetCompatibilityMap](tests/Fixtures/Support/Workbench/WidgetCompatibilityMap.php) is one; [declaring and registering a map](docs/flows/declaring-and-registering-a-map.md) describes both forms.

## Documentation
The full documentation is on the PHP Archetype standards site, on the eloquent-compatibility package page.

Maintainers and reviewers: start at [docs/architecture.md](docs/architecture.md).

Adopting a map in an application: read [docs/adopting-a-map.md](docs/adopting-a-map.md) first. It covers the writes and raw reads to audit, the views to redirect, the tests to change, and the lost-connection and queued-job trade-offs.
