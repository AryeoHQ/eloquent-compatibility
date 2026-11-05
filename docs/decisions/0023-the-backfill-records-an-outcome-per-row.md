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
- A walked row whose legacy values are unchanged runs no operation. A copy between successor tables, such as an Address's coordinates on a JobSite, is the consumer's own consistency rule, and it must hold after install too. This amends the earlier design, which re-ran the operations of rows that a declared change source reported.
- A deletion is a ledger row whose legacy row is gone. The pass deletes the successor through its model, and marks the row deleted, or failed when a foreign key refuses.
- The pass calls the successor model's `delete()`. For a model that uses SoftDeletes, that is a soft delete: it fires `deleting` and `deleted`, keeps the rows that depend on the successor, and stays recoverable. A legacy delete after install calls `forceDelete()` instead ([Operations: Eloquent-managed columns](../specification/operations.md#eloquent-managed-columns)). The installed delete runs synchronously inside the caller's write, so a foreign key from native history refuses in front of the caller. A pass finds the deletion later, when native history may have attached, and no caller is present.
- The trashed roots a pass leaves stay excluded from install's native-root inserts, because a deleted ledger row names each one. Install keeps those rows, so a re-install after a rollback excludes the same roots. Purging them is the consumer's job.
- Trashed rows follow the same rules, and `compatibility:check` reports them separately.
- Install refuses while any row of this legacy model is refused or failed, and names the reasons and the first keys. Before its lock, install runs passes until one changes the outcome of fewer rows than its pass size. A row whose outcome stays the same does not count. Rows with a NULL `updated_at`, and refused or failed rows, which every pass walks, therefore cannot keep install from its gate. It then proves existence and values. The existence proof checks that every non-NULL successor key names an existing root row, whatever the row's ledger state. The proofs read only, and write no ledger row. Install catches up the keys the existence proof and the value proof flag, proves them again, and refuses only when they still fail. A key the existence proof flags takes the create path, which creates the root with the key value. This amends the earlier design, which refused on any difference. Declined: refusing on the first difference, which blocks install forever on a write that leaves `updated_at` alone. A row is failed only when its write raised an error.
- Each legacy model installs independently, even on a shared successor.
- `prepare()` creates `compatibility_backfills` and `<legacy>_backfill` when they do not exist. The first holds one row per legacy model, keyed by the legacy table's name, so a renamed class keeps its watermark. The second holds one row per legacy row.
- The margin is a named argument of `compatibility:backfill` and install, with a default of 5 minutes. The pass size, 1,000 rows by default, is install's argument alone, because it only sets install's stop condition. The package has no configuration of its own. When install finishes, it deletes the legacy model's row from `compatibility_backfills`. It keeps `compatibility_backfills`, because dropping the shared table would erase other models' watermarks and break their independent installs. This amends the earlier design, which dropped both tables.
- Install also deletes every `<legacy>_backfill` row other than the deleted ones, and keeps the table. `prepare()` reuses it on a re-install, so the roots a pass trashed stay excluded from native-root inserts after a rollback. This amends the earlier design, which dropped `<legacy>_backfill` at install. Declined: dropping the table, which lets a re-install insert a retained row for each trashed root and so revive records whose legacy rows were hard-deleted.

Declined: package-wide configuration keys, the legacy model's class as `legacy_type`, the first pass creating the tables, a declared row scope, a partial install that serves rows without a successor, and re-running operations for rows another table reports, without the equality skip.

## Consequences

- `compatibility:backfill` prints counts by reason, and exits non-zero on any refused or failed row.
- A row the successor cannot represent blocks install by name. Unblocking is the consumer's work: complete the prerequisite, widen the successor, retire rows, split the legacy table, or order the installs.
- Aryeo's File model must serve an asset from its legacy path while `zml_asset_id` is NULL. The historic ZML migration cannot finish before compatibility rolls out. That work is Aryeo's.
- [Lifecycle](../specification/lifecycle.md#the-backfill) and [Storage](../specification/storage.md) state the passes and the tables.
