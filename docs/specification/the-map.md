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
->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
    ->attribute(name: 'company_id', definition: fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A Job never changes company.')))
```

Listing adopts its existing `job_id` column as the successor key. FloorPlan names `place_asset_id`, a column that `prepare()` adds and the backfill fills.

Only the root has a successor key. A related successor is reached through the root's relations. A stored key for a related successor goes stale when an application moves the root, as v2 moves a Job to another Place. Legacy columns such as `listings.place_id` are therefore ordinary mapped attributes, read from the related row.

`retainedIn()` names the table that the legacy table becomes at install. [Storage](storage.md) states its columns and constraints.

## Reads are projections

Each attribute declares how the view reads it, and nothing else about its storage. An attribute reads one successor column with `from()`, optionally through the package's conversions. Two helpers cover the common projections:

- `orFrom(successor:, column:)` reads the attribute's column, or that related successor's column when the first is NULL. The class must be a successor the map relates, and the map refuses any other class when it compiles.
- `position()` reads the row's one-based position through eloquent-ordering's published position expression for the successor. Positions number within the successor's eloquent-ordering parent, not within a legacy parent. Its default operation moves the row with eloquent-ordering's `Placement::at()`, so the attribute needs no `writtenBy()`. The package compiles the read, so a filter on the attribute still runs as a rebuilt expression rather than on the view. A legacy `orderBy('index')` compiles to the successor's `rank_key` order. A `where('index', N)` across parents compiles without a count per candidate row. [Operations: positions](operations.md#positions) states the conversion.

```php
->attribute(name: 'created_at', definition: fn (Attribute $attribute) => $attribute->from('created_at')->inTimeZone('UTC'))
->attribute(name: 'downloads_enabled', definition: fn (Attribute $attribute) => $attribute
    ->from(column: 'are_customer_downloads_enabled')
    ->orFrom(successor: ListingCompatibilityState::class, column: 'downloads_enabled_fallback')
    ->writtenBy(SetDownloadsPermission::class))
```

`readAs()` stays public as the escape hatch, for any projection the helpers do not cover. A `readAs()` expression names the view's join aliases, which are internal and may change. A helper names the successor by class instead.

`readAs()` is computed by SQL inside the view, so it can be filtered and sorted, and `computedAs()` is computed in PHP after the query, so it cannot.

The conversions stay the package's own. An application cannot add one, because the `Conversion` interface stays closed. A conversion's inverse becomes the attribute's default operation. [Operations](operations.md) states how every write runs.

## Legacy-only columns

A legacy-only column is a column of the retained table that no attribute maps, other than the legacy identity and the successor key ([Storage: the retained table](storage.md#the-retained-table)). The view reads it from the retained table.

Project a value where a successor can supply it. A value derived from a successor is projected, not stored. A projected value cannot go stale, because every read resolves it from the successor.

- **A successor column.** The map reads it with `from()` or `orFrom()`. A legacy media URL, meaning the original and its resized variants, is a column the File owns. So are the media facts, such as size, dimensions and Mux ids. The map reads each one with `from()` on the File target of `relatedMorph()`. They are ordinary mapped attributes: the backfill copies them, the round trip holds, v1 filters keep working, and rollback refills them from the File.
- **A value with no column anywhere.** The map reads it with `computedAs()` and a read closure that computes it from the successor model, marked `readOnly()`. A ZML URL signed on each read is such a value.

A genuine legacy-only value is one that no successor can supply. The application refreshes it through the legacy model, and the package never refreshes it. [Operations: legacy-only writes](operations.md#legacy-only-writes) states what such a write runs ([0031](../decisions/0031-legacy-only-values-are-projected-or-written-through-the-legacy-model.md)).

Declined: a refresh hook driven by successor saves. It needs a listener on every related successor class, and it misses mass and raw writes to `files`, such as the completion of the ZML migration.

## Related successors

`related()` takes a closure that returns an Eloquent relation of the successor it sits under. The relation's constraints live inside the closure, such as `->withTrashed()`. The package reads the related model class from the relation. An optional `successor:` closure declares the related successor's own attributes and relations.

```php
->related(fn (Job $job) => $job->listingCompatibilityState())
->related(relation: fn (Job $job) => $job->place(), successor: fn (Successor $place) => $place
    ->attribute(name: 'place_id', definition: fn (Attribute $attribute) => $attribute->from('id'))
    ->related(fn (Place $place) => $place->address()->withTrashed()))
```

A related successor without a `successor:` closure is a companion. The map reads it through `orFrom()` or in `readAs()` expressions, and an operation creates it with `relatedOrNew()`.

An operation references a related successor by its model class, such as `$successors->relatedOrNew(Place::class)`. Attribute names and column names stay strings. When the map compiles, it refuses two related successors of one class under one root. A PHPStan rule reports an operation that references a class the map does not relate ([0021](../decisions/0021-related-successors-are-referenced-by-class.md)).

### Morph targets

`relatedMorph()` declares a `MorphTo` relation and its targets. Its `choose:` closure picks the target class for a legacy record. Its `targets:` closure declares each target over `Definition\Targets`.

```php
->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset(),
    choose: fn (FloorPlan $plan): string => $plan->url !== null ? Link::class : File::class,
    targets: fn (Targets $targets) => $targets
        ->as(target: File::class, successor: fn (Successor $file) => $file)
        ->as(target: Link::class, successor: fn (Successor $link) => $link))
```

When `choose:` picks another class for a record, the package moves the target in one unit of work ([Operations: morph targets](operations.md#morph-targets)).

### Membership

`when()` on the root scopes the successor rows the legacy model serves. FloorPlan serves only the `PlaceAsset` rows whose `legacy_type` is `floorplan`. A native successor create inserts a legacy row only for a successor inside that scope.

## Hooks

| Method | Interface | The package calls it |
| :-- | :-- | :-- |
| `writtenBy()`, on an attribute | `Contracts\WritesAttribute` | when a write changes that attribute, including a create |
| `successorsInitializedBy()` | `Contracts\InitializesSuccessors` | when the package creates the root, after the attribute operations |
| `legacyInitializedBy()` | `Contracts\InitializesLegacy` | when an application creates a member successor natively |
| `writesWithin()` | `Contracts\ScopesWrites` | before every lock the package takes, on every path except install's final catch-up and rollback's refill |
| `dependents()` | `Contracts\ManagesDependents` | inside the install and rollback transactions |

Every hook is optional, and a map declares each hook at most once. Without `writtenBy()`, an attribute runs its default operation. Without `dependents()`, install refuses when an object depends on the legacy table.

## Classes only

`writtenBy()`, `successorsInitializedBy()`, `legacyInitializedBy()`, `writesWithin()` and `dependents()` take a class-string of their interface. A closure is a type error ([0022](../decisions/0022-operations-are-classes.md)).

The other closures a map holds stay closures. They are `when()`, `choose:`, the scope of `hide()`, the condition of `guard()`, the value of `isNotNull()`, and the read of `computedAs()`. Each one selects rows, picks a class or computes a value, and none of them writes. The five hooks are the places where application behavior runs inside the package's transaction.

The classes live in the consumer's entity namespace plus `\Compatibility`. This is a documented convention, and no tool checks it. Aryeo's is `App\Entities\<Plural>\Compatibility`, whether or not the model itself lives there. The worked examples therefore place the classes of `App\Entities\Listings\Listing` under `App\Entities\Listings\Compatibility`. Declined: the model's own namespace plus `\Compatibility`.

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
