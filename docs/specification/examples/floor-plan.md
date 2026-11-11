Status: Accepted

# Floor plan example

`FloorPlan` is served from `PlaceAsset`, whose polymorphic `asset()` points at a `File` or a `Link`. `PlaceAsset` is a shared successor: other media models are served from it too. The package's class and method names are the specified ones. The Aryeo class names are illustrative.

## The tables

`floorplans` keeps `id bigint` and `uuid`. It has no reference to its `PlaceAsset`, so `prepare()` adds `place_asset_id uuid` as the successor key, and the backfill fills it.

`legacy_type` is a column Aryeo adds to `place_assets`, holding the media's morph alias: `getMorphClass()` of the legacy model a `PlaceAsset` serves, one of `photo`, `video`, `floorplan`, `legacy_file` or `interactive_content`. Aryeo's morph map registers each alias, and the alias can differ from the class name: `Image` is `photo`. Aryeo's `place_assets` today has `asset_type` for the File/Link morph. `place_assets` also holds the display columns, `displays_on_property_websites` and `display_type`.

`photos.id` is `integer`, and every other media key is `bigint`. Each table keeps its own key and sequence, so the widths never meet. `photos.listing_id integer NOT NULL` stays on `photos_retained`, with its foreign key to `listings`.

## The map

```php
namespace App\Entities\FloorPlans\Compatibility;

final class FloorPlanMap
{
    public static function define(Compatibility $floorPlan): Compatibility
    {
        return $floorPlan
            ->retainedIn('floorplans_retained')
            ->servedFrom(root: PlaceAsset::class, key: 'place_asset_id', successor: fn (Successor $asset) => $asset
                ->when(fn (Builder $assets) => $assets->where(column: 'legacy_type', operator: '=', value: (new FloorPlan)->getMorphClass()))
                ->attribute(name: 'created_at', definition: fn (Attribute $attribute) => $attribute->from('created_at')->inTimeZone('UTC'))
                ->attribute(name: 'updated_at', definition: fn (Attribute $attribute) => $attribute->from('updated_at')->inTimeZone('UTC'))
                ->attribute(name: 'deleted_at', definition: fn (Attribute $attribute) => $attribute->from('deleted_at')->inTimeZone('UTC'))
                ->attribute(name: 'index', definition: fn (Attribute $attribute) => $attribute->position())
                ->attribute(name: 'display_type', definition: fn (Attribute $attribute) => $attribute
                    ->readAs('CASE WHEN "place_assets"."displays_on_property_websites" THEN "place_assets"."display_type" ELSE \'none\' END')
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

`when()` declares membership: a `PlaceAsset` is a floor plan when its `legacy_type` is FloorPlan's morph alias, `floorplan`. `legacy_type` never changes after the `PlaceAsset` is created, so membership is exclusive across the media maps and stable from creation. `InitializeFloorPlanAsset` sets it for a `PlaceAsset` the package creates, and Aryeo's `creating` listener on `PlaceAsset` sets it for a native create ([The map: membership](../the-map.md#membership)). The example elides the mapped attributes of the File and the Link. The File's include the intrinsic metadata and the Mux ids, which the File owns as columns and the map reads with `from()`. They also include the media URLs, which the map reads with `computedAs()` and writes with a `writtenBy()` operation ([The map: media values](../the-map.md#media-values)).

`floorplans` has a `deleted_at`, so the map declares it as Listing's map does. `deleted_at` and `updated_at` run the default operations of Eloquent-managed columns ([Operations: Eloquent-managed columns](../operations.md#eloquent-managed-columns)). A legacy soft delete queues the `PlaceAsset`'s `delete()`, and a legacy `forceDelete()` runs its `forceDelete()`. The `index` operation's `move()` saves the `PlaceAsset` again under `Model::withoutTimestampsOn()`, so the `PlaceAsset` keeps the legacy `updated_at`.

`index` reads its one-based position through eloquent-ordering's published position expression. It needs no `writtenBy()`, because its default operation places the `PlaceAsset`. After install, a write moves the row with `Placement::at()`. A backfill row or catch-up row re-sequences its parent, so the parent's floor plans end in legacy order ([Operations: positions](../operations.md#positions)). `position()` waits on [eloquent-ordering#14](https://github.com/AryeoHQ/eloquent-ordering/issues/14), so this map cannot run until it ships ([Status](../README.md#status)).

Positions number within each eloquent-ordering parent, so a legacy reader that needs one sequence per listing must read `readAs()` with an operation instead.

### What `index` reads after install

After install, a `position()` attribute reads the successor's position, not the stored legacy value. The order of the rows within each eloquent-ordering parent does not change, but three kinds of value do:

- A row whose legacy value was NULL reads a number.
- Sparse values close up: legacy values 1, 4 and 7 read 1, 2 and 3.
- Positions restart in each parent. A listing whose photos span two subsections reads one sequence per subsection.

Aryeo's `photos` holds live rows with a NULL `index`, so those photos read a number after install. A trashed row reads a NULL `index` after install, because a trashed record has no position.

## The operations

`WriteDisplayType` writes one legacy value into two columns of the root `PlaceAsset`, whatever the morph target. `'none'` means the asset is not displayed, and v2 has no `'none'` display type. `'none'` therefore sets `displays_on_property_websites` to false. Any other value sets it to true and sets `display_type`. The operation never touches the File or the Link.

`'none'` leaves `display_type` unassigned. The column is NOT NULL with no database default. On a create, `PlaceAsset`'s model default, `'both'`, fills it, because the package creates the `PlaceAsset` through its model. On an update, the column keeps its stored value.

```php
final class WriteDisplayType implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $asset = $successors->root();
        $asset->displays_on_property_websites = $value !== 'none';

        if ($value !== 'none') {
            $asset->display_type = $value;
        }
    }
}
```

## The initializers

`InitializeFloorPlanAsset` sets the membership discriminator from the legacy model's `getMorphClass()`, and the successor-only parent keys. `rank_key` needs nothing, because eloquent-ordering's `IsOrdered` assigns it when the `PlaceAsset` is created.

```php
final class InitializeFloorPlanAsset implements InitializesSuccessors
{
    public function initialize(Successors $successors, Creation $creation): void
    {
        $asset = $successors->root();
        $asset->legacy_type = $creation->record->getMorphClass();
        $asset->place_id = Listing::query()->whereKey($creation->record->listing_id)->value('place_id');
        $asset->subsection_id = DefaultSection::for(type: 'floorplan', place: $asset->place_id);
    }
}
```

`InitializeLegacyFloorPlan` runs the other way. It derives the legacy-only NOT NULL `listing_id` from the successor graph: the asset's Place, that Place's Job, and the listing whose `job_id` is that Job. It resolves each one with `withTrashed()`, so a trashed Place, Job or listing still answers. When one is missing, it refuses and names the asset's key. It never reads the stored `listings.place_id`, which goes stale when v2 moves a Job to another Place.

```php
final class InitializeLegacyFloorPlan implements InitializesLegacy
{
    public function initialize(Successors $successors, Creation $creation): void
    {
        $asset = $successors->root();
        $place = $asset->place()->withTrashed()->first()
            ?? throw Refusal::because("Floor plan asset {$asset->getKey()} has no Place.");
        $job = $place->job()->withTrashed()->first()
            ?? throw Refusal::because("Floor plan asset {$asset->getKey()} has no Job.");
        $creation->record->listing_id = Listing::withTrashed()->where(column: 'job_id', operator: '=', value: $job->getKey())->value('id')
            ?? throw Refusal::because("Floor plan asset {$asset->getKey()} has no listing.");
    }
}
```

Under install's lock, install catches nothing from an initializer. A `Refusal` from `legacyInitializedBy()` there aborts install, and names the asset's key and the reason ([Operations: native successors before install](../operations.md#native-successors-before-install)).

FloorPlan and Photo install after Listing. Before Listing installs, a Job created natively has no listing row, so a media install would find no listing for that Job's assets and abort.

## Photos

`Photo` is served from `PlaceAsset` the same way. Its `when()` compares `legacy_type` with Photo's `getMorphClass()`, which is `photo`. Its map names `InitializeLegacyPhoto` in `legacyInitializedBy()`. `photos.listing_id integer NOT NULL` stays on `photos_retained`, so a native `PlaceAsset` create needs it.

`InitializeLegacyPhoto` derives `photos.listing_id` through the asset's Place, that Place's Job, and `listings.job_id`. It resolves each one with `withTrashed()`, and refuses with the asset's key when one is missing.

```php
final class InitializeLegacyPhoto implements InitializesLegacy
{
    public function initialize(Successors $successors, Creation $creation): void
    {
        $asset = $successors->root();
        $place = $asset->place()->withTrashed()->first()
            ?? throw Refusal::because("Photo asset {$asset->getKey()} has no Place.");
        $job = $place->job()->withTrashed()->first()
            ?? throw Refusal::because("Photo asset {$asset->getKey()} has no Job.");
        $creation->record->listing_id = Listing::withTrashed()->where(column: 'job_id', operator: '=', value: $job->getKey())->value('id')
            ?? throw Refusal::because("Photo asset {$asset->getKey()} has no listing.");
    }
}
```

## The write scope

`MediaWriteScope` applies eloquent-ordering's rule: lock the parents first. It runs inside the package's transaction, so it opens none of its own. It calls Aryeo's own use of eloquent-ordering to take that lock order. Once [eloquent-ordering#14](https://github.com/AryeoHQ/eloquent-ordering/issues/14) ships, the package requires eloquent-ordering for `position()`, but takes no lock order from it. `OrderedParents::lockFirst()` stands for the routine that eloquent-ordering must publish.

```php
final class MediaWriteScope implements ScopesWrites
{
    public function run(array $successors, Closure $write): mixed
    {
        OrderedParents::lockFirst(PlaceAsset::query()->whereKey($successors[PlaceAsset::class] ?? [])->get());

        return $write();
    }
}
```
