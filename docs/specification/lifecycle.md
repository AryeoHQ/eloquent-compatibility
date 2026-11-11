Status: Accepted

# Lifecycle

Each legacy model moves through the same stages, independently of every other legacy model. That holds even when two legacy models share a successor.

| Stage | Operation | What changes |
| :-- | :-- | :-- |
| discovered | none | the map compiles, and nothing exists in the database |
| prepared | `Compatibility::prepare()` | the successor key gains its unique constraint |
| backfilling | `compatibility:backfill`, one run per pass | every legacy row gains its successors, and each pass catches up |
| installed | `Compatibility::install()` | the legacy name becomes a view over the retained table and its successors |
| rolled back | `Compatibility::rollback()` | the legacy table returns, and is the source of truth again |
| retired | `Compatibility::retire()` | the view and the retained table are gone, and the successors remain |

## The source of truth

The legacy model is the source of truth until install. The successor is the source of truth from install onward ([0019](../decisions/0019-legacy-is-the-source-of-truth-until-install.md)).

- Before install, catch-up overwrites mapped successor columns with the legacy values. That includes a native edit to a mapped column.
- Catch-up skips a write when the successor already serves the legacy value equivalently. A Job with a NULL permission override and a fallback that serves the same boolean is left alone.
- The legacy record never owns a successor-only column. The initializers and the successor's own code own it.
- The package detects no conflicts and records no provenance.

This is documented, not enforced, because Aryeo's v2 writes Jobs live before install. `compatibility:check` reports each served value that disagrees with its legacy value, which shows what the next pass would overwrite. It compares no attribute that [Operations: what the comparisons skip](operations.md#what-the-comparisons-skip) names, so an empty report says nothing about those attributes.

`compatibility:check` also reports a served record whose root no longer matches its map's `when()`. The package evaluates `when()` only when it creates a legacy row, so the successor key keeps that record bound to its root ([The map: membership](the-map.md#membership)).

After install, `compatibility:check` reports each member root that no retained row names. A query-builder insert into the root table bypasses `Provides\CarriesLegacy`, so the view serves no record for that root ([The writer boundary](#the-writer-boundary)). The check excludes a root that a `deleted` ledger row names, because its legacy row is gone, and a pass recorded the deletion.

## Prepare

`Compatibility::prepare()` runs on the live legacy table, before the first pass:

- It adopts the successor key column `servedFrom(key:)` names, or adds it when the column does not exist.
- It adds a unique constraint on the successor key, and no other constraint. The key is an identity that may name no root yet, and install adds the rest under its lock ([Storage: the successor key](storage.md#the-successor-key)).
- It creates `<legacy>_updated_at_index` on `updated_at` `CONCURRENTLY`, as it does today, so a pass finds the rows at or after its lower bound without a scan. A served legacy table therefore needs an `updated_at` column that its legacy model writes. Install refuses a table without one before any write, because its catch-up under the lock would otherwise walk every legacy row.
- It lists every object that depends on the legacy table.
- It creates `compatibility_backfills` when the table does not exist, and inserts this legacy model's row when none exists. It creates this legacy model's `<legacy>_backfill` when that table does not exist, and otherwise reuses the one an earlier install kept. `compatibility:sql prepare` therefore prints every statement the package runs before install.

It allocates nothing on the successors. Running it again changes nothing. [Storage](storage.md#the-successor-key) shows its statements.

## The backfill

`compatibility:backfill {model}` runs one pass. A pass reads the legacy rows by key, in chunks, and writes each row in its own savepoint ([0023](../decisions/0023-the-backfill-records-an-outcome-per-row.md)). A map without `writesWithin()` writes each chunk in one transaction. A map that declares `writesWithin()` commits each row in its own transaction, so the chunk is only a read batch. Each row then runs its write scope, the package's locks, its write and its ledger row, and commits. A savepoint's release keeps its row locks, so in one chunk transaction a row's scope would run while the package still held the locks of the rows before it.

### A pass

1. The pass takes a session-level advisory lock, `pg_try_advisory_lock(hashtextextended('compatibility_backfills:<legacy>', 0))`. It holds the lock for the whole pass, across its transactions, and releases it when the pass ends. When another pass or an install holds the lock, the pass refuses with a named error, and does not wait. The error names the lock, so `pg_locks` finds the session that holds it.
2. The pass records its start, read from the database clock.
3. The first pass walks every row. Each later pass walks:
   - rows whose `updated_at` is at or after the watermark minus the margin,
   - rows whose `updated_at` is NULL,
   - rows whose ledger status is `refused` or `failed`.

   It reads these keys one chunk at a time, in key order, so a pass holds one chunk of keys in memory. Before it counts the pass, a later pass refuses a legacy table without an `updated_at` column its legacy model writes.
4. Each row is written in its own savepoint, through the operations, and its ledger row is upserted. Inside the savepoint, the create path locks the legacy row `FOR UPDATE`, then re-reads its successor key before it creates. When the key names an existing root, the row takes the update path. The test reads the root's table without global scopes, so a trashed root exists. When the key is NULL, the create path creates a root and fills the key. When the key names no row, the create path creates the root with that key value, so the identity holds ([Storage: the successor key](storage.md#the-successor-key)).
5. The pass handles deletions: ledger rows whose status is not `deleted` and whose legacy row is gone.
6. The pass sets the watermark to its recorded start.

A failure rolls back that row only, and the chunk continues. A row that waits past a lock timeout (`55P03`) or deadlocks (`40P01`) is not recorded as `failed`. The pass retries it once. When the retry waits too, the pass ends without moving its watermark, so the next pass walks the row again.

Before it compares a walked row, the update path reads the root, its successors and the served values without a lock. It locks the root and its successors only when a value differs, and then compares again. An unchanged row therefore takes no row lock.

`compatibility:backfill` prints one line per chunk, with its first and last key and its counts by status.

### The watermark and its margin

The watermark is a timestamp and nothing else: the database time at which the last completed pass started. It holds no key. A pass that does not complete leaves the watermark where it was.

The margin is subtracted from each pass's lower bound. It is a named argument of each operation, and the package has no configuration of its own: `compatibility:backfill --margin=` takes seconds, and `Compatibility::install()` takes `margin:`. Both default to 5 minutes. The pass size is `install()`'s `passSize:` alone, because it only sets install's stop condition, and `compatibility:backfill` runs one pass. The margin must exceed the clock skew between the application servers, which stamp `updated_at`, and the database, which stamps the pass start, plus the longest legacy write transaction. A write stamps `updated_at` when it runs, but a pass sees the row only after its transaction commits.

### Per-row outcomes

The ledger records one outcome per legacy row:

| Status | Meaning |
| :-- | :-- |
| `created` | the pass created the row's successors |
| `updated` | the pass changed at least one successor |
| `unchanged` | every successor already served the legacy values |
| `refused` | an operation or initializer refused the row, or the package refused the write with a `CompatibilityWriteRefused`, with its reason |
| `failed` | the row's write raised another error, recorded as the reason |
| `deleted` | the legacy row is gone, and the pass deleted its successor |

A `failed` row's reason is the exception's class and the first line of its message. For a database error, that line is the driver's, which names the SQLSTATE and the constraint and carries neither the bindings nor the connection. The package writes the whole exception to the Laravel log, with the legacy key. A refusal's reason is its message. The ledger stores every reason as valid UTF-8 without NUL bytes.

`compatibility:backfill` prints the counts by status. They cover the walked rows and the ledger rows whose legacy row is gone, and the command prints both totals. It prints each reason with its count and its first keys, ten reasons at most, and the count of the others. It prints a reason as text: its console markup stays literal, and each control character prints as `\xNN`. It exits non-zero when any row is `refused` or `failed`, and it names `<legacy>_backfill`. Every command it prints for the operator quotes the model's class.

### Retries

Every pass retries the `refused` and `failed` rows, whatever the watermark. Each attempt increments the row's `attempts`.

### Deletions

A ledger row whose legacy row is gone, and whose status is not `deleted` yet, names a deletion. A pass never selects a `deleted` row again. The pass deletes that row's successor through its model, and marks the row `deleted`. When a foreign key refuses the delete, the pass marks the row `failed`.

One rule covers every origin. The pass deletes the successor of a deleted legacy row, whether the backfill created it or not. A soft delete keeps it recoverable. A foreign key, and the per-row outcome, refuse loudly when native history depends on it.

The pass calls the successor model's `delete()`. For a model that uses `SoftDeletes`, that is a soft delete: it fires `deleting` and `deleted`, keeps the rows that depend on the successor, and stays recoverable. A legacy delete after install calls `forceDelete()` instead ([Operations: Eloquent-managed columns](operations.md#eloquent-managed-columns)).

The two paths differ because of when they run. The installed delete runs synchronously inside the caller's write, so a foreign key from native history refuses in front of the caller. A pass finds the deletion later, when native history may have attached to the successor, and no caller is present.

The trashed roots a pass leaves stay excluded from install's native-root inserts, because a `deleted` ledger row names each one ([Install: under the lock](#under-the-lock)). Purging them is the consumer's job.

### Changes outside the legacy row

A pass walks only the rows above. A walked row whose legacy values are unchanged runs no operation, except the `writtenBy()` operation of a `computedAs()` attribute, which runs on every walked row ([Operations: computed attributes under the backfill](operations.md#computed-attributes-under-the-backfill)). A served value can still change through another table, as when an Address edit changes the coordinates a JobSite copies. That copy between successor tables is the consumer's own consistency rule, and it must hold after install too. The package runs nothing for it.

Declined: a declared change source that reports rows another table changed, so that a pass re-runs their operations without the equality skip.

Creating and linking related records is the consumer's work too. Examples are setting `orders.job_id` from the listing, memberships derived from sharing, and PlaceEngagements derived from ShowingTime+ requests. The consumer writes each as a set-based backfill keyed on the related table, and makes it idempotent. It re-runs that backfill after the package's passes create the roots, and again before install. The package's passes never run it, because they walk the legacy row's changes. The consumer gates its install migration on its own readiness check. `successorsInitializedBy()` keeps only the per-root values a successor needs at birth.

Declined: related-record work in `successorsInitializedBy()`. It runs once at a root's birth, so it misses existing roots and later related rows. It also writes set-sized work row by row under the package's locks.

### Positions

`position()` lands in M5b, and this release does not ship it ([Status](README.md#status)).

A pass re-sequences a `position()` attribute's ordering parent on each backfill row and each catch-up row. The re-sequence runs in the default operation's `afterSave()` action, at step 5 of the unit of work, when the successor's eloquent-ordering parent is set. Its members are the parent's live legacy rows whose successors are current members: not trashed, and with a `rank_key`. It walks them in legacy order, the value with NULLs last, then the key. It moves each member that does not already follow its predecessor to just after it, so one sweep ends in legacy order. Native members keep their places. A trashed row is not placed, and its `position()` operation does nothing. [Operations: positions](operations.md#positions) states the re-sequence.

### Trashed rows

Trashed legacy rows follow the same rules as live ones, with one exception: a `position()` operation does nothing on a trashed record. The round trip and install's value proof therefore skip a `position()` attribute on a trashed row. `compatibility:check` reports trashed rows separately.

### A timeline

```text
10:00  Pass 1 records watermark_at = 10:00 from the database clock.
       It walks every row by key, one savepoint each.
14:00  Pass 2 walks rows with updated_at >= 10:00 minus the margin, rows with NULL updated_at,
       refused and failed rows, and ledger rows not yet deleted whose legacy row is gone.
       It records watermark_at = 14:00.
14:05  Install runs passes until one changes the outcome of fewer rows than passSize.
       A pass that changes no row always stops the loop. Install then proves
       existence and values before its lock. Under the lock it runs the final small pass, the
       NULL-key probe, the rename, the drop of mapped columns, the native-root inserts, the
       check, the view, the dependent hooks and, last, the foreign key.
```

## The install gate

Install refuses while any row of this legacy model is `refused` or `failed`. The refusal names the reasons and the first keys of each. Before it takes its lock, install also proves two things:

- **Existence.** Every legacy row has a ledger row or a successor key. Every non-NULL successor key names an existing root row, whatever the row's ledger state. The proof reads the root's table without global scopes, so a trashed root exists. The ledger gate refuses `refused` and `failed` rows on its own. When the proof still fails after install's catch-up, the refusal names the first keys.
- **Values.** The drift check's value comparison finds no difference. For a `position()` attribute, the proof compares order, not ranks. Before `lag()` runs, it filters the rows to the current members: live legacy rows whose successors are not trashed and hold a `rank_key`. It partitions those rows by the successor's eloquent-ordering parent. Within one parent, each row's successor `rank_key` must exceed its previous member's, through `lag()` over the legacy order. The proof never compares `rank_key` across two parents. It compares no attribute that [Operations: what the comparisons skip](operations.md#what-the-comparisons-skip) names.

The proofs read only, and write no ledger row. When either proof flags keys, install catches up those keys and proves them again. It refuses only when they still fail. For a key the existence proof flags, the catch-up takes the create path, and creates the root with the key value, so the identity holds ([Storage: the successor key](storage.md#the-successor-key)). For a key the order proof flags, the catch-up re-sequences that key's whole parent, so one catch-up puts the parent in legacy order ([Operations: positions](operations.md#positions)). A row whose write left `updated_at` alone is therefore caught up before the lock. So is a row whose root was hard-deleted, which no incremental pass walks while its `updated_at` stays unchanged. A ledger row is `failed` only when its write raised an error. Each legacy model passes its own gate, even on a shared successor.

The package has no partial install. A legacy row without a successor cannot be served, so install waits until every row has one. Unblocking is the consumer's work: complete the prerequisite, widen the successor to represent the state, retire rows, split the legacy table, or order the installs.

## Install

Install runs in one transaction ([0024](../decisions/0024-install-is-one-transaction.md)).

### Before the lock

1. Install refuses before any work when an object depends on the legacy table and the map declares no `dependents()`. It also refuses when `<legacy>_backfill` or the model's `compatibility_backfills` row is missing, and the refusal names `prepare()`.
2. It takes the advisory lock that every pass takes, and holds it until install ends. Install's own passes run under it, and any other pass of this model refuses. Before any write, install refuses a legacy table whose rows with a NULL `updated_at` reach its `passSize:`. Every pass walks those rows, the final pass under the lock included, so install names the count and the remedy: stamp `updated_at` on those rows. It also refuses a root model that stamps no `created_at` column, because the native-root window under the lock reads that column ([Under the lock](#under-the-lock), step 6). When no index leads with the root's `created_at`, install writes a warning to the Laravel log, because that read then scans the root's table under the lock. Install does not refuse then. Create the index before install, with `CREATE INDEX CONCURRENTLY`.
3. When no pass has completed, so the model's watermark is NULL, it runs one full pass first. That holds for a model that was never backfilled, for an empty ledger, and for a re-install after a rollback. A first install therefore never drops a mapped value that no pass has written, and a re-install keeps the legacy edits made after the rollback. Before that pass, install refuses a legacy table without an `updated_at` column its legacy model writes, so the refusal comes before any write. An installed model runs no pass.
4. It runs passes until one changes the outcome of fewer rows than its `passSize:` argument, 1,000 by default. The count covers the rows whose successors the pass created, updated or deleted, and the rows whose ledger status changed. It does not count the rows the pass walked and left as they were. Every pass still walks the rows whose `updated_at` is NULL, and the `refused` and `failed` rows. A pass that changes no row always stops the loop, and install proceeds to its gate, which refuses on `refused` or `failed` rows and names them. When five passes in a row each still change the outcome of `passSize:` rows or more, install refuses, and names `compatibility:backfill`, because live writes outpace its catch-up. Install writes one line to the Laravel log per pass, with its walked, gone, changed, refused and failed counts.
5. It records the start time of its read of native roots. It then reads the keys of member roots that no legacy row's successor key matches, through the indexed anti-join on the successor key. The read excludes every root that a `deleted` ledger row names in `successor_id`. It writes nothing for these roots, and it reports their count.
6. It proves the install gate: the ledger, existence and values. It catches up the keys the existence proof and the value proof flag, proves those keys again, and refuses only when they still fail. It logs the count of flagged keys.
7. It handles the deletions one last time, as a pass does, and refuses on a `refused` or `failed` row again.

These whole-table proofs and the deletion read never run under the lock. A legacy row hard-deleted after that last deletion read leaves its root live, and the view serves no record for it. `compatibility:check` reports that root as an unserved root after install ([The source of truth](#the-source-of-truth)).

### Under the lock

1. The final small catch-up pass. It calls no write scope and runs no key re-check, because the table lock serializes it against live writes ([the write scope](operations.md#the-write-scope)). It handles no deletions. When the pass refuses a row, or a row's write fails, install rolls back and refuses, naming each reason and the first keys. The gate refused such rows before the lock. A row that waits past the lock timeout, deadlocks or is cancelled is not a row failure: install rolls back, and retries or reports the spent budget as it does for every statement under the lock.
2. One indexed probe for a legacy row with a NULL successor key. Install refuses when the probe finds one.
3. `ManagesDependents::beforeInstall()`.
4. The rename of the legacy table to the retained table.
5. The drop of the mapped columns.
6. The insert of one retained row per natively created member root. The set holds each key the pre-lock read found, plus every member root whose `created_at` is at or after that read's start time minus the margin. Install anti-joins both against the retained table's successor key, so a root the final pass just created is excluded. It also excludes every root that a `deleted` ledger row names in `successor_id`. Install runs `legacyInitializedBy()` per row, with `Origin::Backfill`. It catches nothing from an initializer under the lock. A `Refusal` therefore aborts install, and the refusal names the root's key and the reason ([Operations: native successors before install](operations.md#native-successors-before-install)).
7. The addition of `CHECK (<key> IS NOT NULL) NOT VALID` on the successor key. It needs no scan, and it checks every later insert and update ([Storage](storage.md#the-successor-key)).
8. The creation of the view.
9. `ManagesDependents::afterInstall()`.
10. The addition of the successor key's foreign key to the root's primary key, `ON DELETE CASCADE NOT VALID`, as install's last statement. It needs no scan, and it checks every later insert and update. It takes a `SHARE ROW EXCLUSIVE` lock on the root's table, and runs under `SET LOCAL lock_timeout`, as install's table lock does. The lock waits for every open transaction that writes the root's table, and every later writer of that table queues behind it. A writer that holds the root's table and then waits on the legacy table deadlocks with install, and PostgreSQL aborts one side with `40P01`. Install treats a lock timeout or a `40P01` on this statement as a lock it cannot take: it rolls back and retries ([Storage](storage.md#the-successor-key)).

Install's required `statementTimeout:` argument sets `statement_timeout` for every statement under install's lock. It is also the total budget for the lock hold. Before every step under the lock, before each row of the final pass, and before every native-root insert, install checks the time elapsed since it took the lock. Once the budget is spent, install rolls back and refuses, and the refusal names the step it reached. The argument therefore bounds the hold. It has no default, because the hold budget depends on each application's tolerance. `compatibility:sql install` prints each lock, with its mode and table. Install keeps its own retries when it cannot take a lock, and writes one line to the Laravel log for each retry.

Declined: a per-statement timeout only. Nothing would then bound the hold, and PostgreSQL 14 has no `transaction_timeout`.

`Provides\CarriesLegacy` inserts no legacy row before install, so a natively created root has none until install inserts one under its lock. The trait takes an `ACCESS SHARE` lock on each legacy table whose `when()` the new root matches, before it checks whether that model is installed. A native create therefore waits for the `ACCESS EXCLUSIVE` lock of an install it would join, and afterward it sees the installed state. No pass walks that row, and install's pause grows with the number of such roots. After install, the trait inserts the legacy row on create. Legacy readers do not see a v2-created listing until install ([Operations: native successors before install](operations.md#native-successors-before-install)).

When install finishes, the package deletes this legacy model's row from `compatibility_backfills`. It keeps `compatibility_backfills` itself, because the table holds the watermarks of other legacy models. Dropping it would erase them, and those models could no longer install independently. Install keeps the whole `<legacy>_backfill`, and deletes no ledger row. Its `deleted` rows name the roots a pass trashed, so a re-install after a rollback still excludes them from its native-root inserts. `retire()` drops the ledger.

Declined: dropping `<legacy>_backfill` at install. A re-install would then insert a retained row for every root a pass trashed, and revive records whose legacy rows were hard-deleted. Declined: deleting the rows other than `deleted` ones at install. That delete writes nearly one row per legacy row inside install's transaction, and a re-install's first pass upserts every row anyway.

### Positions after install

After install, a `position()` attribute reads the successor's position, not the stored legacy value. The order of the rows within each eloquent-ordering parent does not change, but three kinds of value do:

- A row whose legacy value was NULL reads a number.
- Sparse values close up: legacy values 1, 4 and 7 read 1, 2 and 3.
- Positions restart in each parent. A listing whose photos span two subsections reads one sequence per subsection.

A legacy `orderBy('index')` across two parents therefore returns the positions 1, 1, 2 and 2, because it sorts by the position expression ([The map: reads are projections](the-map.md#reads-are-projections)).

Aryeo's `photos` holds live rows with a NULL `index`, so those photos read a number after install.

A trashed row reads a NULL `index` after install, because a trashed record has no position.

### Inside a caller's transaction

`Compatibility::install()` may run inside a caller's transaction when no catch-up is pending. A first install inside a caller's transaction runs its full pass inside that transaction, through nested savepoints. A test that uses `RefreshDatabase` can therefore install a served model. Install still refuses a caller's transaction once a pass has left a watermark, because its catch-up passes commit in transactions of their own before it takes the lock.

Inside a caller's transaction, a refusal loses nothing. The proofs write no ledger row, and the next install's existence proof and value proof flag the same keys again.

### Dependent schema

`prepare()`, `install()`, `rollback()` and `compatibility:sql` list every object that depends on the legacy table: views, materialized views, rules, policies and SQL functions. The operations write the list to the Laravel log, and `compatibility:sql` prints it in its header.

`dependents()` names a `Contracts\ManagesDependents` class. Its `beforeInstall()` and `afterInstall()` run inside the install transaction. Its `beforeRollback()` and `afterRollback()` run inside the rollback. The package does not rebind dependent views itself.

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

## Rollback

`Compatibility::rollback()` is the emergency reversal, and it replaces `uninstall()` ([0025](../decisions/0025-rollback-and-retire-are-separate-operations.md)). It runs these steps in one transaction:

1. It runs `ManagesDependents::beforeRollback()`, which removes every object that depends on the view.
2. It drops the view.
3. It renames the retained table back to the legacy name.
4. It restores the mapped columns, and refills them from the successors through the successor key. The refill calls no write scope and runs no key re-check. Rollback's table lock waits for in-flight writes to commit, so the refill reads only committed successors.
5. It carries the view's grants back to the restored table.
6. It runs `ManagesDependents::afterRollback()`, which recreates those objects over the restored table.
7. It drops the successor key's foreign key and `CHECK (<key> IS NOT NULL)`, as its last statement. Native legacy inserts with a NULL key, or with a key whose root does not exist yet, resume after a rollback ([Storage](storage.md#the-successor-key)).

Under its table lock, before the first step, rollback refuses when an `atMostOne()` index differs from the one `prepare()` builds for the current map, because the refill joins that relation plainly ([Storage](storage.md#the-view)).

Dropping the foreign key takes an `ACCESS EXCLUSIVE` lock on the root's table, which blocks every read of the root until rollback commits. Rollback therefore drops it last, under `SET LOCAL lock_timeout`. It treats a lock timeout or a `40P01` on that statement as install does: it rolls back and retries. No earlier step needs the constraints gone, because the rename keeps them and the refill does not write the key.

`beforeRollback()` runs before the drop, because PostgreSQL refuses to drop a view that another object depends on (`2BP01`). Listing's `job_sites_view` depends on the view after `afterInstall()` recreates it.

The legacy model is then the source of truth again.

### Re-install after rollback

Install deleted this model's `compatibility_backfills` row. A re-install therefore runs `prepare()` again, which inserts that row with no watermark. `prepare()` is idempotent, and it reuses the ledger. With no watermark, install runs one full pass before its catch-up, whether or not `compatibility:backfill` ran since the rollback, so it keeps every legacy edit made after the rollback. Install refuses when the ledger is missing, and the refusal names `prepare()`.

## Retire

`Compatibility::retire()` deprecates a legacy model that has no readers left:

- It drops the view and the retained table.
- It drops `<legacy>_backfill`, and deletes the model's row from `compatibility_backfills` when one remains. Retire rules out the re-install that the ledger's `deleted` rows serve ([Re-install after rollback](#re-install-after-rollback)).
- It leaves every successor untouched.
- It refuses while any object depends on the view or the retained table, and lists each one. An inbound foreign key is one such object.
- `compatibility:sql retire` prints the legacy-only data it would discard, and lists `<legacy>_backfill` and the model's `compatibility_backfills` row among that data. `retire()` runs in a migration, where nobody can confirm, so it asks nothing. It writes the same list to the Laravel log.
- A second `retire()` changes nothing.

## Status and idempotence

`compatibility:status` prints each legacy model's stage: one of the six stages in the table at the top of this page, read from the catalog and the ledger. `prepare()` inserts the model's `compatibility_backfills` row, and install deletes it. A legacy table with its `<legacy>_backfill` but no `compatibility_backfills` row therefore reads as `rolled back`. Two states match none of the six stages, and status prints a refusal for that model instead of a stage:

- **A catalog that matches no stage,** such as a legacy table beside its retained table. A schema change outside the package caused it, and the refusal names both tables.
- **A `compatibility_backfills` row without the successor key's unique index.** `prepare()` failed, or it is still building `<legacy>_<key>_unique`. Status runs the check that install runs: a valid unique index of its own on the successor key. The refusal says that `prepare()` has not finished, and names the index.

Status still prints the stage of every other model, and it exits non-zero when it refused any model. A model that is still backfilling also shows its pass count, its watermark, and its counts of refused and failed rows. `prepare()`, `install()`, `rollback()` and `retire()` are idempotent: a second run changes nothing. The package has no public `Compatibility::state()`.

## The writer boundary

The package documents this boundary and does not enforce it.

| Writer | Before install | After install |
| :-- | :-- | :-- |
| Eloquent through the legacy model, from the release that ships the trait | writes the legacy table | the package runs the operations |
| `Model::query()->toBase()` writes | writes the legacy table; a later incremental pass is guaranteed to select only a write that sets `updated_at` | the package's query builder translates them |
| `DB::table('listings')` writes and raw SQL | writes the legacy table; a later incremental pass is guaranteed to select only a write that sets `updated_at` | fails with a database error |
| a legacy model class from before that release, during a deploy | writes the legacy table | fails with a database error |
| a root created through its model, or a subclass of it, that carries `Provides\CarriesLegacy` | inserts no legacy row; install inserts one under its lock | inserts the legacy row in the same transaction |
| a root inserted through the query builder, raw SQL or another class over the root table | inserts no legacy row; install inserts one under its lock | inserts no legacy row, so the view does not serve that root |
| reads of any kind | read the table | read the view |

A later incremental pass is not guaranteed to select a row whose write left `updated_at` alone. A pass selects that row on the first pass, and whenever its `updated_at` is NULL. Install's value proof flags that row's key, and install catches it up before its lock, as [the install gate](#the-install-gate) states. A `computedAs()` attribute is the exception, because the value proof skips it. An edit of its legacy value that leaves `updated_at` alone reaches the successor only when a pass walks the row for another reason. Otherwise install drops it, as a media URL edit to a non-ZML File shows. A write that leaves `updated_at` alone after the value proof and before the lock is lost when install drops the mapped columns.

A root deletion leaves a second window. A root hard-deleted after the existence proof and before install adds the foreign key leaves a successor key with no row. The `NOT VALID` foreign key does not check existing rows, so it does not catch that key, and the view's inner join hides that record. Declined: an anti-join probe under the lock, which is a whole-table scan under the lock.

The view is not automatically updatable, and the package creates no trigger and no rule. A consumer therefore searches for raw writes to the legacy name before install. It ships the model that carries `Provides\ServesCompatibility` at least one release before install.
