# 0025. Rollback and retire are separate operations

## Context

`Compatibility::uninstall()` reversed an install. It replayed constraints, indexes and comments, but no privileges, so a role revoked on the view regained access through the restored table. No operation retired a legacy model once its readers were gone.

## Decision

`rollback()` replaces `uninstall()` as the emergency reversal. It drops the view, renames the retained table back, and refills the mapped columns from the successors through the successor key. The legacy model becomes the source of truth again. Rollback also carries the view's grants back to the restored table.

`retire()` is the separate deprecation operation. Once a legacy model has no readers, it drops the view and the retained table, and leaves the successors untouched. It refuses while inbound foreign keys exist, and lists them. It prints the legacy-only data it would discard.

Declined: one operation that serves both reversal and deprecation.

## Consequences

- Rollback needs no ledger, so install can drop the ledger.
- Rollback's refill runs inside the write scope, like every package write.
- An install after a rollback starts with a first pass.
- Retire never deletes a successor.
- [Lifecycle](../specification/lifecycle.md#rollback) states both operations.
