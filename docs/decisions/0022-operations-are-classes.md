# 0022. Operations are classes

## Context

The research proposed a closure for each new hook. They were a write handler such as `writeUsing(fn ...)`, a `creating()` callback on each successor, a write scope, and an extra catch-up condition. Each of those closures would run application behavior inside the package's transaction.

The map already accepts closures for its declarations, and refuses a closure that captures variables.

## Decision

`writtenBy()`, `successorsInitializedBy()`, `legacyInitializedBy()`, `writesWithin()`, `dependents()` and `catchUpAlso()` take a class string only, so a closure is a type error. Each class implements its hook's interface, which the [public surface](../specification/public-surface.md#interfaces-and-their-arguments) lists. The classes live under the model's `Compatibility` namespace.

Declined: closures for these six hooks.

## Consequences

- Every operation and hook is a named class, which the application can test alone.
- The map names application behavior and never contains it.
- The other closures in a map, such as a membership scope or a morph's chooser, are an [open question](../specification/the-map.md#1-declaration-closures-under-the-classes-only-rule).
