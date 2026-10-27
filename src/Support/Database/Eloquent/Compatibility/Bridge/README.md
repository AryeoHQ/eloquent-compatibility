# Bridge

This namespace is the PHP half of the package. `EventBridge` raises the other side's Eloquent model events for each write through a model. When a write's statement starts, it locks the records the write can change and reads them. After the statement it reads them again and replays their events. It stores no data.

Entry points:

- `EventBridge::mirror()` registers an event mirror and listens on its model classes.
- `EventBridge::withoutMirroring()` switches the mirror off for one callback.
- `EventBridge::handleQuery()` matches each `QueryExecuted` to a pending mirrored write.
- `EventBridge::releaseFailed()` closes a write whose statement failed. `Provider` calls it.

The other classes serve `EventBridge`. `AffectedRecords` and `KeyTranslator` find and lock the records, `EventReplayer` raises their events, and `MirrorIndex` indexes the mirrored maps.

Flows: [mirroring a write](../../../../../../docs/flows/mirroring-a-write.md) and [failure and rollback](../../../../../../docs/flows/failure-and-rollback.md).

[Invariants](../../../../../../docs/invariants.md) held here: INV-1, INV-6, INV-15 to INV-21, INV-25, INV-33 to INV-35 and INV-43.
