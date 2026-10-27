# 0020. One closure per trigger argument

## Context

A state change's trigger may need constructor arguments that only the write can supply, such as a reason or a date. The column's proxy in eloquent-state-machines passes the named arguments of its trigger method to the trigger's `make()`. A map must say how each value comes from the write.

Two interfaces were possible:

- **One closure per argument name.** The map passes an array whose keys are the argument names and whose values are closures. The [README](../../README.md) shows this form. The names exist before any write.
- **One closure that returns the array.** The map passes one closure, which returns the arguments keyed by name. The names exist only once the closure runs, inside a write.

## Decision

The user approved one closure per argument name on 2026-10-25. Registration can then check every name against the trigger's constructor, and every left-out parameter against the container, before the application serves a write.

## Consequences

- `ResolvedMap::validateTriggerArguments()` refuses a bad declaration when the map registers. [INV-32](../invariants.md) holds the rule.
- A closure that throws names its own argument in `CompatibilityWriteRefused::byTriggerArgument()`.
- Two arguments that share a computation compute it twice. A closure that needs both values reads them from the same instances.
- `PrimaryCurrentModel::triggerArguments()` and `DependentCurrentModel::triggerArguments()` take the closures as an array. A second call for one trigger replaces the first, and does not merge.
