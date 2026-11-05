Status: Proposed

# Floor plan example

`FloorPlan` is served from `PlaceAsset`, whose polymorphic `asset()` points at a `File` or a `Link`. `PlaceAsset` is a shared successor: other media models are served from it too. The package's class and method names are the specified ones. The Aryeo class names are illustrative.

## The tables

`floorplans` keeps `id bigint` and `uuid`. It has no reference to its `PlaceAsset`, so `prepare()` adds `place_asset_id uuid` as the successor key, and the backfill fills it.

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
            ->servedFrom(PlaceAsset::class, key: 'place_asset_id', successor: fn (Successor $asset) => $asset
                ->when(fn (Builder $assets) => $assets->where('type', 'floorplan'))
                ->attribute('index', fn (Attribute $a) => $a
                    ->readAs('<position over rank_key>')
                    ->writtenBy(MovePlaceAsset::class))
                ->attribute('display_type', fn (Attribute $a) => $a
                    ->readAs('CASE WHEN "asset_files"."displays_on_property_websites" THEN "asset_files"."display_type" ELSE \'none\' END')
                    ->writtenBy(WriteDisplayType::class))
                ->relatedMorph(fn (PlaceAsset $asset) => $asset->asset(),
                    choose: fn (FloorPlan $plan): string => $plan->url !== null ? Link::class : File::class,
                    targets: fn (Targets $targets) => $targets
                        ->as(File::class, fn (Successor $file) => $file)
                        ->as(Link::class, fn (Successor $link) => $link)))
            ->successorsInitializedBy(InitializeFloorPlanAsset::class)
            ->legacyInitializedBy(InitializeLegacyFloorPlan::class)
            ->writesWithin(MediaWriteScope::class);
    }
}
```

`when()` declares membership: a `PlaceAsset` is a floor plan when its `type` is `floorplan`. The example elides the position expression of `index`, and the mapped attributes of the File and the Link.

## The operations

`MovePlaceAsset` turns a position into a move through eloquent-ordering's own API. `moveTo()` saves on its own, so it runs after the staged saves.

```php
final class MovePlaceAsset implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $successors->afterSave(fn () => $successors->root()->moveTo($value - 1));
    }
}
```

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
        $asset->subsection_id = DefaultSection::for('floorplan', $asset->place_id);
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
        $legacy->listing_id = Listing::query()->where('job_id', $job->getKey())->value('id');
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
        $legacy->listing_id = Listing::query()->where('job_id', $job->getKey())->value('id');
    }
}
```

## The write scope

`MediaWriteScope` applies eloquent-ordering's rule: lock the parents first. It calls Aryeo's own use of eloquent-ordering, so the package depends on neither sibling package. `OrderedParents::lockFirst()` stands for the routine that eloquent-ordering must publish.

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
