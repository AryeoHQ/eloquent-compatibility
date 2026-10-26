# Bridge

This namespace is the PHP half of the package. `EventBridge` raises the other side's Eloquent model events for each write through a model. It locks the records a write can change, waits for the statement, reads the records again and replays their events. It stores no data.

Entry points:

- `EventBridge::mirror()` registers an event mirror and listens on its model classes.
- `EventBridge::withoutMirroring()` switches the mirror off for one callback.
- `EventBridge::handleQuery()` matches each `QueryExecuted` to a pending mirrored write.
- `EventBridge::releaseFailed()` closes a write whose statement failed. `Provider` calls it.

The other classes serve `EventBridge`. `AffectedRecords` and `KeyTranslator` find and lock the records, `EventReplayer` raises their events, and `MirrorIndex` indexes the mirrored maps.

Flows: [mirroring a write](../../../../../../docs/flows/mirroring-a-write.md) and [failure and rollback](../../../../../../docs/flows/failure-and-rollback.md).

[Invariants](../../../../../../docs/invariants.md) held here: INV-1, INV-6 and INV-15 to INV-21.
