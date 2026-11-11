Status: Accepted

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
    ->attribute(name: 'company_id', definition: fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A Job never changes company.', ignoreWrites: false)))
```

Listing adopts its existing `job_id` column as the successor key. FloorPlan names `place_asset_id`, a column that `prepare()` adds and the backfill fills.

Only the root has a successor key. A related successor is reached through the root's relations. A stored key for a related successor goes stale when an application moves the root, as v2 moves a Job to another Place. Legacy columns such as `listings.place_id` are therefore ordinary mapped attributes, read from the related row.

`retainedIn()` names the table that the legacy table becomes at install. [Storage](storage.md) states its columns and constraints.

## Reads are projections

Each attribute declares how the view reads it, and nothing else about its storage. An attribute reads one successor column with `from()`, optionally through the package's conversions. Two helpers cover the common projections:

- `orFrom(successor:, column:)` reads the attribute's column, or that related successor's column when the first is NULL. The class must be a successor the map relates, and the map refuses any other class when it compiles.
- `position()` reads the row's one-based position through eloquent-ordering's published position expression for the successor. Positions number within the successor's eloquent-ordering parent, not within a legacy parent. The attribute needs no `writtenBy()`, because its default operation places the row. The package compiles the read, so a filter on the attribute still runs as a rebuilt expression rather than on the view. A legacy `orderBy('index')` sorts by the position expression, because `rank_key` values from two parents share no order. A query that pins one parent sorts by `rank_key` instead. A `where('index', N)` across parents compiles without a count per candidate row. After install, a write moves the row with `Placement::at()`. A backfill row or catch-up row re-sequences its parent in legacy order. [Operations: positions](operations.md#positions) states the re-sequence. `position()` lands in M5b, and this release does not ship it ([Status](README.md#status)).

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

An attribute with no inverse must declare `writtenBy()` or `readOnly()`, or the map does not compile. A `readAs()` attribute and a `computedAs()` attribute have no inverse.

## Read-only attributes

`readOnly(because:, ignoreWrites:)` marks an attribute that no write changes, and states the reason. `ignoreWrites:` has no default, so every declaration states it. With `false`, an update of the attribute refuses. A create assigns the inverse into a successor row it creates. For an attribute with no stored column, a live create that supplies a non-NULL value refuses. With `true`, every path accepts and discards the write, and a discarded non-NULL write dispatches `Events\WriteIgnored`, except on catch-up. [Operations: read-only attributes](operations.md#read-only-attributes) states what each value runs.

A map adopts with `ignoreWrites: true`. A listener on `Events\WriteIgnored` then finds the writers that still send the attribute, and no write refuses while the application still has them. The event dispatches synchronously, inside the write's transaction and under its locks, so a listener that throws fails the write and a slow one holds every lock. Make the listener queued and dispatched after commit, which reports the writer without holding the write. After install, the map flips the flag to `false`, once the `WriteIgnored` events from `Origin::Live` show no writer. Before install, a v1 write lands in the legacy column, and catch-up discards it without an event. A backfill create dispatches only for a value it does not assign, so `Origin::Backfill` events show what the backfill dropped, not a live writer. The flip changes only the map: no schema and no data change. The guidance holds for an attribute with a stored column and for one without.

```php
->attribute(name: 'company_id', definition: fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A Job never changes company.', ignoreWrites: true))
```

The worked examples declare `ignoreWrites: false`, the state after the flip.

## Legacy-only columns

A legacy-only column is a column of the retained table that no attribute maps, other than the legacy identity and the successor key ([Storage: the retained table](storage.md#the-retained-table)). The view reads it from the retained table.

Project a value that a successor column holds, or that a read can compute each time. Such a value is projected, not stored. A projected value cannot go stale, because every read resolves it from the successor.

- **A successor column.** The map reads it with `from()` or `orFrom()`. A media asset's intrinsic metadata and its third-party ids are columns the File owns, and the map reads each one with `from()` on the File target of `relatedMorph()` ([Media values](#media-values)).
- **A value with no column anywhere.** The map reads it with `computedAs()` and a read closure that computes it from the successor model, marked `readOnly()`. A ZML URL signed on each read is such a value.
- **A value that resolves by a successor's state.** The map reads it with `computedAs()` and pairs it with a `writtenBy()` operation. A media URL resolves by the File's storage state, and [Media values](#media-values) shows the pattern.

A genuine legacy-only value is one that no successor column holds and no read computes. A value that `legacyInitializedBy()` derives once from the successor graph is one. `listings.title`, copied from `Job.name` at birth, is such a value. So is `photos.listing_id`, derived through Place, Job and `listings.job_id`. The application refreshes a legacy-only value through the legacy model, and the package never refreshes it. [Operations: legacy-only writes](operations.md#legacy-only-writes) states what such a write runs ([0031](../decisions/0031-legacy-only-values-are-projected-or-written-through-the-legacy-model.md)).

Declined: a refresh hook driven by successor saves. It needs a listener on every related successor class, and it misses mass and raw writes to `files`, such as the completion of the ZML migration.

### Media values

A legacy media record carries values of two kinds, and each kind takes its own projection.

**Intrinsic metadata and third-party ids.** Width, height, size, format, duration and orientation are intrinsic metadata. A Mux id is a third-party id. Each one is a File column, which the map reads with `from()` on the File target of `relatedMorph()`. Every creation path writes these columns once. The backfill copies v1's values, a v1 create writes v1's values, and the consumer's ZML ingest writes ZML's metadata. The ZML migration does not change them. They are ordinary mapped attributes: the round trip holds, v1 filters keep working, and rollback refills them from the File.

**Delivery values.** The original URL and the variant URLs resolve by the File's storage state. The map reads each one with `computedAs()`, through the consumer's File resolver, which prefers ZML when `zml_asset_id` is set. A `writtenBy()` operation stores a v1 legacy URL, and its behavior depends on the origin:

- **`Origin::Backfill`.** It writes the v1 URL onto a File that is not in ZML, and does nothing for a File in ZML.
- **`Origin::Live`.** It writes the v1 URL onto a File that is not in ZML, and refuses for a File in ZML.

Under `Origin::Backfill`, the comparisons skip a `computedAs()` attribute. A pass runs its operation on every row it walks whose chosen target declares the attribute, and the operation must change nothing when it runs again with an unchanged value ([Operations: computed attributes under the backfill](operations.md#computed-attributes-under-the-backfill)). A v1 URL edit made before install therefore reaches a non-ZML File when a pass walks the row: when the edit sets `updated_at`, or when a pass walks the row for another reason. An edit that leaves `updated_at` alone is lost ([Lifecycle: the writer boundary](lifecycle.md#the-writer-boundary)). An asset the ZML migration moved never refuses.

The map declares the metadata and the delivery values under the File target. A `computedAs()` read closure receives a collection of the successors the attribute sits under, once per query, and returns their values keyed by successor key.

```php
->as(target: File::class, successor: fn (Successor $file) => $file
    ->attribute(name: 'width', definition: fn (Attribute $attribute) => $attribute->from('width'))
    ->attribute(name: 'mux_id', definition: fn (Attribute $attribute) => $attribute->from('mux_id'))
    ->attribute(name: 'original_url', definition: fn (Attribute $attribute) => $attribute
        ->computedAs(read: fn (Collection $files) => $files->mapWithKeys(fn (File $file) => [$file->getKey() => $file->originalUrl()]))
        ->writtenBy(StoreLegacyUrl::class)))
```

```php
final class StoreLegacyUrl implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $file = $successors->relatedOrNew(File::class);

        if ($file->zml_asset_id === null) {
            $file->legacy_url = $value;

            return;
        }

        if ($change->origin === Origin::Live) {
            throw Refusal::because("File {$file->getKey()} is in ZML, so its URL is not writable.");
        }
    }
}
```

Each variant URL follows the same pattern. Under `Origin::Live`, the round trip calls the read closure with the reloaded File, because a computed value is not in the view ([Operations: what the comparisons skip](operations.md#what-the-comparisons-skip)).

The alternative for a delivery value is a stable redirect URL that the consumer controls, read with `readAs()`. That value can be filtered in SQL.

Declined: reading metadata live from an outside store on each read. That costs one call per asset, loses SQL filters, and fetches a value that does not change. Declined: choosing the source by the legacy record.

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

`when()` on the root decides which roots gain a legacy row when they are created natively. A native `PlaceAsset` create gains a FloorPlan row only when its `legacy_type` is FloorPlan's morph alias.

The package evaluates `when()` on every native create of a root class that a discovered map serves, before and after install. It does so to choose which legacy tables to lock, which legacy model receives a shared root's pre-insert events, and, after install, which legacy rows to insert. It evaluates it again at install's insert of retained rows for natively created roots. From then on, the successor key binds the legacy row to its root, and the package never evaluates `when()` to decide what that row serves ([Storage: the view](storage.md#the-view)).

Membership must therefore be exclusive across the maps that share a root, and stable from creation. A root that two maps' `when()` both match gets a legacy row in each. A root that leaves its map's scope stays served by the legacy row it already has.

For a root that several legacy models share, declare membership on an immutable discriminator column, such as Aryeo's `place_assets.legacy_type`. `successorsInitializedBy()` sets it for a root the package creates. A `creating` listener on the successor sets it for a native create.

A change of membership after creation is a consumer reclassification: delete the record through one legacy model, then create it through the other. The package never reclassifies a record. `compatibility:check` reports a served record whose root no longer matches its map's `when()` ([Public surface: commands](public-surface.md#commands)).

## Hooks

| Method | Interface | The package calls it |
| :-- | :-- | :-- |
| `writtenBy()`, on an attribute | `Contracts\WritesAttribute` | when a write changes that attribute, including a create; for a `computedAs()` attribute, also on every row a pass walks ([Operations: computed attributes under the backfill](operations.md#computed-attributes-under-the-backfill)) |
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
| membership | the roots that gain a legacy row at creation, scoped by `when()`; the successor key binds the row from then on |
| operation | a class that turns a write of one attribute into successor changes |
| `Successors` | the unit of work an operation receives, generic over the root class |
| the round trip | after a write, every written attribute reads back its new value |
| the round-trip check | the package's check of the round trip, which refuses the write and rolls it back when it fails |
