# Write

This namespace is the PHP half of the package. It splits every write of a mapped model's Eloquent builder, so the database needs no trigger.

Entry points:

- `WriteRouter::register()` registers a map through its event mirror, and `WriteRouter::withoutMirroring()` switches the replayed events off for one callback.
- `RoutesCompatibleWrites` overrides every write method of an Eloquent builder and hands it to the router. `CompatibleBuilder` uses it, and `HasCompatibility` gives it to each mapped model.
- `PreviousWriter` splits one previous record's insert, update or delete into saves of the current models.
- `CurrentWriter` keeps the previous models in step with a write of a current table, and replays their events through `EventReplayer`.

`Records` runs the writers' SQL: locks, reads through the view, designations and refused-state checks. `MirrorIndex` indexes the registered maps, `Values` compares raw values by column type, and `PreviousRecord` names one record of a previous model.

Flows: [writing through the previous model](../../../../../../docs/flows/writing-through-the-previous-model.md), [writing a current model](../../../../../../docs/flows/writing-a-current-model.md) and [failure and rollback](../../../../../../docs/flows/failure-and-rollback.md).

[Invariants](../../../../../../docs/invariants.md) held here: INV-1, INV-3 to INV-7, INV-11, INV-12, INV-14 to INV-22 and INV-28.
