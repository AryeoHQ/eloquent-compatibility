# 0003. The bridge locks before the statement

## Context

The update trigger raises 40001 when the record changed after the statement's snapshot. The event bridge also reads each record before and after a write, to decide which events to raise.

## Decision

`EventBridge::begin()` locks every affected record with `FOR UPDATE` before the statement runs, then reads it. `AffectedRecords::snapshots()` locks in the triggers' order: primary rows, then retained rows, then dependent rows.

## Consequences

- No other transaction can change a locked record between the bridge's two reads.
- A write through a model does not meet the 40001 conflict check.
- The bridge's lock order must match the triggers' order, or two writers can deadlock. [INV-1](../invariants.md) records the rule.
- A write locks the previous records of every map that shares a current table, not only its own.
- A delete through the previous model that removes the row also locks every dependent row the trigger removes. See [INV-25](../invariants.md).
