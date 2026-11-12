Status: Accepted

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

`prepare()` gives the successor key one constraint on the live legacy table:

```sql
ALTER TABLE listings ADD CONSTRAINT listings_job_id_unique UNIQUE (job_id);
```

The unique constraint can also come from a unique index built `CONCURRENTLY`.

The successor key is an identity. It names the root a legacy row maps to, and before install that root may not exist yet. A key that names no row means "no successor yet". The backfill then takes the create path, and creates the root with that key value, so the identity holds ([Lifecycle: a pass](lifecycle.md#a-pass)). Install's existence proof checks that every non-NULL key names an existing root row, whatever the legacy row's ledger state. Every test of whether a successor key names a root reads the root's table without global scopes, so a trashed root counts as existing. A test through a model with `SoftDeletes` would miss a trashed root, and the create path would then fail with `23505`. Install catches up each key the proof flags through the create path, which creates the root with the key value, and then proves that key again ([Lifecycle: the install gate](lifecycle.md#the-install-gate)).

Under its lock, after the final pass, the NULL-key probe and the insert of retained rows for natively created roots, install adds the database's guarantee of a non-NULL key:

```sql
ALTER TABLE listings_retained ADD CONSTRAINT listings_retained_job_id_not_null CHECK (job_id IS NOT NULL) NOT VALID;
```

Install's last DDL statement under the lock, after `ManagesDependents::afterInstall()`, adds the foreign key to the root:

```sql
ALTER TABLE listings_retained ADD CONSTRAINT listings_retained_job_id_foreign FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE CASCADE NOT VALID;
```

Each constraint takes the name `<retained table>_<key>_not_null` or `<retained table>_<key>_foreign`, as Laravel names a foreign key on the table that carries it. Rollback drops both by name.

Neither constraint needs a scan, and both check every later insert and update. Install runs no `SET NOT NULL` and no `VALIDATE CONSTRAINT`, because each would scan the whole table under the lock. The existence proof before the lock, with install's catch-up of the keys it flags, stands in for the validation, and [the writer boundary](lifecycle.md#the-writer-boundary) states the window it leaves.

Adding the foreign key takes a `SHARE ROW EXCLUSIVE` lock on the root's table, which blocks writes to it until install commits. It is the last DDL statement. Install then holds the root's table for one read of the member roots that committed while it waited, their retained-row inserts, and the commit ([Lifecycle: under the lock](lifecycle.md#under-the-lock), step 11). Each insert runs `legacyInitializedBy()`. The read filters on the root's `created_at`, so it scans the root's table when no index leads with that column. `compatibility:sql install` prints that lock.

The lock also waits. It conflicts with the `ROW EXCLUSIVE` lock that every open transaction writing the root's table holds, so install waits for the longest of them while it still holds the legacy table. During that wait, every read of the legacy name blocks, and every later writer of the root's table queues behind install's request. A writer that holds the root's table and then waits on the legacy table deadlocks with install. A native create whose `Provides\CarriesLegacy` lock follows the successor's `INSERT` is one such writer. PostgreSQL then aborts one side with `40P01`. Install therefore runs the statement under `SET LOCAL lock_timeout`, and treats a lock timeout or a `40P01` as a lock it cannot take: it rolls back and retries. A `40P01` retries only outside a caller's transaction ([Lifecycle: inside a caller's transaction](lifecycle.md#inside-a-callers-transaction)). For a shared successor such as `place_assets`, the other legacy models' writes pause for the statement, its wait, step 11's read and inserts, and the commit.

Prepare adds neither constraint. A `NOT VALID` constraint still applies to new rows. Before install, the legacy model inserts natively with a NULL key that a pass fills later, and with a key whose root the backfill creates later. The check would fail every NULL-key insert with `23514`, and the foreign key would fail every insert whose root does not exist yet with `23503`. A foreign key at prepare would also cascade a successor delete into the live legacy row, which is still the source of truth. Placing the foreign key at install removes that cascade.

Declined: a nullable key that relies on the probe alone. Declined: requiring a backfill, or the creation of every physical Job, before `prepare()`. Declined: keeping the foreign key at prepare as `NO ACTION`, which still rejects the same inserts ([0018](../decisions/0018-legacy-identity-stays-on-the-legacy-table.md)).

Rollback mirrors install. As its last statement, it drops the check and the foreign key, because the drop locks the root's table against every read until rollback commits. Native legacy inserts with a NULL key, or with a key whose root does not exist yet, resume after a rollback.

From install onward, `ON DELETE CASCADE` carries a successor delete through the retained row. From there, the legacy schema's own rules govern every inbound key. Before install, a successor delete leaves the legacy row in place. Its key then names no row, and the backfill treats the row as having no successor yet. The delete leaves the legacy row's `updated_at` unchanged, so no incremental pass walks the row. Install's existence proof flags its key, and install's catch-up reaches the row and creates the root with the key value.

## The updated-at index

`prepare()` creates `<legacy>_updated_at_index` on `updated_at` `CONCURRENTLY`, as it does today. Each pass then finds the rows at or after its lower bound without a scan.

```sql
CREATE INDEX CONCURRENTLY IF NOT EXISTS "listings_updated_at_index" ON "listings" ("updated_at");
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
- **A related successor** is a plain `LEFT JOIN` where a unique index on its join key proves at most one row, and a lateral join otherwise. The join key is a `BelongsTo`'s owner key or a `HasOne`'s foreign key.
- **The proof** is a valid, immediate unique index on the related table whose one key column is the join key, with no expression and with the column's own collation. The index has no predicate, or it is the partial index `prepare()` builds for `atMostOne()`. That partial index proves a relation only while its definition equals the one `prepare()` builds for the map's current conditions. A `BelongsTo` to a primary key always has a proof. A `BelongsTo` to an owner key that no such index bounds stays lateral, because the database proves nothing about it.
- **A relation's own conditions** sit in a select of their own, `LEFT JOIN (SELECT * FROM "places" WHERE ...) AS "places" ON ...`. A condition names its column without the table, so a legacy column of the same name would otherwise make it ambiguous. PostgreSQL still pulls that select into the join, so the related table's index serves a filter. That select cannot see the parent table. A relation therefore stays lateral unless each of its conditions is a plain predicate on a column of its own table.
- **A lateral join** is `LEFT JOIN LATERAL (... LIMIT 1)`. The view uses it only for a relation that no index proves.
- **A morph target** always joins plainly, on its alias and its key.

`install()`, `update()` and the refill of `rollback()` each refuse while an `atMostOne()` index differs from the index `prepare()` builds for the map's current conditions. The refusal names the index, and says to drop it and run `prepare()`. A migration that drops or replaces an index the proof relies on calls `Compatibility::update()` in the same migration. Until then, the installed view keeps the join it compiled. `compatibility:check` reports the stale view, and names each relation whose join changes between plain and lateral.

The view serves an added successor key, such as `floorplans.place_asset_id`, as it serves `job_id`. The legacy model hydrates that column from `prepare()` onward, so its attributes stay the same across install.

The view holds no constant: no discriminator literal and no membership filter. The successor key is the membership, so a retained row with a root key is a served record.

A plain join lets PostgreSQL use the related table's index for a filter on a related attribute. That holds only while the successor column keeps the legacy column's type, typmod and collation, and the attribute declares no `orFrom()`. Otherwise the view's cast or its `COALESCE` hides the column from the index.

`compatibility:sql --filters` prints, for each attribute, whether a filter runs on a stored column, on a rebuilt expression, or on the view. A stored-column filter falls back to the view where its conversion cannot invert the operator, and the command says so. A `readAs()`, `fixed()` or `orFrom()` attribute filters on the view. For an attribute of a related successor, the command names the lateral join that reaches it, or the parent columns that the plain joins traverse. It then names the indexes that can serve the filter, except where the view casts the column or reads an `orFrom()` fallback. Before `prepare()`, it counts an `atMostOne()` relation as joined plainly, because `install()` requires its index. A `computedAs()` attribute has no SQL filter, and the command says so.

Two package reads keep a lateral join for every relation except a morph target, because they read a few rows by key: the native create's stand-in query, which replaces the written successor's table with a row set that has no unique index, and the batch read of `computedAs()` values.

The view is not automatically updatable. [Lifecycle](lifecycle.md#the-writer-boundary) states which writers it refuses.

## `compatibility_backfills`

One row per legacy model holds the state of its backfill. `prepare()` creates the table when it does not exist, and inserts the legacy model's row when none exists. Install deletes that row, so a legacy table with its ledger and no row reads as `rolled back` in `compatibility:status`. `prepare()` creates the legacy model's `<legacy>_backfill` when that table does not exist, and otherwise reuses it.

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
CREATE INDEX ON listings_backfill (successor_id) WHERE status = 'deleted';
```

| Column | Holds |
| :-- | :-- |
| `legacy_id` | the legacy row's key, typed as the legacy key; no foreign key to the legacy table, so the row outlives a deleted legacy row |
| `successor_id` | the root successor last written, typed as the root's key |
| `status` | `created`, `updated`, `unchanged`, `refused`, `failed` or `deleted` |
| `reason` | the refusal's reason, without the record's key and values for a package refusal, or the exception class and first message line of a failed row |
| `attempts` | how many passes attempted the row |
| `attempted_at` | when a pass last wrote the row |

The first partial index lets every pass find the `refused` and `failed` rows without a scan. The second lets install's native-root read exclude the roots a `deleted` row names without a scan. The table's name obeys the identifier limit ([0009](../decisions/0009-map-names-fit-the-object-name-limit.md)).

When install finishes, the package deletes the legacy model's row from `compatibility_backfills`. It never drops `compatibility_backfills`. The table holds one row per legacy model, so dropping it would erase the watermarks of models not yet installed. Each legacy model installs independently, and that rule needs those watermarks.

Install keeps the whole `<legacy>_backfill`, and deletes no ledger row. The `deleted` rows outlive install, a rollback and a re-install. `retire()` drops the table and deletes the model's `compatibility_backfills` row, because retire rules out a re-install. `prepare()` reuses the table on a re-install, so install still excludes the roots a pass trashed from its native-root inserts ([Lifecycle: re-install after rollback](lifecycle.md#re-install-after-rollback)). Declined: dropping `<legacy>_backfill` at install, which lets a re-install revive records whose legacy rows were hard-deleted. Declined: deleting the other rows at install, which writes nearly one row per legacy row inside install's transaction.

`rollback()` needs neither table, because it refills the legacy table from the successors through the successor key.
