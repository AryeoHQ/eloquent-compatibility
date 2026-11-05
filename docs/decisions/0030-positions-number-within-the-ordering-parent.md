# 0030. Positions number within the ordering parent

## Context

`position()` took a legacy parent column, such as `photos.listing_id`, and ranked a legacy value among the live rows of that legacy parent. It assumed that a listing's media were the members of one eloquent-ordering parent.

That assumption does not hold. A listing's photos can span several subsections, and v2 orders within each subsection. A rank among a listing's photos is then not a valid position in the successor's parent, and Placement::at() refuses a position outside the member range. The rank conversion also needed an index of the consumer's on the legacy table, `(listing_id, index, id) WHERE deleted_at IS NULL`, and `prepare()` refused without it.

## Decision

`position()` takes no arguments. Positions number within the successor's eloquent-ordering parent. This amends the earlier design, which named a legacy parent column as its argument.

- On a backfill row and a catch-up row, the legacy value becomes its rank among the legacy rows whose successors already belong to the successor's eloquent-ordering parent. The rows are ordered by the value with NULLs last, then by key. Trashed rows are skipped.
- Each create therefore inserts at a valid position, which Placement::at() accepts. Each parent's final order matches v1's relative order.
- Install's order proof partitions the rows by that parent, and compares `rank_key` only within one parent.
- The package reads the parent from eloquent-ordering's published parent key columns.
- The rank walks eloquent-ordering's `(parent, rank_key)` index and the legacy table's successor-key index. The consumer's index on the legacy table is dropped from the design, and `prepare()` no longer refuses without it.

Declined: a per-listing parent override on `position()`, until a caller needs one sequence per listing.

## Consequences

- eloquent-ordering must publish an ordered item's parent key columns. This is Requirement 3 of https://github.com/AryeoHQ/eloquent-ordering/issues/14.
- After install, positions restart in each parent. A listing whose photos span two subsections reads one sequence per subsection.
- A legacy reader that needs one sequence per listing reads `readAs()` with an operation instead.
- A consumer creates no index on the legacy table for a `position()` attribute.
- [Operations: positions](../specification/operations.md#positions) states the conversion.
