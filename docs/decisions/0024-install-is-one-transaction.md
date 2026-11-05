# 0024. Install is one transaction

## Context

Install ran in three phases: statements committed before the lock, the locked transaction, and work after the commit. The retained primary key was added after the commit. When that step ran out of retries, the view was live, and no operation could finish the key.

Under the lock, install scanned whole tables to prove its invariants. `lock_timeout` bounded only the wait to acquire a lock, not the time install held it. A dependent view failed install late with `2BP01`, or followed the rename and silently omitted successor-created rows. Aryeo built its own capture and replay of dependent views around the package, with durable checkpoints.

## Decision

With legacy identity on the legacy table, install needs no foreign key repointing, no generated column, no install-time key and no step after the commit. It runs in one transaction.

- Whole-table proofs, of existence and of values, run before the lock.
- Under the lock, install runs only the final small catch-up, the rename, the drop of mapped columns, the view and the dependent hooks.
- `compatibility:sql --for=install` prints each lock with its mode and table. A configurable `statement_timeout` bounds the hold.
- Prepare, install, rollback and `compatibility:sql` list every object that depends on the legacy table. Install refuses before any work when one exists and the map declares no `dependents()`.
- The dependents class runs inside the install transaction, before and after the swap, and mirrors both for rollback.
- The package has no public state() method. `compatibility:status` prints each legacy model's stage, and `prepare()`, `install()` and `rollback()` are idempotent.
- The writer boundary is documented, not enforced. After install, a raw write to the legacy name fails loudly with a database error, and so does a model class from before the release. Eloquent writes through the legacy model and `toBase()` writes are translated, and reads keep working.

Declined: package-managed rebinding of dependent views, until a second adopter needs plain rebinds.

## Consequences

- A failed install commits nothing, so a retry starts from the same state.
- The time install holds its locks no longer grows with the table's size.
- The consumer decides what a dependent becomes after install, through its dependents class.
- The consumer searches for raw writes, and ships the trait-carrying model at least one release before install.
- [Lifecycle](../specification/lifecycle.md#install) states the steps.
