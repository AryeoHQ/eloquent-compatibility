Status: Proposed

# Listing example

`Listing` is served from `Job`, with a Place, a JobSite and a compatibility state row as related successors. The package's class and method names are the specified ones. The Aryeo class names are illustrative.

## The tables

`listings` has `id integer` and `uuid uuid NOT NULL`. The uuid has no default: Aryeo generates it with `Listing::makeUuid()`. `Listing` does not override `newUniqueId()`, so the package allocates no uuid, and `InitializeLegacyListing` sets it. An initializer that omits `uuid` still compiles, because declaring `legacyInitializedBy()` counts as initializing the column. `assertServesCompatibility()` runs a native create, which catches the omission with a NOT NULL violation.

`listings` also has `job_id`, `place_id` and `job_site_id`, all `uuid`, and `address_id bigint`. `job_id` already references `jobs.id`, so it becomes the successor key. `place_id`, `job_site_id` and `address_id` are mapped attributes, read from the related rows. After install, the table is `listings_retained`.

## The model

```php
namespace App\Entities\Listings;

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
namespace App\Entities\Listings\Compatibility;

final class ListingMap
{
    public static function define(Compatibility $listing): Compatibility
    {
        return $listing
            ->retainedIn('listings_retained')
            ->servedFrom(root: Job::class, key: 'job_id', successor: fn (Successor $job) => $job
                ->attribute(name: 'created_at', definition: fn (Attribute $attribute) => $attribute->from('created_at')->inTimeZone('UTC'))
                ->attribute(name: 'updated_at', definition: fn (Attribute $attribute) => $attribute->from('updated_at')->inTimeZone('UTC'))
                ->attribute(name: 'deleted_at', definition: fn (Attribute $attribute) => $attribute->from('deleted_at')->inTimeZone('UTC'))
                ->attribute(name: 'company_id', definition: fn (Attribute $attribute) => $attribute->from('company_id')->readOnly(because: 'A Job never changes company.'))
                ->attribute(name: 'is_draft', definition: fn (Attribute $attribute) => $attribute
                    ->readAs('"jobs"."lifecycle_status" = \'draft\'')
                    ->writtenBy(PublishOrDraftJob::class))
                ->attribute(name: 'downloads_enabled', definition: fn (Attribute $attribute) => $attribute
                    ->from(column: 'are_customer_downloads_enabled')
                    ->orFrom(successor: ListingCompatibilityState::class, column: 'downloads_enabled_fallback')
                    ->writtenBy(SetDownloadsPermission::class))
                ->related(fn (Job $job) => $job->listingCompatibilityState())
                ->related(relation: fn (Job $job) => $job->place(), successor: fn (Successor $place) => $place
                    ->attribute(name: 'place_id', definition: fn (Attribute $attribute) => $attribute->from('id')->readOnly(because: 'A Place is replaced, never re-keyed.'))
                    ->related(relation: fn (Place $place) => $place->address()->withTrashed(), successor: fn (Successor $address) => $address
                        ->attribute(name: 'address_id', definition: fn (Attribute $attribute) => $attribute
                            ->from(column: 'id')
                            ->writtenBy(ChangeListingAddress::class))))
                ->related(relation: fn (Job $job) => $job->jobSite(), successor: fn (Successor $site) => $site
                    ->attribute(name: 'job_site_id', definition: fn (Attribute $attribute) => $attribute->from('id')->readOnly(because: 'A JobSite is replaced, never re-keyed.'))))
            ->successorsInitializedBy(InitializeListingJob::class)
            ->legacyInitializedBy(InitializeLegacyListing::class)
            ->writesWithin(ListingWriteScope::class)
            ->dependents(ListingDependents::class);
    }
}
```

`deleted_at` and `updated_at` run the default operations of Eloquent-managed columns ([Operations: Eloquent-managed columns](../operations.md#eloquent-managed-columns)). A legacy soft delete queues the Job's `delete()`, and a legacy restore queues its `restore()`, so the Job's `deleting`, `deleted`, `restoring` and `restored` listeners run. A legacy `forceDelete()` runs the Job's `forceDelete()`. `PublishOrDraftJob`'s `publish()` saves the Job again under `Model::withoutTimestampsOn()`, so the Job keeps the legacy `updated_at`.

`company_id`, `place_id` and `job_site_id` are `readOnly()`. `place_id` and `job_site_id` read the keys of the related Place and JobSite. On a create, each default operation assigns its key into the row the create creates, so a reserved id is honored. An update of any of the three refuses ([Operations: creates](../operations.md#creates)). `address_id` reads `from(column: 'id')` on the Address the Place points at, as `place_id` reads the Place's key. `writtenBy()` names `ChangeListingAddress`, which must satisfy that read for the round trip. The state row is a companion: the map declares no attributes on it, and `downloads_enabled` reads its fallback through `orFrom()`.

## The operations

`PublishOrDraftJob` runs a domain action. Its `afterSave()` action runs after the staged saves, and `publish()` or `draft()` saves the Job on its own.

```php
namespace App\Entities\Listings\Compatibility;

final class PublishOrDraftJob implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $successors->afterSave(fn () => $value ? $successors->root()->draft() : $successors->root()->publish());
    }
}
```

`SetDownloadsPermission` writes the Job's own column when the column holds a value. When the column is NULL, the operation fills the fallback on the state row instead, through `relatedOrNew()`, so NULL keeps its meaning. An adopted Job never runs `InitializeListingJob`, so the operation owns the fallback, and the initializer does not.

```php
final class SetDownloadsPermission implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $job = $successors->root();

        if ($job->are_customer_downloads_enabled === null) {
            $successors->relatedOrNew(ListingCompatibilityState::class)->fill([
                'downloads_enabled_fallback' => (bool) ($value ?? true),
            ]);

            return;
        }

        $job->are_customer_downloads_enabled = (bool) $value;
    }
}
```

`ChangeListingAddress` is a compound change. It stages assignments on related successors, which it references by class. A listing keeps its address, so a NULL address refuses. An address without valid coordinates deletes the JobSite.

```php
final class ChangeListingAddress implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        if ($value === null) {
            throw Refusal::because('A listing keeps its address.');
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
    public function initialize(Successors $successors, Creation $creation): void
    {
        $listing = $creation->record;
        $job = $successors->root();
        $job->name = $listing->title ?: 'Legacy listing '.$listing->uuid;
        $job->opted_in_to_zma = false;
        $job->payment_status = $creation->origin === Origin::Backfill
            ? PaymentStatus::fromOrders($listing->orders)
            : PaymentStatus::Unpaid;
    }
}
```

`InitializeLegacyListing` runs the other way. `Job` carries `Provides\CarriesLegacy`, so from install onward a Job created natively becomes a listing. `Creation::$record` is the new listing. The package allocates `listings.id` from the listing sequence, and sets `job_id`. The class sets the uuid through `Listing::makeUuid()`, and the title. An initializer that omits `uuid` compiles, and `assertServesCompatibility()`'s native create catches it with a NOT NULL violation.

```php
final class InitializeLegacyListing implements InitializesLegacy
{
    public function initialize(Successors $successors, Creation $creation): void
    {
        $creation->record->uuid = Listing::makeUuid();
        $creation->record->title = $successors->root()->name;
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
        return JobWriteTransaction::run(jobs: $successors[Job::class] ?? [], write: $write);
    }
}
```

## Dependent schema

`job_sites_view` reads `listings`. `ListingDependents` drops it in `beforeInstall()` and redefines it over the served listings in `afterInstall()`, inside the install transaction.

The rollback mirrors reverse that, inside the rollback transaction. `beforeRollback()` drops `job_sites_view` before rollback drops the view it reads. `afterRollback()` recreates it over the restored `listings` table, after the refill.

```php
final class ListingDependents implements ManagesDependents
{
    public function beforeInstall(Connection $connection): void
    {
        $connection->statement('DROP VIEW job_sites_view');
    }

    public function afterInstall(Connection $connection): void
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
