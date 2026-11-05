Status: Proposed

# The map

A legacy model declares one map. The map says how the view reads each attribute, which successors serve a record, and which application classes the package calls. The package owns the mechanism. The consumer owns policy only through the hooks the map declares ([0016](../decisions/0016-the-package-owns-mechanism-consumers-own-policy.md)).

## Declaring a map

A legacy model implements `Contracts\Compatible` and uses `Provides\ServesCompatibility`. Its static `compatibility()` method receives an empty `Compatibility` and returns the declared map.

```php
final class Listing extends Model implements Compatible
{
    use ServesCompatibility;

    public static function compatibility(Compatibility $listing): Compatibility
    {
        return ListingMap::define($listing);
    }
}
```

The worked examples keep the map in its own class, `ListingMap`, beside the operation classes. [The Listing example](examples/listing.md) and [the floor plan example](examples/floor-plan.md) show complete maps.

## The root and its successor key

`servedFrom()` names the root: the successor a legacy record is served from. Its `key:` names the successor key, the legacy table's column that holds the root's primary key.

```php
->servedFrom(Job::class, key: 'job_id', successor: fn (Successor $job) => $job
    ->attribute('company_id', fn (Attribute $a) => $a->from('company_id')->readOnly(because: 'A Job never changes company.')))
```

Listing adopts its existing `job_id` column as the successor key. FloorPlan names `place_asset_id`, a column that `prepare()` adds and the backfill fills.

Only the root has a successor key. A related successor is reached through the root's relations. A stored key for a related successor goes stale when an application moves the root, as v2 moves a Job to another Place. Legacy columns such as `listings.place_id` are therefore ordinary mapped attributes, read from the related row.

`retainedIn()` names the table that the legacy table becomes at install. [Storage](storage.md) states its columns and constraints.

## Reads are projections

Each attribute declares how the view reads it, and nothing else about its storage. An attribute reads one successor column with `from()`, optionally through the package's conversions. It reads an SQL expression with `readAs()`.

```php
->attribute('created_at', fn (Attribute $a) => $a->from('created_at')->inTimeZone('UTC'))
->attribute('downloads_enabled', fn (Attribute $a) => $a
    ->readAs('COALESCE("jobs"."are_customer_downloads_enabled", "listing_compatibility_states"."downloads_enabled_fallback")')
    ->writtenBy(SetDownloadsPermission::class))
```

The conversions stay the package's own. An application cannot add one, because the `Conversion` interface stays closed. A conversion's inverse becomes the attribute's default operation. [Operations](operations.md) states how every write runs.

## Related successors

`related()` takes a closure that returns an Eloquent relation of the successor it sits under. The relation's constraints live inside the closure, such as `->withTrashed()`. The package reads the related model class from the relation. An optional `successor:` closure declares the related successor's own attributes and relations.

```php
->related(fn (Job $job) => $job->listingCompatibilityState())
->related(fn (Job $job) => $job->place(), successor: fn (Successor $place) => $place
    ->attribute('place_id', fn (Attribute $a) => $a->from('id'))
    ->related(fn (Place $place) => $place->address()->withTrashed()))
```

A related successor without a `successor:` closure is a companion. The map reads it in `readAs()` expressions, and an operation creates it with `relatedOrNew()`.

An operation references a related successor by its model class, such as `$successors->relatedOrNew(Place::class)`. Attribute names and column names stay strings. When the map compiles, it refuses two related successors of one class under one root. A PHPStan rule reports an operation that references a class the map does not relate ([0021](../decisions/0021-related-successors-are-referenced-by-class.md)).

### Morph targets

`relatedMorph()` declares a `MorphTo` relation and its targets. Its `choose:` closure picks the target class for a legacy record. Its `targets:` closure declares each target over `Definition\Targets`.

```php
->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(),
    choose: fn (FloorPlan $plan): string => $plan->url !== null ? Link::class : File::class,
    targets: fn (Targets $targets) => $targets
        ->as(File::class, fn (Successor $file) => $file)
        ->as(Link::class, fn (Successor $link) => $link))
```

### Membership

`when()` on the root scopes the successor rows the legacy model serves. FloorPlan serves only the `PlaceAsset` rows whose `type` is `floorplan`. A native successor create inserts a legacy row only for a successor inside that scope.

## Hooks

| Method | Interface | The package calls it |
| :-- | :-- | :-- |
| `writtenBy()`, on an attribute | `Contracts\WritesAttribute` | when a write changes that attribute, including a create |
| `successorsInitializedBy()` | `Contracts\InitializesSuccessors` | when the package creates the root, after the attribute operations |
| `legacyInitializedBy()` | `Contracts\InitializesLegacy` | when an application creates a member successor natively |
| `writesWithin()` | `Contracts\ScopesWrites` | before every lock the package takes, on every path |
| `dependents()` | `Contracts\ManagesDependents` | inside the install and rollback transactions |
| `catchUpAlso()` | `Contracts\ReportsChanges` | on every backfill pass that has a watermark |

Every hook is optional. Without `writtenBy()`, an attribute runs its default operation. Without `dependents()`, install refuses when an object depends on the legacy table.

## Classes only

`writtenBy()`, `successorsInitializedBy()`, `legacyInitializedBy()`, `writesWithin()`, `dependents()` and `catchUpAlso()` take a class-string of their interface. A closure is a type error ([0022](../decisions/0022-operations-are-classes.md)).

The classes live under the model's `Compatibility` namespace. The worked examples place `Listing`'s classes, for the model `App\Listings\Models\Listing`, under `App\Listings\Compatibility`. [Open question 2](#2-the-compatibility-namespace) covers the exact rule.

## Hide, guard and forwarding

These three declarations keep today's behavior.

- `hide()` takes a scope over the legacy records the legacy model never shows, and the reason.
- `guard()` refuses a legacy write when its condition returns true for the record after and before the write. It names the reason, and what to do instead.
- `withoutForwardingTo()` names a legacy listener that forwarded successor events never reach, and the reason.

## Glossary

| Term | Meaning |
| :-- | :-- |
| legacy model | the previous Eloquent model, which declares the map |
| successor | a current model whose rows serve a legacy record |
| root | the successor a legacy record is served from, returned by `Successors::root()`; it replaces "primary" |
| related successor | a successor reached through the root's relations, referenced by its model class |
| companion | a related successor with no mapped attributes, such as a compatibility state row |
| target | one class a `relatedMorph()` can point to; `targets:` declares them, and `choose:` picks one |
| successor key | the legacy table's column that holds the root's key, declared with `servedFrom(key:)`; it replaces "retained key" |
| retained table | the legacy table after install, under the name `retainedIn()` gives it |
| membership | the successor rows a legacy model serves, scoped by `when()` |
| operation | a class that turns a write of one attribute into successor changes |
| `Successors` | the unit of work an operation receives, generic over the root class |
| the round trip | after a write, every written attribute reads back its new value |
| the round-trip check | the package's check of the round trip, which refuses the write and rolls it back when it fails |

## Open questions

### 1. Declaration closures under the classes-only rule

The session settled classes only for six hooks. It did not settle the other closures a map holds. They are `when()`, `choose:`, the scope of `hide()`, the condition of `guard()`, the value of `isNotNull()`, and the read of `external()`.

- **A.** They stay closures.
- **B.** Each becomes a class with its own interface.

**Recommendation: A.** Each one selects rows, picks a class or computes a value, and none of them writes. The six hooks are the places where application behavior runs inside the package's transaction.

### 2. The `Compatibility` namespace

Decision 6 says `<model namespace>\Compatibility`. Read literally, `App\Listings\Models\Listing` puts its classes under `App\Listings\Models\Compatibility`. The worked examples use `App\Listings\Compatibility`.

- **A.** The literal rule: the model's namespace, then `\Compatibility`.
- **B.** The examples' rule: replace a final `Models` segment with `Compatibility`, and append `\Compatibility` otherwise.
- **C.** Either rule, as a convention the documentation states and no tool checks.

**Recommendation: B, as a convention.** The reviewed examples use it, and the class-string types already keep closures out.

### 3. Hooks declared more than once

The session did not say whether a map may declare a hook twice.

- **A.** `catchUpAlso()` may repeat, because one legacy model can derive values from several other tables. Every other hook is declared at most once.
- **B.** Every hook is declared at most once, and one change source reports every table.

**Recommendation: A.** A second change source then needs no edit to the first.
