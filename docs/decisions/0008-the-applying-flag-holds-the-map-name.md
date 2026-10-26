# 0008. The applying flag holds the map's name

## Context

The view's insert trigger writes the primary row itself. The primary insert trigger on that table would then create a second retained row. Two maps can also share one primary table, and each must react to the other's writes.

## Decision

The view's triggers set a transaction-local setting, `Setting::Applying`, to the map's name around their writes. The primary insert, primary delete and designate triggers return early while it holds their own map's name.

## Consequences

- The view's own writes fire none of its map's current-side reactions.
- An insert through one map still creates the retained row of another map over the same primary table.
- A failed trigger cannot leave the flag set beyond its transaction.
- [INV-3](../invariants.md) records the rule.
