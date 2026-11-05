Status: Proposed

# Listing example

`Listing` is served from `Job`, with a Place, a JobSite and a compatibility state row as related successors. The package's class and method names are the specified ones. The Aryeo class names are illustrative.

## The tables

`listings` has `id integer` and `uuid uuid NOT NULL`. The uuid has no default: Aryeo generates it with `Listing::makeUuid()`. The package fills it on a native create through `Listing::newUniqueId()` when `Listing` defines it. Otherwise `InitializeLegacyListing` must set it, or the map does not compile.

`listings` also has `job_id`, `place_id` and `job_site_id`, all `uuid`, and `address_id bigint`. `job_id` already references `jobs.id`, so it becomes the successor key. `place_id`, `job_site_id` and `address_id` are mapped attributes, read from the related rows. After install, the table is `listings_retained`.

## The model

```php
namespace App\Listings\Models;

final class Listing extends Model implements Compatible
{
    use ServesCompatibility;

    public static function compatibility(Compatibility $listing): Compatibility
    {
        return ListingMap::define($listing);
    }
}
```

## The map

```php
namespace App\Listings\Compatibility;

final class ListingMap
{
    public static function define(Compatibility $listing): Compatibility
    {
        return $listing
            ->retainedIn('listings_retained')
            ->servedFrom(Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                ->attribute('created_at', fn (Attribute $a) => $a->from('created_at')->inTimeZone('UTC'))
                ->attribute('updated_at', fn (Attribute $a) => $a->from('updated_at')->inTimeZone('UTC'))
                ->attribute('deleted_at', fn (Attribute $a) => $a->from('deleted_at')->inTimeZone('UTC'))
                ->attribute('company_id', fn (Attribute $a) => $a->from('company_id')->readOnly(because: 'A Job never changes company.'))
                ->attribute('is_draft', fn (Attribute $a) => $a
                    ->readAs('"jobs"."lifecycle_status" = \'draft\'')
                    ->writtenBy(PublishOrDraftJob::class))
                ->attribute('downloads_enabled', fn (Attribute $a) => $a
                    ->readAs('COALESCE("jobs"."are_customer_downloads_enabled", "listing_compatibility_states"."downloads_enabled_fallback")')
                    ->writtenBy(SetDownloadsPermission::class))
                ->attribute('address_id', fn (Attribute $a) => $a
                    ->readAs('"addresses"."id"')
                    ->writtenBy(ChangeListingAddress::class))
                ->related(fn (Job $job) => $job->listingCompatibilityState())
                ->related(fn (Job $job) => $job->place(), successor: fn (Successor $place) => $place
                    ->attribute('place_id', fn (Attribute $a) => $a->from('id'))
                    ->related(fn (Place $place) => $place->address()->withTrashed()))
                ->related(fn (Job $job) => $job->jobSite(), successor: fn (Successor $site) => $site
                    ->attribute('job_site_id', fn (Attribute $a) => $a->from('id'))))
            ->successorsInitializedBy(InitializeListingJob::class)
            ->legacyInitializedBy(InitializeLegacyListing::class)
            ->writesWithin(ListingWriteScope::class)
            ->dependents(ListingDependents::class)
            ->catchUpAlso(ListingAddressChanges::class);
    }
}
```

`place_id` and `job_site_id` read the keys of the related Place and JobSite. On a create, their default operations assign those keys to the new rows, so a reserved id is honored ([Operations: creates](../operations.md#creates)). `address_id` reads the `id` of the Address the Place points at. That read is what `ChangeListingAddress` must satisfy for the round trip. The state row is a companion: the map declares no attributes on it, and `downloads_enabled` reads it in `readAs()`.

## The operations

`PublishOrDraftJob` runs a domain action. Its `afterSave()` action runs after the staged saves, and `publish()` or `draft()` saves the Job on its own.

```php
namespace App\Listings\Compatibility;

final class PublishOrDraftJob implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $successors->afterSave(fn () => $value ? $successors->root()->draft() : $successors->root()->publish());
    }
}
```

`SetDownloadsPermission` writes the Job's own column. The fallback on the state row keeps serving a Job whose column is NULL.

```php
final class SetDownloadsPermission implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $successors->root()->are_customer_downloads_enabled = (bool) $value;
    }
}
```

`ChangeListingAddress` is a compound change. It stages assignments on related successors, which it references by class. A NULL address deletes the Place and the JobSite. An address without valid coordinates deletes the JobSite.

```php
final class ChangeListingAddress implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        if ($value === null) {
            $successors->deleteRelated(Place::class);
            $successors->deleteRelated(JobSite::class);

            return;
        }

        $address = Address::withTrashed()->findOrFail($value);
        $successors->relatedOrNew(Place::class)->fill(['address_id' => $address->uuid, 'name' => $address->label()]);
        $address->hasValidCoordinates()
            ? $successors->relatedOrNew(JobSite::class)->fill(['latitude' => $address->latitude, 'longitude' => $address->longitude])
            : $successors->deleteRelated(JobSite::class);
    }
}
```

## The initializers

`InitializeListingJob` sets the successor-only values at birth. The attribute operations of the create ran before it, so the Job already holds every supplied attribute.

```php
final class InitializeListingJob implements InitializesSuccessors
{
    public function initialize(Successors $successors, Change $change): void
    {
        $listing = $change->record;
        $job = $successors->root();
        $job->name = $listing->title ?: 'Legacy listing '.$listing->uuid;
        $job->opted_in_to_zma = false;
        $job->payment_status = $change->origin === Origin::Backfill
            ? PaymentStatus::fromOrders($listing->orders)
            : PaymentStatus::Unpaid;
        $successors->relatedOrNew(ListingCompatibilityState::class)->fill([
            'downloads_enabled_fallback' => $listing->downloads_enabled ?? true,
        ]);
    }
}
```

`InitializeLegacyListing` runs the other way. `Job` carries `Provides\CarriesLegacy`, so a Job created natively becomes a listing. The package allocates `listings.id` from the listing sequence and the uuid through `Listing::newUniqueId()`, and sets `job_id`. The class sets the title.

```php
final class InitializeLegacyListing implements InitializesLegacy
{
    public function initialize(Model $legacy, Successors $successors, Change $change): void
    {
        $legacy->title = $successors->root()->name;
    }
}
```

## The write scope

`ListingWriteScope` runs before any lock the package takes. The package's own row locks come inside it, last.

```php
final class ListingWriteScope implements ScopesWrites
{
    public function run(array $successors, Closure $write): mixed
    {
        return JobWriteTransaction::run($successors[Job::class] ?? [], $write);
    }
}
```

## Dependent schema

`job_sites_view` reads `listings`. `ListingDependents` drops it before the swap and redefines it over the served listings after the swap, inside the install transaction.

The rollback mirrors reverse that, inside the rollback transaction. `beforeRollback()` drops `job_sites_view` before rollback drops the view it reads. `afterRollback()` recreates it over the restored `listings` table, after the refill.

```php
final class ListingDependents implements ManagesDependents
{
    public function beforeSwap(Connection $connection): void
    {
        $connection->statement('DROP VIEW job_sites_view');
    }

    public function afterSwap(Connection $connection): void
    {
        $connection->statement(JobSiteViewSql::overServedListings());
    }

    public function beforeRollback(Connection $connection): void
    {
        $connection->statement('DROP VIEW job_sites_view');
    }

    public function afterRollback(Connection $connection): void
    {
        $connection->statement(JobSiteViewSql::overLegacyListings());
    }
}
```

## The change source

An Address edit changes a listing's served values without touching `listings.updated_at`. `ListingAddressChanges` returns the listings whose Address changed since the watermark.

```php
final class ListingAddressChanges implements ReportsChanges
{
    /** @return iterable<int> */
    public function since(CarbonImmutable $watermark): iterable
    {
        return Listing::query()->whereIn('address_id', Address::query()->where('updated_at', '>=', $watermark)->select('id'))->pluck('id');
    }
}
```
