# 0006. Mirrored events are replayed after the statement

## Context

A write of a current model changes rows the previous model reads. The previous model never ran, so its listeners would miss the change. Since [0024](0024-writes-are-split-in-php-without-triggers.md), a write through the previous model saves the current models through Eloquent, so their own events fire natively. Only this direction, from a current model to the previous model, still needs replayed events.

## Decision

`CurrentWriter` locks and reads each affected previous record through the view before the write, and reads it again after. `EventReplayer::replay()` then raises the previous model's events on a fresh instance. For an update, the instance carries the row from before as its original and the row from after as its attributes. The `-ing` events can stop the write, but a listener cannot change the record.

The replay runs only for a model's own write, as Eloquent raises no events for a query builder write, and never for the map whose previous model is writing the row itself.

## Consequences

- Listeners on the previous model see the state the database stored, including values the view computes.
- A mirrored listener's change has nowhere to go, so `CompatibilityWriteRefused::byMirroredChange()` refuses it. See [INV-15](../invariants.md#inv-15-a-mirrored-listener-may-refuse-a-write-never-change-it).
- The replay runs inside the router's transaction, so a refusal rolls the current write back.
- Writes that fire no model events are stored correctly, and mirror nothing.
