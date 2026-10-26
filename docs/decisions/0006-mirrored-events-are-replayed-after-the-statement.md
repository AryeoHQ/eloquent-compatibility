# 0006. Mirrored events are replayed after the statement

## Context

A write through one side changes rows the other side's models read. Those models never ran, so their listeners would miss the change. The triggers already route the data; the events are what is missing.

## Decision

After the statement succeeds, the bridge reads each affected record again and `EventReplayer::record()` raises its events on a fresh instance. For an update, the instance carries the row from before as its original and the row from after as its attributes. The `-ing` events can stop the write, but a listener cannot change the record.

## Consequences

- Listeners on the other side see the state the database stored, including values the triggers computed.
- A mirrored listener's change has nowhere to go, so `CompatibilityWriteRefused::byMirroredChange()` refuses it. See [INV-15](../invariants.md).
- The mirrored instance is not the caller's instance, and the replayer never begins a pending write for it.
- Writes that fire no model events are stored correctly, and mirror nothing.
