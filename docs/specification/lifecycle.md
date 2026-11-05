Status: Proposed

# Lifecycle

Each legacy model moves through the same stages, independently of every other legacy model. That holds even when two legacy models share a successor.

| Stage | Operation | What changes |
| :-- | :-- | :-- |
| discovered | none | the map compiles, and nothing exists in the database |
| prepared | `Compatibility::prepare()` | the successor key gains its constraints |
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

This is documented, not enforced, because Aryeo's v2 writes Jobs live before install. `compatibility:check` reports each served value that disagrees with its legacy value, which shows what the next pass would overwrite.

## Prepare

`Compatibility::prepare()` runs on the live legacy table, before the first pass:

- It adopts the successor key column `servedFrom(key:)` names, or adds it when the column does not exist.
- It adds a unique constraint on the successor key.
- It adds the successor key's foreign key to the root's primary key, `ON DELETE CASCADE NOT VALID`.
- It creates `<legacy>_updated_at_index` on `updated_at` `CONCURRENTLY`, as it does today, so a pass finds the rows at or after its lower bound without a scan.
- It lists every object that depends on the legacy table.
- It creates `compatibility_backfills` when the table does not exist, and this legacy model's `<legacy>_backfill`. `compatibility:sql prepare` therefore prints every statement the package runs before install.

It allocates nothing on the successors. Running it again changes nothing. [Storage](storage.md#the-successor-key) shows its statements.

## The backfill

`compatibility:backfill {model}` runs one pass. A pass walks the legacy rows by key, in chunks, and writes each row in its own savepoint ([0023](../decisions/0023-the-backfill-records-an-outcome-per-row.md)).

### A pass

1. The pass takes a session-level advisory lock, `pg_try_advisory_lock`, keyed on the legacy model's `legacy_type`. It holds the lock for the whole pass, across its chunk transactions, and releases it when the pass ends. When another pass or an install holds the lock, the pass refuses with a named error, and does not wait.
2. The pass records its start, read from the database clock.
3. The first pass walks every row. Each later pass walks:
   - rows whose `updated_at` is at or after the watermark minus the margin,
   - rows whose `updated_at` is NULL,
   - rows whose ledger status is `refused` or `failed`.
4. Each row is written in its own savepoint, through the operations, and its ledger row is upserted. Inside the savepoint, the create path locks the legacy row `FOR UPDATE`, then re-reads its successor key before it creates. When the key is no longer NULL, the row takes the update path.
5. The pass handles deletions: ledger rows whose legacy row is gone.
6. The pass sets the watermark to its recorded start.

A failure rolls back that row only, and the chunk continues.

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
| `refused` | an operation or initializer refused the row, with its reason |
| `failed` | the row's write raised an error, recorded as the reason |
| `deleted` | the legacy row is gone, and the pass deleted its successor |

`compatibility:backfill` prints the counts by status, and the refusals by reason. It exits non-zero when any row is `refused` or `failed`.

### Retries

Every pass retries the `refused` and `failed` rows, whatever the watermark. Each attempt increments the row's `attempts`.

### Deletions

A ledger row whose legacy row is gone names a deletion. The pass deletes that row's successor through its model, and marks the row `deleted`. When a foreign key refuses the delete, the pass marks the row `failed`.

One rule covers every origin. The pass deletes the successor of a deleted legacy row, whether the backfill created it or not. A soft delete keeps it recoverable. A foreign key, and the per-row outcome, refuse loudly when native history depends on it.

### Changes outside the legacy row

A pass walks only the rows above. A walked row whose legacy values are unchanged runs no operation. A served value can still change through another table, as when an Address edit changes the coordinates a JobSite copies. That copy between successor tables is the consumer's own consistency rule, and it must hold after install too. The package runs nothing for it.

Declined: a declared change source that reports rows another table changed, so that a pass re-runs their operations without the equality skip.

### Positions

A pass places a `position()` attribute's successor relative to its legacy neighbours on each backfill row and each catch-up row. The placement runs in the default operation's `afterSave()` action, at step 5 of the unit of work, when the successor's eloquent-ordering parent is set. The row goes after its nearest previous live legacy neighbour whose successor already belongs to that parent, in legacy order: the value with NULLs last, then the key. Without one, it goes before the nearest next such neighbour. Without either, it keeps the create's default placement. Native members keep their places. A trashed row is not placed, and its `position()` operation does nothing. [Operations: positions](operations.md#positions) states the placement.

### Trashed rows

Trashed legacy rows follow the same rules as live ones, with one exception: a `position()` operation does nothing on a trashed record. The round trip and install's value proof therefore skip a `position()` attribute on a trashed row. `compatibility:check` reports trashed rows separately.

### A timeline

```text
10:00  Pass 1 records watermark_at = 10:00 from the database clock.
       It walks every row by key, one savepoint each.
14:00  Pass 2 walks rows with updated_at >= 10:00 minus the margin, rows with NULL updated_at,
       refused and failed rows, and ledger rows whose legacy row is gone.
       It records watermark_at = 14:00.
14:05  Install runs passes until one changes the outcome of fewer rows than passSize.
       A pass that changes no row always stops the loop. Install then proves
       existence and values before its lock. Under the lock it runs the final small pass, the
       NULL-key probe, the rename, the drop of mapped columns, the native-root inserts, the
       check, the view and the dependent hooks.
```

## The install gate

Install refuses while any row of this legacy model is `refused` or `failed`. The refusal names the reasons and the first keys of each. Before it takes its lock, install also proves two things:

- **Existence.** No legacy row lacks both a ledger row and a successor key.
- **Values.** The drift check's value comparison finds no difference. For a `position()` attribute, the proof compares order, not ranks. It partitions the rows by the successor's eloquent-ordering parent. Within one parent, each row's successor `rank_key` must exceed its previous legacy sibling's, through `lag()` over the legacy order. The proof never compares `rank_key` across two parents, and it skips the attribute on a trashed row. The proof also skips a `readOnly()` attribute with no stored column ([Operations: read-only attributes with no stored column](operations.md#read-only-attributes-with-no-stored-column)).

The proofs read only, and write no ledger row. When the value proof flags keys, install catches up those keys and proves them again. It refuses only when they still differ. A row whose write left `updated_at` alone is therefore caught up before the lock. A ledger row is `failed` only when its write raised an error. Each legacy model passes its own gate, even on a shared successor.

The package has no partial install. A legacy row without a successor cannot be served, so install waits until every row has one. Unblocking is the consumer's work: complete the prerequisite, widen the successor to represent the state, retire rows, split the legacy table, or order the installs.

## Install

Install runs in one transaction ([0024](../decisions/0024-install-is-one-transaction.md)).

### Before the lock

1. Install refuses before any work when an object depends on the legacy table and the map declares no `dependents()`. It also refuses when `<legacy>_backfill` is missing, and the refusal names `prepare()`.
2. It takes the advisory lock that every pass takes, and holds it until install ends. Install's own passes run under it, and any other pass of this model refuses.
3. It runs passes until one changes the outcome of fewer rows than its `passSize:` argument, 1,000 by default. The count covers the rows whose successors the pass created, updated or deleted, and the rows whose ledger status changed. It does not count the rows the pass walked and left as they were. Every pass still walks the rows whose `updated_at` is NULL, and the `refused` and `failed` rows. A pass that changes no row always stops the loop, and install proceeds to its gate, which refuses on `refused` or `failed` rows and names them.
4. It records the start time of its read of native roots. It then reads the keys of member roots that no legacy row's successor key matches, through the indexed anti-join on the successor key. The read excludes every root that a `deleted` ledger row names in `successor_id`. It writes nothing for these roots, and it reports their count.
5. It proves the install gate: the ledger, existence and values. It catches up the keys the value proof flags, proves those keys again, and refuses only when they still differ.
6. It validates the successor key's foreign key.

These whole-table proofs never run under the lock.

### Under the lock

1. The final small catch-up pass. It calls no write scope and runs no key re-check, because the table lock serializes it against live writes ([the write scope](operations.md#the-write-scope)).
2. One indexed probe for a legacy row with a NULL successor key. Install refuses when the probe finds one.
3. `ManagesDependents::beforeInstall()`.
4. The rename of the legacy table to the retained table.
5. The drop of the mapped columns.
6. The insert of one retained row per natively created member root. The set holds each key the pre-lock read found, plus every member root whose `created_at` is at or after that read's start time minus the margin. Install anti-joins both against the retained table's successor key, so a root the final pass just created is excluded. It also excludes every root that a `deleted` ledger row names in `successor_id`. Install runs `legacyInitializedBy()` per row, with `Origin::Backfill`. It catches nothing from an initializer under the lock. A `Refusal` therefore aborts install, and the refusal names the root's key and the reason ([Operations: native successors before install](operations.md#native-successors-before-install)).
7. The addition of `CHECK (<key> IS NOT NULL) NOT VALID` on the successor key. It needs no scan, and it checks every later insert and update ([Storage](storage.md#the-successor-key)).
8. The creation of the view.
9. `ManagesDependents::afterInstall()`.

Install's required `statementTimeout:` argument sets `statement_timeout` for every statement under install's lock. It is also the total budget for the lock hold. Before every step under the lock, and before every native-root insert, install checks the time elapsed since it took the lock. Once the budget is spent, install rolls back and refuses, and the refusal names the step it reached. The argument therefore bounds the hold. It has no default, because the hold budget depends on each application's tolerance. `compatibility:sql install` prints each lock, with its mode and table. Install keeps its own retries when it cannot take a lock.

Declined: a per-statement timeout only. Nothing would then bound the hold, and PostgreSQL 14 has no `transaction_timeout`.

`Provides\CarriesLegacy` does nothing before install, so a natively created root has no legacy row until install inserts one under its lock. The trait takes an `ACCESS SHARE` lock on the legacy table before it checks whether the model is installed. A native create therefore waits for install's `ACCESS EXCLUSIVE` lock, and afterward it sees the installed state. No pass walks that row, and install's pause grows with the number of such roots. After install, the trait inserts the legacy row on create. Legacy readers do not see a v2-created listing until install ([Operations: native successors before install](operations.md#native-successors-before-install)).

When install finishes, the package deletes this legacy model's row from `compatibility_backfills` and drops `<legacy>_backfill`. It keeps `compatibility_backfills` itself, because the table holds the watermarks of other legacy models. Dropping it would erase them, and those models could no longer install independently.

### Positions after install

After install, a `position()` attribute reads the successor's position, not the stored legacy value. The order of the rows within each eloquent-ordering parent does not change, but three kinds of value do:

- A row whose legacy value was NULL reads a number.
- Sparse values close up: legacy values 1, 4 and 7 read 1, 2 and 3.
- Positions restart in each parent. A listing whose photos span two subsections reads one sequence per subsection.

Aryeo's `photos` holds live rows with a NULL `index`, so those photos read a number after install.

A trashed row reads a NULL `index` after install, because a trashed record has no position.

### Inside a caller's transaction

`Compatibility::install()` may run inside a caller's transaction when no catch-up is pending. A test that uses `RefreshDatabase` can therefore install a served model.

Inside a caller's transaction, a refusal loses nothing. The proofs write no ledger row, and the next install's value proof flags the same keys again.

### Dependent schema

`prepare()`, `install()`, `rollback()` and `compatibility:sql` list every object that depends on the legacy table: views, materialized views, rules, policies and SQL functions.

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
3. It drops `CHECK (<key> IS NOT NULL)` from the successor key, because native legacy inserts with a NULL key resume after a rollback ([Storage](storage.md#the-successor-key)).
4. It renames the retained table back to the legacy name.
5. It restores the mapped columns, and refills them from the successors through the successor key. The refill calls no write scope and runs no key re-check. Rollback's table lock waits for in-flight writes to commit, so the refill reads only committed successors.
6. It carries the view's grants back to the restored table.
7. It runs `ManagesDependents::afterRollback()`, which recreates those objects over the restored table.

`beforeRollback()` runs before the drop, because PostgreSQL refuses to drop a view that another object depends on (`2BP01`). Listing's `job_sites_view` depends on the view after `afterInstall()` recreates it.

The legacy model is then the source of truth again.

### Re-install after rollback

Install had dropped `<legacy>_backfill`, so a re-install runs `prepare()` again. `prepare()` is idempotent, and it recreates the ledger. Passes then run, starting with a first pass, and then install. Install refuses when the ledger is missing, and the refusal names `prepare()`.

## Retire

`Compatibility::retire()` deprecates a legacy model that has no readers left:

- It drops the view and the retained table.
- It leaves every successor untouched.
- It refuses while any object depends on the view or the retained table, and lists each one. An inbound foreign key is one such object.
- `compatibility:sql retire` prints the legacy-only data it would discard. `retire()` runs in a migration, where nobody can confirm, so it asks nothing.
- A second `retire()` changes nothing.

## Status and idempotence

`compatibility:status` prints each legacy model's stage: one of the six stages in the table at the top of this page, read from the catalog and the ledger. A model that is still backfilling also shows its pass count, its watermark, and its counts of refused and failed rows. `prepare()`, `install()`, `rollback()` and `retire()` are idempotent: a second run changes nothing. The package has no public `Compatibility::state()`.

## The writer boundary

The package documents this boundary and does not enforce it.

| Writer | Before install | After install |
| :-- | :-- | :-- |
| Eloquent through the legacy model, from the release that ships the trait | writes the legacy table | the package runs the operations |
| `Model::query()->toBase()` writes | writes the legacy table; a later incremental pass is guaranteed to select only a write that sets `updated_at` | the package's query builder translates them |
| `DB::table('listings')` writes and raw SQL | writes the legacy table; a later incremental pass is guaranteed to select only a write that sets `updated_at` | fails with a database error |
| a legacy model class from before that release, during a deploy | writes the legacy table | fails with a database error |
| reads of any kind | read the table | read the view |

A later incremental pass is not guaranteed to select a row whose write left `updated_at` alone. A pass selects that row on the first pass, and whenever its `updated_at` is NULL. Install's value proof flags that row's key, and install catches it up before its lock, as [the install gate](#the-install-gate) states. A write that leaves `updated_at` alone after the value proof and before the lock is lost when install drops the mapped columns.

The view is not automatically updatable, and the package creates no trigger and no rule. A consumer therefore searches for raw writes to the legacy name before install. It ships the model that carries `Provides\ServesCompatibility` at least one release before install.
