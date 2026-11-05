# 0025. Rollback and retire are separate operations

## Context

Compatibility::uninstall() reversed an install. It replayed constraints, indexes and comments, but no privileges, so a role revoked on the view regained access through the restored table. No operation retired a legacy model once its readers were gone.

## Decision

`rollback()` replaces uninstall() as the emergency reversal. It drops the view, drops the successor key's non-NULL check, renames the retained table back, and refills the mapped columns from the successors through the successor key. The legacy model becomes the source of truth again. Rollback also carries the view's grants back to the restored table.

`retire()` is the separate deprecation operation. Once a legacy model has no readers, it drops the view and the retained table, and leaves the successors untouched. It refuses while any object depends on the view or the retained table, inbound foreign keys included, and lists each one. `compatibility:sql retire` prints the legacy-only data it would discard. A second retire() changes nothing.

Declined: one operation that serves both reversal and deprecation, and a retire() that prints its report and asks for confirmation, because it runs in a migration.

## Consequences

- Rollback needs no ledger, so install can drop the ledger.
- Rollback's refill calls no write scope and runs no key re-check. Its table lock waits for in-flight writes to commit, so the refill reads only committed successors. A scope there would deadlock with a live write that holds its scope lock and reads through the view.
- Rollback mirrors install, so it drops the non-NULL check that install added. Native legacy inserts with a NULL key resume after a rollback, and the check would refuse them.
- A re-install after a rollback runs `prepare()` again, which is idempotent and recreates `<legacy>_backfill`. Passes then run from a first pass, and then install. Install refuses when the ledger is missing, and names `prepare()`.
- Retire never deletes a successor.
- [Lifecycle](../specification/lifecycle.md#rollback) states both operations.
