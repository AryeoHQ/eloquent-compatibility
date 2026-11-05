# 0030. Positions number within the ordering parent

## Context

`position()` took a legacy parent column, such as `photos.listing_id`, and ranked a legacy value among the live rows of that legacy parent. It assumed that a listing's media were the members of one eloquent-ordering parent.

That assumption does not hold. A listing's photos can span several subsections, and v2 orders within each subsection. A rank among a listing's photos is then not a valid position in the successor's parent, and Placement::at() refuses a position outside the member range. The rank conversion also needed an index of the consumer's on the legacy table, `(listing_id, index, id) WHERE deleted_at IS NULL`, and `prepare()` refused without it.

## Decision

`position()` takes no arguments. Positions number within the successor's eloquent-ordering parent. This amends the earlier design, which named a legacy parent column as its argument.

- On a backfill row and a catch-up row, the default operation places the row relative to its legacy neighbours. It runs in the default operation's `afterSave()` action, at step 5 of the unit of work. The parent is set there, because step 4 saved the root and `successorsInitializedBy()` has run.
- The neighbours are the live legacy rows whose successors already belong to the same eloquent-ordering parent, in legacy order: the value with NULLs last, then the key. Trashed rows are skipped.
- The row moves with Placement::after(), anchored on the previous neighbour's successor key. With no previous neighbour, it moves with Placement::before(), anchored on the next neighbour's successor key. With neither, it keeps the create's default placement.
- Native members keep their places. Each parent's legacy rows end in v1's relative order, which is what install's order proof checks.
- Before install, the round trip and catch-up's equality check compare a `position()` attribute by order. The row's successor must sit after the successor of its previous live legacy neighbour in the same parent, and before the next one's. This is install's order proof for one row.
- Placement::at() serves live writes after install, where the legacy value is a served position. After install, the round trip and catch-up's equality check compare the raw value with the served position.
- Install's order proof partitions the rows by that parent, and compares `rank_key` only within one parent.
- The package reads the parent from eloquent-ordering's published parent key columns.
- The neighbour lookup walks eloquent-ordering's `(parent, rank_key)` index and the legacy table's successor-key index. The consumer's index on the legacy table is dropped from the design, and `prepare()` no longer refuses without it.

Declined: a per-listing parent override on `position()`, until a caller needs one sequence per listing.

Declined: moving a backfill row to its rank among the legacy rows with Placement::at(). Before install, a parent also holds native members with no legacy row, so the rank can put a row between the wrong neighbours. A parent of N, A and B that receives C at rank 3 reads N, A, C, B, and install's order proof refuses.

## Consequences

- eloquent-ordering ships Placement::after() and Placement::before() today. It must still publish an ordered item's parent key columns and add Placement::at(). These are the requests in https://github.com/AryeoHQ/eloquent-ordering/issues/14.
- After install, positions restart in each parent. A listing whose photos span two subsections reads one sequence per subsection.
- A legacy reader that needs one sequence per listing reads `readAs()` with an operation instead.
- A consumer creates no index on the legacy table for a `position()` attribute.
- [Operations: positions](../specification/operations.md#positions) states the placement.
