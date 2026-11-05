Status: Proposed

# Lifecycle

Each legacy model moves through the same stages, independently of every other legacy model. That holds even when two legacy models share a successor.

| Stage | Operation | What changes |
| :-- | :-- | :-- |
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

This is documented, not enforced, because Aryeo's v2 writes Jobs live before install. `compatibility:check` reports where the values differ, which shows what the next pass would overwrite.

## Prepare

`Compatibility::prepare()` runs on the live legacy table, before the first pass:

- It adopts the successor key column `servedFrom(key:)` names, or adds it when the column does not exist.
- It adds a unique constraint on the successor key.
- It adds the successor key's foreign key to the root's primary key, `ON DELETE CASCADE NOT VALID`.
- It lists every object that depends on the legacy table.
- For each `position()` attribute, it refuses unless the index `(<legacyWithin>, <column>, <key>) WHERE deleted_at IS NULL` exists. The consumer creates that index `CONCURRENTLY` before prepare. `compatibility:sql prepare` prints the statement.

It allocates nothing on the successors. Running it again changes nothing. [Storage](storage.md#the-successor-key) shows its statements.

## The backfill

`compatibility:backfill {model}` runs one pass. A pass walks the legacy rows by key, in chunks, and writes each row in its own savepoint ([0023](../decisions/0023-the-backfill-records-an-outcome-per-row.md)).

### A pass

1. The pass records its start, read from the database clock.
2. The first pass walks every row. Each later pass walks:
   - rows whose `updated_at` is at or after the watermark minus the margin,
   - rows whose `updated_at` is NULL,
   - rows whose ledger status is `refused` or `failed`, and
   - the keys each `catchUpAlso()` source returns.
3. Each row is written in its own savepoint, through the operations, and its ledger row is upserted.
4. The pass handles deletions: ledger rows whose legacy row is gone.
5. The pass sets the watermark to its recorded start.

A failure rolls back that row only, and the chunk continues.

### The watermark and its margin

The watermark is a timestamp and nothing else: the database time at which the last completed pass started. It holds no key. A pass that does not complete leaves the watermark where it was.

The margin is subtracted from each pass's lower bound. It must exceed the clock skew between the application servers, which stamp `updated_at`, and the database, which stamps the pass start, plus the longest legacy write transaction. A write stamps `updated_at` when it runs, but a pass sees the row only after its transaction commits.

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

### Change sources

A served value can change through another table without touching the legacy row's `updated_at`. `catchUpAlso()` names a `Contracts\ReportsChanges` class. On each pass with a watermark, its `since()` returns the legacy keys whose served values changed through other tables, and the pass walks them.

```php
final class ListingAddressChanges implements ReportsChanges
{
    /** @return iterable<int> */
    public function since(CarbonImmutable $watermark): iterable
    {
        return Listing::query()->whereIn(column: 'address_id', values: Address::query()->where(column: 'updated_at', operator: '>=', value: $watermark)->select('id'))->pluck('id');
    }
}
```

### Positions

A pass converts a `position()` attribute's legacy value to a rank on each backfill row and each catch-up row. The rank is the value's place among the live rows of the same legacy parent, ordered by the value with NULLs last, then by key. [Operations: positions](operations.md#positions) states the conversion.

### Trashed rows

Trashed legacy rows follow the same rules as live ones. `compatibility:check` reports them separately.

### A timeline

```text
10:00  Pass 1 records watermark_at = 10:00 from the database clock.
       It walks every row by key, one savepoint each.
14:00  Pass 2 walks rows with updated_at >= 10:00 minus the margin, rows with NULL updated_at,
       refused and failed rows, change-source keys, and ledger rows whose legacy row is gone.
       It records watermark_at = 14:00.
14:05  Pass 3 walks far fewer rows. Passes repeat until one walks fewer than about 1,000 rows.
       Install then proves existence and values before its lock. Under the lock it runs the
       final small pass, the NULL-key probe, the rename, the drop of mapped columns, the view and the dependent hooks.
```

## The install gate

Install refuses while any row of this legacy model is `refused` or `failed`. The refusal names the reasons and the first keys of each. Before it takes its lock, install also proves two things:

- **Existence.** No legacy row lacks both a ledger row and a successor key.
- **Values.** The drift check's value comparison finds no difference. For a `position()` attribute, the proof compares order, not ranks. Each row's successor `rank_key` must exceed its previous legacy sibling's, through `lag()` over the legacy order.

Install refuses on any difference. It records each key the value proof flags as `failed`, with the reason `values differ`, and keeps those ledger rows when it refuses. The next pass retries them, whatever the watermark, so a row whose write left `updated_at` alone is walked. Each legacy model passes its own gate, even on a shared successor.

The package has no partial install. A legacy row without a successor cannot be served, so install waits until every row has one. Unblocking is the consumer's work: complete the prerequisite, widen the successor to represent the state, retire rows, split the legacy table, or order the installs.

## Install

Install runs in one transaction ([0024](../decisions/0024-install-is-one-transaction.md)).

### Before the lock

1. Install refuses before any work when an object depends on the legacy table and the map declares no `dependents()`.
2. It proves the install gate: the ledger, existence and values.
3. It validates the successor key's foreign key.

These whole-table proofs never run under the lock. The pre-lock proofs, and the ledger rows they mark `failed`, run and commit in their own transaction before install's transaction opens, so a refused install keeps them.

### Under the lock

1. The final small catch-up pass. It calls no write scope and runs no key re-check, because the table lock serializes it against live writes ([the write scope](operations.md#the-write-scope)).
2. One indexed probe for a legacy row with a NULL successor key. Install refuses when the probe finds one. The successor key stays nullable ([Storage](storage.md#2-not-null-on-the-successor-key)).
3. `ManagesDependents::beforeSwap()`.
4. The rename of the legacy table to the retained table.
5. The drop of the mapped columns.
6. The creation of the view.
7. `ManagesDependents::afterSwap()`.

A configurable `statement_timeout` bounds how long install holds its locks. `compatibility:sql install` prints each lock, with its mode and table. Install keeps its own retries when it cannot take a lock.

When install finishes, the package deletes this legacy model's row from `compatibility_backfills` and drops `<legacy>_backfill`. It keeps `compatibility_backfills` itself, because the table holds the watermarks of other legacy models. Dropping it would erase them, and those models could no longer install independently.

### Positions after install

After install, a `position()` attribute reads the successor's position, not the stored legacy value. The order of the rows does not change, but two kinds of value do:

- A row whose legacy value was NULL reads a number.
- Sparse values close up: legacy values 1, 4 and 7 read 1, 2 and 3.

Aryeo's `photos` holds live rows with a NULL `index`, so those photos read a number after install.

### Inside a caller's transaction

`Compatibility::install()` may run inside a caller's transaction when no catch-up is pending. A test that uses `RefreshDatabase` can therefore install a served model.

### Dependent schema

`prepare()`, `install()`, `rollback()` and `compatibility:sql` list every object that depends on the legacy table: views, materialized views, rules, policies and SQL functions.

`dependents()` names a `Contracts\ManagesDependents` class. Its `beforeSwap()` and `afterSwap()` run inside the install transaction. Its `beforeRollback()` and `afterRollback()` run inside the rollback. The package does not rebind dependent views itself.

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

## Rollback

`Compatibility::rollback()` is the emergency reversal, and it replaces `uninstall()` ([0025](../decisions/0025-rollback-and-retire-are-separate-operations.md)). It runs these steps in one transaction:

1. It runs `ManagesDependents::beforeRollback()`, which removes every object that depends on the view.
2. It drops the view.
3. It renames the retained table back to the legacy name.
4. It restores the mapped columns, and refills them from the successors through the successor key. The refill calls no write scope and runs no key re-check. Rollback's table lock waits for in-flight writes to commit, so the refill reads only committed successors.
5. It carries the view's grants back to the restored table.
6. It runs `ManagesDependents::afterRollback()`, which recreates those objects over the restored table.

`beforeRollback()` runs before the drop, because PostgreSQL refuses to drop a view that another object depends on (`2BP01`). Listing's `job_sites_view` depends on the view after `afterSwap()` recreates it.

The legacy model is then the source of truth again. Install had dropped the ledger, so a later install starts with a first pass.

## Retire

`Compatibility::retire()` deprecates a legacy model that has no readers left:

- It drops the view and the retained table.
- It leaves every successor untouched.
- It refuses while an inbound foreign key points at the retained table, and lists each one.
- It prints the legacy-only data it would discard.

## Status and idempotence

`compatibility:status` prints each legacy model's stage. `prepare()`, `install()`, `rollback()` and `retire()` are idempotent: a second run changes nothing. The plan's work item "Keep legacy identity on the legacy table" settles all four. The package has no public `Compatibility::state()`.

## The writer boundary

The package documents this boundary and does not enforce it.

| Writer | Before install | After install |
| :-- | :-- | :-- |
| Eloquent through the legacy model, from the release that ships the trait | writes the legacy table | the package runs the operations |
| `Model::query()->toBase()` writes | writes the legacy table; a pass catches up only a write that sets `updated_at` | the package's query builder translates them |
| `DB::table('listings')` writes and raw SQL | writes the legacy table; a pass catches up only a write that sets `updated_at` | fails with a database error |
| a legacy model class from before that release, during a deploy | writes the legacy table | fails with a database error |
| reads of any kind | read the table | read the view |

A pass never selects a row whose write left `updated_at` alone. Install's value proof flags that row's key and records it `failed`, so the next pass walks it, as [the install gate](#the-install-gate) states. A write that leaves `updated_at` alone after the value proof and before the lock is lost when install drops the mapped columns.

The view is not automatically updatable, and the package creates no trigger and no rule. A consumer therefore searches for raw writes to the legacy name before install. It ships the model that carries `Provides\ServesCompatibility` at least one release before install.

## Open questions

### 1. Configuration values

The session named three values without settling them. They are the clock-skew margin, the pass size at which install proceeds, and the `statement_timeout` budget.

- **A.** Package configuration keys, with defaults: 1,000 rows for the pass size, as today, and 5 minutes for the margin. The `statement_timeout` budget has no default, and install refuses until it is set.
- **B.** Arguments to `install()` and `compatibility:backfill`.

**Recommendation: A.** A migration calls `install()` with no room for tuning arguments. The hold budget depends on each application's tolerance, so the package should not guess it.

### 2. The bound `ReportsChanges::since()` receives

- **A.** The watermark itself.
- **B.** The watermark minus the margin, as the pass's own lower bound.

**Recommendation: B.** The other tables' `updated_at` values come from the same application clocks, so they need the same margin.

### 3. Who runs the passes before install

The timeline repeats passes until one is small, then installs. The session did not say whether install runs those passes itself.

- **A.** Install runs passes until one walks fewer rows than the configured size. It then proves the gate and takes the lock.
- **B.** Install refuses when the last pass was large, and the operator runs more passes.

**Recommendation: A.** Today's install converges itself, and the proofs then run against a nearly current table.

### 4. Retire and its report

The session did not say how `retire()` treats dependent objects, or where its report goes. Its idempotence is settled: [status and idempotence](#status-and-idempotence) states it.

- **A.** `retire()` refuses while any object depends on the view or the retained table, and lists each one. `compatibility:sql retire` prints the data it would discard.
- **B.** `retire()` prints its report and asks for confirmation.

**Recommendation: A.** `retire()` runs in a migration, where nobody can confirm. The refusal follows the rule for inbound foreign keys.

### 5. The stages `compatibility:status` prints

- **A.** The five stages in the table at the top of this page, read from the catalog and the ledger. A backfilling model also shows its pass count, its watermark and its count of refused and failed rows.
- **B.** A free-form description of each model.

**Recommendation: A.** A fixed set of stages can be tested and scripted, and enums type fixed sets ([0013](../decisions/0013-enums-and-value-objects-type-the-fixed-sets.md)).
