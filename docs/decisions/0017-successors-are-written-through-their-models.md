# 0017. Successors are written through their models

## Context

The package chose its write mechanism by the map's shape. A map without a morph or an external attribute backfilled with a set-based upsert. Mass writes, related rows and the install catch-up's deletions also ran as set-based SQL. None of those paths fired a successor's model events.

Both sibling packages now keep their invariants in model listeners. eloquent-ordering assigns rank keys, locks parents and dispatches its reorder event there. Its README calls any write that bypasses Eloquent unsupported. Aryeo's Job guards its lifecycle columns in model listeners too.

The research recommended model writes by default, with a set-based opt-in that a map declares per successor.

## Decision

Every package path saves through the successor's model: record writes, mass writes, the backfill, catch-up and related rows. No set-based path exists.

Declined: the declared set-based opt-in per successor, which the research recommended.

## Consequences

- A successor's listeners run on every path. Event behavior no longer depends on the map's shape.
- Mass writes and the backfill run once per record, so a backfill of a large table, such as 196M photos, takes longer.
- Successor listeners run during the backfill. An operation tells a backfill write from a live one through its origin.
- The install catch-up deletes a gone row's successor through its model.
- [Operations](../specification/operations.md#the-unit-of-work) states the statements and events of each save.
