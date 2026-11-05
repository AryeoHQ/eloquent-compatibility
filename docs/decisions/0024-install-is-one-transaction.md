# 0024. Install is one transaction

## Context

Install ran in three phases: statements committed before the lock, the locked transaction, and work after the commit. The retained primary key was added after the commit. When that step ran out of retries, the view was live, and no operation could finish the key.

Under the lock, install scanned whole tables to prove its invariants. `lock_timeout` bounded only the wait to acquire a lock, not the time install held it. A dependent view failed install late with `2BP01`, or followed the rename and silently omitted successor-created rows. Aryeo built its own capture and replay of dependent views around the package, with durable checkpoints.

## Decision

With legacy identity on the legacy table, install needs no foreign key repointing, no generated column, no install-time key and no step after the commit. It runs in one transaction.

- Before the lock, install runs passes until one walks fewer rows than its pass size. It reads the keys of member roots without a legacy row through the indexed anti-join on the successor key, writes nothing for them, and reports their count.
- Whole-table proofs, of existence and of values, run before the lock. They read only. Install catches up the keys the value proof flags, proves them again, and refuses only when they still differ. This amends decision 7 of the plan, which refused on any difference. Declined: refusing on the first difference, which blocks install forever on a write that leaves `updated_at` alone.
- Under the lock, install runs only the final small catch-up, one indexed probe for a NULL successor key, the rename, the drop of mapped columns, the insert of one retained row per natively created root, the `NOT VALID` check that keeps the key non-NULL, the view and the dependent hooks. The native-root insert covers the keys the pre-lock read found, plus every member root created since, through an indexed range on the root's key or `created_at`, and runs the legacy initializer per row.
- The final catch-up calls no write scope and runs no key re-check. The table lock serializes it against live writes.
- `compatibility:sql install` prints each lock with its mode and table. A required statement timeout argument bounds the hold, and the package has no configuration of its own.
- Prepare, install, rollback and `compatibility:sql` list every object that depends on the legacy table. Install refuses before any work when one exists and the map declares no `dependents()`.
- The dependents class runs inside the install transaction, through beforeInstall() and afterInstall(), and mirrors both for rollback through beforeRollback() and afterRollback().
- Install refuses when `<legacy>_backfill` is missing, and the refusal names `prepare()`. A re-install after a rollback therefore runs `prepare()` again, then passes, then install.
- The package has no public state() method. `compatibility:status` prints each legacy model's stage from the catalog and the ledger, with the pass count, the watermark and the refused and failed counts of a backfilling model, and `prepare()`, `install()` and `rollback()` are idempotent.
- The writer boundary is documented, not enforced. After install, a raw write to the legacy name fails loudly with a database error, and so does a model class from before the release. Eloquent writes through the legacy model and `toBase()` writes are translated, and reads keep working.

Declined: package-managed rebinding of dependent views, until a second adopter needs plain rebinds. Also declined: inserting native roots' rows before the lock with the mapped columns filled from the successors, because the final pass would overwrite a v2 edit made inside the install window. Also declined: a free-form status, and proofs that record flagged keys as failed in the ledger, because those marks roll back with a refusal inside a caller's transaction. The dependents hooks were named beforeSwap() and afterSwap() before PR #21's answers renamed them.

## Consequences

- A failed install commits nothing, so a retry starts from the same state. Inside a caller's transaction, a refusal loses nothing, because the next install flags the same keys again.
- Legacy readers do not see a successor created natively before install until install inserts its retained row. No pass walks that row, and install's pause grows with the number of such roots, inside the statement timeout.
- The time install holds its locks no longer grows with the table's size.
- The consumer decides what a dependent becomes after install, through its dependents class.
- The consumer searches for raw writes, and ships the trait-carrying model at least one release before install.
- [Lifecycle](../specification/lifecycle.md#install) states the steps.
