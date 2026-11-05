# 0029. Two-primary maps are retired

## Context

The m4 milestone shipped two-primary maps. `servedFrom()` took a chooser and a builder of several successors, and BranchMove moved a record from one primary successor to the other.

Legacy identity now stays on the legacy table, and the retained table holds the key of one root ([0018](0018-legacy-identity-stays-on-the-legacy-table.md)).

## Decision

The package retires the two-primary machinery: branch moves, and the two-primary form of `servedFrom()`. Each legacy model has one root.

The schema keeps the option open. A retained table may hold two nullable root keys, if a real split appears.

Declined: keeping two-primary maps under the new identity model.

## Consequences

- A map that declares two primaries must change before it upgrades.
- The successors builder survives only as the targets of `relatedMorph()`, and its chooser stays.
- [Public surface](../specification/public-surface.md#removed-from-todays-map) lists what the map loses.
