# 0023. The backfill records an outcome per row

## Context

The backfill wrote each chunk in one transaction. One unwritable row aborted the chunk, and a database error named no row. The ledger `<legacy>_backfilled` held keys only. Install needed every legacy row to have a successor, trashed rows included.

The media tables could not meet that. About 195.8M photos had no ZML asset, and some legacy files never will. The watermark also missed rows. Catch-up compared a key and a timestamp, skipped rows whose `updated_at` was NULL, and ignored clock skew. Install never ran the drift check's value comparison.

Aryeo's Listing team rebuilt the lifecycle for its own writer: a run table, a records table, parity checks and a cutover.

## Decision

- Each legacy row is written in its own savepoint. A failure rolls back that row only, and the chunk continues.
- An operation or initializer refuses a row with a refusal that carries its reason.
- Every pass retries refused and failed rows, whatever the watermark.
- The watermark is a timestamp only: the database time at which the last completed pass started. Each pass subtracts a margin from its lower bound, and walks every row whose `updated_at` is NULL. The margin must exceed the clock skew plus the longest legacy write transaction, because a row becomes visible only when its write commits.
- `catchUpAlso()` names a change source. It returns the legacy keys whose served values changed through other tables since the watermark.
- A deletion is a ledger row whose legacy row is gone. The pass deletes the successor through its model, and marks the row deleted, or failed when a foreign key refuses.
- Trashed rows follow the same rules, and `compatibility:check` reports them separately.
- Install refuses while any row of this legacy model is refused or failed, and names the reasons and the first keys. Before its lock, install also proves existence and values, and refuses on any difference. It records each key the value proof flags as failed, with the reason "values differ", so the next pass walks a row whose write left `updated_at` alone.
- Each legacy model installs independently, even on a shared successor.
- `compatibility_backfills` holds one row per legacy model, and `<legacy>_backfill` holds one row per legacy row. When install finishes, it deletes the legacy model's row from `compatibility_backfills` and drops `<legacy>_backfill`. It keeps `compatibility_backfills`, because dropping the shared table would erase other models' watermarks and break their independent installs. This amends the "both dropped" wording of the plan's decision 7.

Declined: a declared row scope, and a partial install that serves rows without a successor.

## Consequences

- `compatibility:backfill` prints counts by reason, and exits non-zero on any refused or failed row.
- A row the successor cannot represent blocks install by name. Unblocking is the consumer's work: complete the prerequisite, widen the successor, retire rows, split the legacy table, or order the installs.
- Aryeo's File model must serve an asset from its legacy path while `zml_asset_id` is NULL. The historic ZML migration cannot finish before compatibility rolls out. That work is Aryeo's.
- [Lifecycle](../specification/lifecycle.md#the-backfill) and [Storage](../specification/storage.md) state the passes and the tables.
