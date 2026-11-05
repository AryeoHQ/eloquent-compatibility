Status: Proposed

# Storage

The package owns four kinds of storage: the retained table and its successor key, the view, `compatibility_backfills`, and one `<legacy>_backfill` ledger per legacy model. Successor tables gain no columns ([0018](../decisions/0018-legacy-identity-stays-on-the-legacy-table.md)).

## The retained table

At install, the legacy table takes the name `retainedIn()` gives it, such as `listings_retained`. It keeps its own identity:

- its `id`, its `uuid`, its primary key, its sequence and its integer width,
- every inbound foreign key, which keeps pointing at it, and
- its legacy-only columns.

Install drops only the mapped columns. The package repoints no foreign key and adds no generated column.

No legacy identity moves to a successor, and no two legacy tables share a sequence. A narrow legacy key such as `photos.id integer` therefore keeps its own width and its own sequence.

Before install, Listing's table holds `id integer`, `uuid uuid NOT NULL`, `job_id uuid`, `place_id uuid`, `job_site_id uuid` and `address_id bigint`, among others. `job_id` is the successor key. `place_id`, `job_site_id` and `address_id` are mapped attributes, so install drops them with the other mapped columns. `listings_retained` keeps `id`, `uuid`, `job_id` and the legacy-only columns, such as `title`.

FloorPlan's `floorplans` keeps `id bigint` and `uuid`. `photos.listing_id integer NOT NULL` stays on `photos_retained`, with its foreign key to `listings`.

A shared successor can serve one record of one legacy model only, such as one `PlaceAsset` per media record. The application enforces that, and `compatibility:check` reports a violation. The database does not enforce it.

A retained table holds one root key. The schema leaves room for a retained table with two nullable root keys, if a real split of one legacy model across two roots appears.

## The successor key

Only the root has a successor key: the retained table's column that holds the root's primary key. Its type is the type of the root's primary key.

- **An existing column is adopted.** Listing's `job_id` already references `jobs.id`, so `prepare()` adopts it.
- **A missing column is added.** `floorplans` has no reference to its `PlaceAsset`, so `prepare()` adds `place_asset_id uuid`, and the backfill fills it.

`prepare()` gives the successor key two constraints on the live legacy table:

```sql
ALTER TABLE listings ADD CONSTRAINT listings_job_id_unique UNIQUE (job_id);
ALTER TABLE listings ADD FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE CASCADE NOT VALID;
```

The unique constraint can also come from a unique index built `CONCURRENTLY`. The foreign key starts `NOT VALID`, and install validates it before it takes its lock.

Under its lock, after the final pass, the NULL-key probe and the insert of retained rows for natively created roots, install adds the database's guarantee of a non-NULL key:

```sql
ALTER TABLE listings_retained ADD CONSTRAINT listings_job_id_not_null CHECK (job_id IS NOT NULL) NOT VALID;
```

The check needs no scan, and it checks every later insert and update. Install runs no `SET NOT NULL`, which would scan the whole table under the lock. Prepare does not add the check: a `NOT VALID` check still applies to new rows, and before install the legacy model inserts natively with a NULL key that a pass fills later. Every such insert would fail with `23514`. Declined: a nullable key that relies on the probe alone.

Rollback mirrors install. Before it renames the retained table back, it drops the check, because native legacy inserts with a NULL key resume after a rollback.

`ON DELETE CASCADE` carries a successor delete through the retained row. From there, the legacy schema's own rules govern every inbound key.

## The updated-at index

`prepare()` creates `<legacy>_updated_at_index` on `updated_at` `CONCURRENTLY`, as it does today. Each pass then finds the rows at or after its lower bound without a scan.

```sql
CREATE INDEX CONCURRENTLY IF NOT EXISTS "listings_updated_at_index" ON "listings" ("updated_at");
```

## The position index

Each `position()` attribute needs an index on the legacy table: `(<legacyWithin>, <column>, <key>) WHERE deleted_at IS NULL`. The consumer creates it `CONCURRENTLY` before prepare. `prepare()` refuses without it, and `compatibility:sql prepare` prints the statement.

```sql
CREATE INDEX CONCURRENTLY floorplans_listing_id_index_id ON floorplans (listing_id, "index", id) WHERE deleted_at IS NULL;
```

## The view

At install, the legacy name becomes a view. The view reads from the retained table, joins the root on the successor key, and reaches every related successor through the root's relations ([0027](../decisions/0027-the-view-is-driven-from-the-retained-table.md)).

```sql
CREATE VIEW listings AS
SELECT "listings_retained"."id", "listings_retained"."uuid", "listings_retained"."job_id",
       <mapped columns from jobs, places, job_sites and the state row>,
       <legacy-only columns from listings_retained>
FROM "listings_retained"
JOIN "jobs" ON "jobs"."id" = "listings_retained"."job_id"
LEFT JOIN "places" ON <the Job's relation to its Place>
LEFT JOIN "job_sites" ON <the Job's relation to its JobSite>;
```

- **The root** is an inner join on the successor key.
- **A related successor** is a plain `LEFT JOIN` where the database proves at most one row: a `BelongsTo`, or a `HasOne` over a unique index.
- **A degrade `HasOne`**, which no unique index bounds, is a `LEFT JOIN LATERAL (... LIMIT 1)`. It is the only lateral join.

The view serves an added successor key, such as `floorplans.place_asset_id`, as it serves `job_id`. The legacy model hydrates that column from `prepare()` onward, so its attributes stay the same across install.

The view holds no constant: no discriminator literal and no membership filter. The successor key is the membership, so a retained row with a root key is a served record.

A plain join lets PostgreSQL use the related table's index for a filter on a related attribute. `compatibility:sql --filters` prints, for each attribute, whether a filter runs on a stored column, on a rebuilt expression, or on the view.

The view is not automatically updatable. [Lifecycle](lifecycle.md#the-writer-boundary) states which writers it refuses.

## `compatibility_backfills`

One row per legacy model holds the state of its backfill. `prepare()` creates the table when it does not exist, and creates the legacy model's `<legacy>_backfill`.

```sql
CREATE TABLE compatibility_backfills (
  legacy_type        text PRIMARY KEY,
  watermark_at       timestamptz,
  pass               integer NOT NULL DEFAULT 0,
  pass_started_at    timestamptz,
  pass_finished_at   timestamptz,
  pass_rows          bigint
);
```

| Column | Holds |
| :-- | :-- |
| `legacy_type` | the legacy table's name, as in `<legacy>_backfill`; a renamed model class keeps its watermark |
| `watermark_at` | the database time at which the last completed pass started; NULL before the first pass completes |
| `pass` | the number of the last pass that started |
| `pass_started_at` | when that pass started |
| `pass_finished_at` | when that pass finished; NULL while it runs |
| `pass_rows` | how many rows that pass walked |

## `<legacy>_backfill`

One ledger table per legacy model holds one row per legacy row the backfill walked.

```sql
CREATE TABLE listings_backfill (
  legacy_id     integer PRIMARY KEY,
  successor_id  uuid,
  status        text NOT NULL,
  reason        text,
  attempts      integer NOT NULL DEFAULT 0,
  attempted_at  timestamptz NOT NULL
);

CREATE INDEX ON listings_backfill (status) WHERE status IN ('refused', 'failed');
```

| Column | Holds |
| :-- | :-- |
| `legacy_id` | the legacy row's key, typed as the legacy key; no foreign key to the legacy table, so the row outlives a deleted legacy row |
| `successor_id` | the root successor last written, typed as the root's key |
| `status` | `created`, `updated`, `unchanged`, `refused`, `failed` or `deleted` |
| `reason` | the refusal's reason, or the error of a failed row |
| `attempts` | how many passes attempted the row |
| `attempted_at` | when a pass last wrote the row |

The partial index lets every pass find the `refused` and `failed` rows without a scan. The table's name obeys the identifier limit ([0009](../decisions/0009-map-names-fit-the-object-name-limit.md)).

When install finishes, the package deletes the legacy model's row from `compatibility_backfills` and drops `<legacy>_backfill`. It never drops `compatibility_backfills`. The table holds one row per legacy model, so dropping it would erase the watermarks of models not yet installed. Each legacy model installs independently, and that rule needs those watermarks. `rollback()` needs neither, because it refills the legacy table from the successors through the successor key.
