# 0008. The applying flag holds the map's name

## Context

The view's insert trigger writes the primary row itself. The primary insert trigger on that table would then create a second retained row. Two maps can also share one primary table, and each must react to the other's writes.

## Decision

The view's triggers hold a setting, `Setting::Applying`, at the map's name while they run. Each function's `SET` clause sets it, and PostgreSQL restores the earlier value when the function returns. The primary insert, primary delete and designate triggers return early while it holds their own map's name.

## Consequences

- The view's own writes fire none of its map's current-side reactions.
- An insert through one map still creates the retained row of another map over the same primary table.
- A failed trigger cannot leave the flag set beyond its own run.
- A write through another map's view inside the write leaves the outer map's name in place when it returns.
- [INV-3](../invariants.md) records the rule.
