# Mapping

This namespace holds the declaration of a compatibility map and its resolved form. The builders record what the application declares, and `ResolvedMap` checks it and answers every other part's questions.

Entry points:

- `PreviousModel::servedAsView()`, `PrimaryCurrentModel::for()` and `DependentCurrentModel::for()` declare the models.
- `Attribute::retained()`, `Attribute::mapped()` and the `UnrepresentableState` constructors declare the attributes and states.
- `ExcludedListener::of()` and `ModelEvent` serve an event mirror.
- `ResolvedMap::of()` validates a map. `ResolvedMap::locate()` builds the subquery that picks a dependent row.

Every builder is immutable, and `ResolvedMap::of()` needs no database.

Flows: [declaring and registering a map](../../../../../../docs/flows/declaring-and-registering-a-map.md), and the `ResolvedMap` calls in [compiling and installing the schema](../../../../../../docs/flows/compiling-and-installing-the-schema.md).

[Invariants](../../../../../../docs/invariants.md) held here: INV-2, INV-8, INV-9, INV-10, INV-12 and INV-22.
