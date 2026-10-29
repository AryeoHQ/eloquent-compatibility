# Mapping

This namespace holds the declaration of a compatibility map and its resolved form. The builders record what the application declares, and `ResolvedMap` checks it and answers every other part's questions.

Entry points:

- `PreviousModel::servedAsView()`, `PrimaryCurrentModel::for()` and `DependentCurrentModel::for()` declare the models.
- `PrimaryCurrentModel::triggerArguments()` and `DependentCurrentModel::triggerArguments()` declare the constructor arguments of a state machine trigger that a state change runs. `ResolvedMap::triggerArguments()` returns them to the event bridge.
- `Attribute::retained()`, `Attribute::mapped()` and the `UnrepresentableState` constructors declare the attributes and states.
- `Attribute::inTimeZone()` declares that a mapped attribute's stored column is a `timestamp with time zone`, so the view reads and writes it in a fixed zone. `PrimaryCurrentModel::indexedBy()` declares the `PlannerIndex` values to build over the primary current model's table, each built with `PlannerIndex::named()`, `PlannerIndex::on()`, and `PlannerIndex::whereNull()` or `PlannerIndex::whereNotNull()`.
- `ExcludedListener::of()` and `ModelEvent` serve an event mirror.
- `ResolvedMap::of()` validates a map, the zones and the indexes included. `ResolvedMap::locate()` builds the subquery that picks a dependent row.
- `Attribute::plannerExpression()` builds the expression an index or a statistics object uses. `ResolvedMap::statisticsExpressions()`, `ResolvedMap::indexColumns()`, `ResolvedMap::indexFilter()`, `ResolvedMap::indexName()` and `ResolvedMap::statisticsName()` hand the planner list's parts to `Compiler`.

Every builder is immutable, and `ResolvedMap::of()` needs no database.

Flows: [declaring and registering a map](../../../../../../docs/flows/declaring-and-registering-a-map.md), the trigger arguments in [replaying a transition](../../../../../../docs/flows/replaying-a-transition.md), and the `ResolvedMap` calls in [compiling and installing the schema](../../../../../../docs/flows/compiling-and-installing-the-schema.md).

[Invariants](../../../../../../docs/invariants.md) held here: INV-2, INV-8, INV-9, INV-10, INV-12, INV-22, INV-32 and INV-52.
