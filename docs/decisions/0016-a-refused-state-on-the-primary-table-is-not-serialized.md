# 0016. A refused state on the primary table is not serialized

## Context

Copilot's review thread 4373715673 on PR #1 found a race in refused states checked on a table. A condition can read other rows of its table, as the workbench `second_root_place` state does. Two transactions that each insert a root place for one gadget each miss the other's uncommitted row. Both checks pass, and both commit the state.

The refuse trigger then took `FOR KEY SHARE` on the primary row, then a transaction-scoped advisory lock keyed on the state and the row's primary foreign key. Since [0024](0024-writes-are-split-in-php-without-triggers.md), `Records::serialize()` takes the same two locks in PHP, before the write. That serializes the writers of one record on a dependent's table or the retained table. A primary row has no primary foreign key, so the key has nothing to name there. A key on the row's own primary key would serialize nothing the row lock does not. A cross-row condition on the primary table always compares different records.

Three options remained for a state checked on the primary table:

- **Declare a limit.** The state stays unserialized. It costs nothing at runtime, and a concurrent pair can commit the state.
- **Key the lock on the map and the state alone.** Every writer of the primary table that reaches the trigger waits for every other, for the whole transaction. Under READ COMMITTED the state cannot race, and the table accepts one insert or update at a time.
- **Refuse `UnrepresentableState::checkedOn()` on the primary alias for a cross-row condition.** The map would fail at registration. That needs a change to `ResolvedMap`, which must then tell a cross-row condition from a single-row one by its SQL text.

## Decision

The user decided on 2026-10-26 to declare the limit. A refused state checked on the primary table takes no lock. `Records::serialize()` takes the lock only for a dependent's alias or the previous model's alias.

## Consequences

- A map author whose cross-row rule lives on the primary table enforces it with a unique or exclusion constraint on that table. The refused state still gives the rule its `CompatibilityWriteRefused` message for a single writer.
- `compatibility:check` reports a row that reached the state through a race, under `DriftCheck::RefusedState`.
- The shipped lock has two limits of its own. A condition that compares rows of different records, or reads another table, can still race. Under REPEATABLE READ the check after the wait reads the old snapshot and misses the committed row.
- Only writes through a model are checked at all: raw SQL on the table skips the check.
- No workbench map declares a state on the primary table, and no test pins this limit. [INV-20](../invariants.md#inv-20-a-refused-state-is-serialized-per-record) records the lock.
