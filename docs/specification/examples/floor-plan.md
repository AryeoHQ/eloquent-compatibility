Status: Proposed

# Floor plan example

`FloorPlan` is served from `PlaceAsset`, whose polymorphic `asset()` points at a `File` or a `Link`. `PlaceAsset` is a shared successor: other media models are served from it too. The package's class and method names are the specified ones. The Aryeo class names are illustrative.

## The tables

`floorplans` keeps `id bigint` and `uuid`. It has no reference to its `PlaceAsset`, so `prepare()` adds `place_asset_id uuid` as the successor key, and the backfill fills it.

Before prepare, a migration of the consumer's creates the position index concurrently:

```sql
CREATE INDEX CONCURRENTLY floorplans_listing_id_index_id ON floorplans (listing_id, "index", id) WHERE deleted_at IS NULL;
```

`photos.id` is `integer`, and every other media key is `bigint`. Each table keeps its own key and sequence, so the widths never meet. `photos.listing_id integer NOT NULL` stays on `photos_retained`, with its foreign key to `listings`.

## The map

```php
namespace App\Media\Compatibility;

final class FloorPlanMap
{
    public static function define(Compatibility $floorPlan): Compatibility
    {
        return $floorPlan
            ->retainedIn('floorplans_retained')
            ->servedFrom(root: PlaceAsset::class, key: 'place_asset_id', successor: fn (Successor $asset) => $asset
                ->when(fn (Builder $assets) => $assets->where(column: 'type', operator: '=', value: 'floorplan'))
                ->attribute(name: 'created_at', definition: fn (Attribute $a) => $a->from('created_at')->inTimeZone('UTC'))
                ->attribute(name: 'updated_at', definition: fn (Attribute $a) => $a->from('updated_at')->inTimeZone('UTC'))
                ->attribute(name: 'index', definition: fn (Attribute $a) => $a->position(legacyWithin: 'listing_id'))
                ->attribute(name: 'display_type', definition: fn (Attribute $a) => $a
                    ->readAs('CASE WHEN "asset_files"."displays_on_property_websites" THEN "asset_files"."display_type" ELSE \'none\' END')
                    ->writtenBy(WriteDisplayType::class))
                ->relatedMorph(relation: fn (PlaceAsset $asset) => $asset->asset(),
                    choose: fn (FloorPlan $plan): string => $plan->url !== null ? Link::class : File::class,
                    targets: fn (Targets $targets) => $targets
                        ->as(target: File::class, successor: fn (Successor $file) => $file)
                        ->as(target: Link::class, successor: fn (Successor $link) => $link)))
            ->successorsInitializedBy(InitializeFloorPlanAsset::class)
            ->legacyInitializedBy(InitializeLegacyFloorPlan::class)
            ->writesWithin(MediaWriteScope::class);
    }
}
```

`when()` declares membership: a `PlaceAsset` is a floor plan when its `type` is `floorplan`. The example elides the mapped attributes of the File and the Link.

`index` reads its one-based position through eloquent-ordering's published position expression, and `legacyWithin:` names the legacy parent column, `listing_id`. It needs no `writtenBy()`: its default operation moves the `PlaceAsset` with `Placement::at($value)` ([Operations: positions](../operations.md#positions)).

One fact stays open, to verify during implementation. A listing's floor plans must be exactly the members of one eloquent-ordering parent. Otherwise the position the successor serves is not the legacy rank.

### What `index` reads after install

After install, a `position()` attribute reads the successor's position, not the stored legacy value. The order of the rows does not change, but two kinds of value do:

- A row whose legacy value was NULL reads a number.
- Sparse values close up: legacy values 1, 4 and 7 read 1, 2 and 3.

Aryeo's `photos` holds live rows with a NULL `index`, so those photos read a number after install.

## The operations

`WriteDisplayType` writes one legacy value into two successor columns. `'none'` means the File is not displayed, and v2 has no `'none'` display type.

```php
final class WriteDisplayType implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $file = $successors->related(File::class);
        $file->displays_on_property_websites = $value !== 'none';

        if ($value !== 'none') {
            $file->display_type = $value;
        }
    }
}
```

## The initializers

`InitializeFloorPlanAsset` sets the successor-only parent keys. `rank_key` needs nothing, because eloquent-ordering's `IsOrdered` assigns it when the `PlaceAsset` is created.

```php
final class InitializeFloorPlanAsset implements InitializesSuccessors
{
    public function initialize(Successors $successors, Change $change): void
    {
        $asset = $successors->root();
        $asset->place_id = Listing::query()->whereKey($change->record->listing_id)->value('place_id');
        $asset->subsection_id = DefaultSection::for(type: 'floorplan', place: $asset->place_id);
    }
}
```

`InitializeLegacyFloorPlan` runs the other way. It derives the legacy-only NOT NULL `listing_id` from the successor graph: the asset's Place, that Place's Job, and the listing whose `job_id` is that Job, as decision 5 states. It never reads the stored `listings.place_id`, which goes stale when v2 moves a Job to another Place.

```php
final class InitializeLegacyFloorPlan implements InitializesLegacy
{
    public function initialize(Model $legacy, Successors $successors, Change $change): void
    {
        $job = $successors->root()->place->job;
        $legacy->listing_id = Listing::query()->where(column: 'job_id', operator: '=', value: $job->getKey())->value('id');
    }
}
```

## Photos

`Photo` is served from `PlaceAsset` the same way, with the membership `type = 'photo'`. Its map names `InitializeLegacyPhoto` in `legacyInitializedBy()`. `photos.listing_id integer NOT NULL` stays on `photos_retained`, so a native `PlaceAsset` create needs it.

`InitializeLegacyPhoto` derives `photos.listing_id` through the asset's Place, that Place's Job, and `listings.job_id`, as decision 5 states.

```php
final class InitializeLegacyPhoto implements InitializesLegacy
{
    public function initialize(Model $legacy, Successors $successors, Change $change): void
    {
        $job = $successors->root()->place->job;
        $legacy->listing_id = Listing::query()->where(column: 'job_id', operator: '=', value: $job->getKey())->value('id');
    }
}
```

## The write scope

`MediaWriteScope` applies eloquent-ordering's rule: lock the parents first. It calls Aryeo's own use of eloquent-ordering to take that lock order. The package requires eloquent-ordering for `position()`, but takes no lock order from it. `OrderedParents::lockFirst()` stands for the routine that eloquent-ordering must publish.

```php
final class MediaWriteScope implements ScopesWrites
{
    public function run(array $successors, Closure $write): mixed
    {
        return DB::transaction(function () use ($successors, $write) {
            OrderedParents::lockFirst(PlaceAsset::query()->whereKey($successors[PlaceAsset::class] ?? [])->get());

            return $write();
        });
    }
}
```
