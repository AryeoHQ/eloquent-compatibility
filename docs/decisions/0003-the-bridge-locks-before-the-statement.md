# 0003. The bridge locks before the statement

## Context

The update trigger raises 40001 when the record changed after the statement's snapshot. The event bridge also reads each record before and after a write, to decide which events to raise.

## Decision

When a write's own statement starts, the private `EventBridge::open()` locks every affected record with `FOR UPDATE`, then reads it. It runs in the `Connection::beforeExecuting()` callback, so the locks come just before the statement and never without it. [INV-34](../invariants.md) records that rule. `AffectedRecords::before()` locks in the triggers' order, through the private `AffectedRecords::lock()`: primary rows, then retained rows, then dependent rows. `AffectedRecords::snapshots()` only reads.

## Consequences

- No other transaction can change a locked record between the bridge's two reads.
- A write through a model does not meet the 40001 conflict check.
- The bridge's lock order must match the triggers' order, or two writers can deadlock. The refused-state lock joins that order: a refuse function locks the primary row before its advisory lock. [INV-1](../invariants.md) records the rule.
- Two paths still break the order, and INV-1 records both. A raw writer that locks a dependent row and then updates it can deadlock a write through the view. The bridge locks a dependent write's primary row first, so a bridged write deadlocks only when the application locked the dependent row itself. The bridge runs `AffectedRecords::of()` again under its first locks, and a newcomer record it finds there locks after every record of the first pass.
- A write locks the previous records of every map that shares a current table, not only its own.
- A delete through the previous model that removes the row also locks every dependent row the trigger removes. That includes a row another transaction committed before the bridge locked the primary row. See [INV-25](../invariants.md).
