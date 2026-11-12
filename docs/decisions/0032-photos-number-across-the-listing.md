# 0032. Photos number across the listing

## Context

Positions number within the successor's eloquent-ordering parent ([0030](0030-positions-number-within-the-ordering-parent.md)). A listing's photos can span several subsections, so after install they read one sequence per subsection. Record 0030 declined a per-listing scope until a caller needed one sequence per listing, and sent such a caller to `readAs()` with an operation.

Aryeo's audit of v1 found that caller ([AryeoHQ/aryeo#12334](https://github.com/AryeoHQ/aryeo/issues/12334)). v1 reads `photos.index` as one sequence across the listing. `Listing::orderedPhotos()` reads it at 75 call sites, Zillow delivery among them, and the cover photo is index 1.

## Decision

`position()` gains a listing-wide scope for photos. A photo's position is its rank across the listing, ordered by subsection order, then by the asset's `rank_key` within its subsection. This amends record 0030, which declined a per-listing scope.

- Before install, the round trip and catch-up's equality check still compare the order, over each eloquent-ordering parent. The backfill still re-sequences each parent, so each subsection's photos end in legacy order.
- At install, positions renumber dense and 1-based across the listing.
- After install, a v1 write of a photo's `index` converts to a move within the photo's own subsection. The write refuses when the target slot lies in another subsection.
- Videos and floor plans keep `position()` as record 0030 states it: their positions restart in each parent.
- The cost of the listing-wide rank, which no filter bounds, is measured with `EXPLAIN` on production-shaped data before it ships.

Declined: `readAs()` with an operation for `photos.index`. The comparisons check such an attribute by value, so install's value proof refuses a listing whose legacy indexes are sparse.

Declined: a stored index kept in sync on every v2 reorder. That is the refresh hook driven by successor saves that record 0031 declined.

## Consequences

- A listing whose photos span two subsections reads one sequence after install, as v1 reads it.
- The listing-wide scope waits on [eloquent-ordering#14](https://github.com/AryeoHQ/eloquent-ordering/issues/14), as `position()` does ([Status](../specification/README.md#status)). The form of its declaration in the map is settled with that work.
- Aryeo prepares its photo writers: 1-based writes, one next-position helper per media type, one path that positions a photo, and no model default of `index = 0`.
- This record supersedes the per-subsection photo sequence that [Lifecycle: positions after install](../specification/lifecycle.md#positions-after-install), [Operations: positions](../specification/operations.md#positions), the [floor plan example](../specification/examples/floor-plan.md) and record 0030 describe.
