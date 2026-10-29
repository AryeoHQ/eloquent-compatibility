# Mapping

This namespace holds the declaration of a compatibility map and its resolved form. The builders record what the application declares, and `ResolvedMap` checks it and answers every other part's questions.

Entry points:

- `PreviousModel::servedAsView()`, `PrimaryCurrentModel::for()` and `DependentCurrentModel::for()` declare the models.
- `PrimaryCurrentModel::triggerArguments()` and `DependentCurrentModel::triggerArguments()` declare the constructor arguments of a state machine trigger that a state change runs. `ResolvedMap::triggerArguments()` returns them to the event bridge.
- `Attribute::retained()`, `Attribute::mapped()` and the `UnrepresentableState` constructors declare the attributes and states.
- `ExcludedListener::of()` and `ModelEvent` serve an event mirror.
- `ResolvedMap::of()` validates a map. `ResolvedMap::locate()` builds the subquery that picks a dependent row.

Every builder is immutable, and `ResolvedMap::of()` needs no database.

Flows: [declaring and registering a map](../../../../../../docs/flows/declaring-and-registering-a-map.md), the trigger arguments in [replaying a transition](../../../../../../docs/flows/replaying-a-transition.md), and the `ResolvedMap` calls in [compiling and installing the schema](../../../../../../docs/flows/compiling-and-installing-the-schema.md).

[Invariants](../../../../../../docs/invariants.md) held here: INV-2, INV-8, INV-9, INV-10, INV-12, INV-22 and INV-32.
