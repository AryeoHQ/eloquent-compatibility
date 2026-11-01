# Mapping

This namespace holds the declaration of a compatibility map and its resolved form. The builders record what the application declares, and `ResolvedMap` checks it and answers every other part's questions.

Entry points:

- `PreviousModel::servedAsView()`, `PrimaryCurrentModel::for()` and `DependentCurrentModel::for()` declare the models.
- `Attribute::retained()` and `Attribute::mapped()` declare the attributes. Reads are SQL; writes are PHP closures, held by `TargetColumn`.
- The `UnrepresentableState` constructors declare the states one side holds and the other cannot.
- `ExcludedListener::of()` and `ModelEvent` serve an event mirror.
- `ResolvedMap::of()` validates a map. `ResolvedMap::locate()` builds the view's subquery that picks a dependent row, and `ResolvedMap::insertAssignmentsTo()` lists what an insert writes.

Every builder is immutable, and `ResolvedMap::of()` needs no database.

Flows: [declaring and registering a map](../../../../../../docs/flows/declaring-and-registering-a-map.md).

[Invariants](../../../../../../docs/invariants.md) held here: INV-2, INV-8 and INV-12.
