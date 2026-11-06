# 0025. Rollback and retire are separate operations

## Context

Compatibility::uninstall() reversed an install. It replayed constraints, indexes and comments, but no privileges, so a role revoked on the view regained access through the restored table. No operation retired a legacy model once its readers were gone.

## Decision

`rollback()` replaces uninstall() as the emergency reversal. It drops the view, renames the retained table back, and refills the mapped columns from the successors through the successor key. It carries the view's grants back to the restored table. Last, it drops the successor key's foreign key and its non-NULL check. The legacy model becomes the source of truth again.

`retire()` is the separate deprecation operation. Once a legacy model has no readers, it drops the view and the retained table, and leaves the successors untouched. It also drops `<legacy>_backfill` and deletes the model's row from `compatibility_backfills`, because retire rules out the re-install that the ledger's deleted rows serve. It refuses while any object depends on the view or the retained table, inbound foreign keys included, and lists each one. `compatibility:sql retire` prints the legacy-only data it would discard, the ledger and the model's backfill row among it. A second retire() changes nothing.

Declined: one operation that serves both reversal and deprecation, and a retire() that prints its report and asks for confirmation, because it runs in a migration.

## Consequences

- Rollback needs no ledger to refill the legacy table. Install keeps the whole ledger, so a re-install after a rollback excludes the roots a pass trashed ([0023](0023-the-backfill-records-an-outcome-per-row.md)).
- Rollback's refill calls no write scope and runs no key re-check. Its table lock waits for in-flight writes to commit, so the refill reads only committed successors. A scope there would deadlock with a live write that holds its scope lock and reads through the view.
- Rollback mirrors install, so it drops the foreign key and the non-NULL check that install added. Native legacy inserts with a NULL key, or with a key whose root does not exist yet, resume after a rollback, and those constraints would refuse them.
- A re-install after a rollback runs `prepare()` again, which is idempotent, inserts the model's `compatibility_backfills` row, and reuses `<legacy>_backfill` with its deleted rows. Passes then run from a first pass, and then install. Install refuses when the ledger is missing, and names `prepare()`.
- Retire never deletes a successor.
- [Lifecycle](../specification/lifecycle.md#rollback) states both operations.
