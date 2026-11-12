# 0030. Positions number within the ordering parent

## Context

`position()` took a legacy parent column, such as `photos.listing_id`, and ranked a legacy value among the live rows of that legacy parent. It assumed that a listing's media were the members of one eloquent-ordering parent.

That assumption does not hold. A listing's photos can span several subsections, and v2 orders within each subsection. A rank among a listing's photos is then not a valid position in the successor's parent, and Placement::at() refuses a position outside the member range. The rank conversion also needed an index of the consumer's on the legacy table, `(listing_id, index, id) WHERE deleted_at IS NULL`, and `prepare()` refused without it.

## Decision

`position()` takes no arguments. Positions number within the successor's eloquent-ordering parent. This amends the earlier design, which named a legacy parent column as its argument.

- On a backfill row and a catch-up row, the default operation re-sequences the row's ordering parent. It runs in the default operation's `afterSave()` action, at step 5 of the unit of work. The parent is set there, because step 4 saved the root and `successorsInitializedBy()` has run.
- The action takes eloquent-ordering's parent lock before it reads the members: the `FOR NO KEY UPDATE` lock on the parent row that `move()` takes, held to commit. The re-sequence therefore serializes with every other move in that parent. The members' keys are not added to the write scope's key list.
- The members are the parent's live legacy rows whose successors are current members: not trashed, and with a `rank_key`. They are taken in legacy order: the value with NULLs last, then the key.
- The action walks the members in that order. It moves each member whose successor does not already follow its predecessor's successor to just after it, with Placement::after(), anchored on the predecessor's successor key. One sweep ends in legacy order. A move of a member other than the written row runs under `Model::withoutTimestampsOn()` for the root class, because the package moved it and no writer edited it, so its served `updated_at` does not change.
- Native members keep their places. Each parent's legacy rows end in v1's relative order, which is what install's order proof checks.
- Before install, the round trip and catch-up's equality check compare a `position()` attribute over the parent's whole legacy order, once per parent, as install's `lag()` proof does.
- Placement::at() serves live writes after install, where the legacy value is a served position. After install, the round trip and catch-up's equality check compare the raw value with the served position, and no path re-sequences a parent.
- Install's order proof filters the rows to current members before `lag()` runs, partitions them by that parent, and compares `rank_key` only within one parent. For a key the proof flags, install's catch-up re-sequences that key's whole parent.
- The package reads the parent from eloquent-ordering's published parent key columns.
- The re-sequence walks eloquent-ordering's `(parent, rank_key)` index and the legacy table's successor-key index. The consumer's index on the legacy table is dropped from the design, and `prepare()` no longer refuses without it.
- The cost falls on backfill passes, and on install's catch-up only for the parents the order proof flags.

This amends the earlier design, which placed only the written row after its nearest previous legacy neighbour, or before its nearest next one. One v1 reorder of A, B, C and D to C, D, A and B then refused A on every pass, because A could not sit after D and before B at once. A v2 reorder that no pass walked blocked install the same way.

Declined: a per-listing parent override on `position()`, until a caller needs one sequence per listing. [0032](0032-photos-number-across-the-listing.md) supersedes this for photos, which v1 reads as one sequence per listing.

Declined: placing only the written row after its nearest previous neighbour, checking only that neighbour, and repeating catch-up rounds until a round makes no progress. Those rounds iterate under install's lock, and they need a stop rule.

Declined: moving a backfill row to its rank among the legacy rows with Placement::at(). Before install, a parent also holds native members with no legacy row, so the rank can put a row between the wrong neighbours. A parent of N, A and B that receives C at rank 3 reads N, A, C, B, and install's order proof refuses.

## Consequences

- eloquent-ordering ships Placement::after() today. It must still publish an ordered item's parent key columns and add Placement::at(). These are the requests in https://github.com/AryeoHQ/eloquent-ordering/issues/14.
- After install, positions restart in each parent. Photos are the exception: they number across the listing ([0032](0032-photos-number-across-the-listing.md)), which supersedes the per-subsection sequence this record gave them.
- [0032](0032-photos-number-across-the-listing.md) also supersedes the advice to read `readAs()` with an operation for one sequence per listing. Install's value proof refuses such an attribute over sparse legacy values.
- A consumer creates no index on the legacy table for a `position()` attribute.
- [Operations: positions](../specification/operations.md#positions) states the re-sequence.
