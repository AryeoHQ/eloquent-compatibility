# Bridge

This namespace is the PHP half of the package. `EventBridge` raises the other side's Eloquent model events for each write through a model. When a write's statement starts, it locks the records the write can change and reads them. After the statement it reads them again and replays their events. It stores no data.

Entry points:

- `EventBridge::mirror()` registers an event mirror and indexes its model classes.
- `EventBridge::withoutMirroring()` switches the mirror off for one callback.
- `EventBridge::fireModelEvent()` runs a mapped model's event dispatch, begins and pairs its pending mirrored writes, and forgets a vetoed one. `HasCompatibility` calls it.
- `EventBridge::markOwnStatement()` claims and arms the pending mirrored write of a model's own statement. `HasCompatibility` calls it.
- `EventBridge::handleQuery()` completes the open pending mirrored write on the `QueryExecuted` of its own statement.
- `EventBridge::releaseFailed()` closes a write whose statement failed. `Provider` calls it.

The other classes serve `EventBridge`. `AffectedRecords` and `KeyTranslator` find and lock the records, `EventReplayer` raises their events, and `MirrorIndex` indexes the mirrored maps. When a write through the previous model moves a current model's state column, `EventReplayer` first runs the state machine's trigger without its transition. That step needs `aryeo/eloquent-state-machines`, which the package suggests.

Flows: [mirroring a write](../../../../../../docs/flows/mirroring-a-write.md), [replaying a transition](../../../../../../docs/flows/replaying-a-transition.md) and [failure and rollback](../../../../../../docs/flows/failure-and-rollback.md).

[Invariants](../../../../../../docs/invariants.md) held here: INV-1, INV-6, INV-15 to INV-21, INV-25, INV-27 to INV-31, INV-33 to INV-35, INV-43 and INV-50.
