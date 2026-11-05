# 0022. Operations are classes

## Context

The research proposed a closure for each new hook. They were a write handler such as writeUsing(fn ...), a creating() callback on each successor, and a write scope. Each of those closures would run application behavior inside the package's transaction.

The map already accepts closures for its declarations, and refuses a closure that captures variables.

## Decision

`writtenBy()`, `successorsInitializedBy()`, `legacyInitializedBy()`, `writesWithin()` and `dependents()` take a class string only, so a closure is a type error. Each class implements its hook's interface, which the [public surface](../specification/public-surface.md#interfaces-and-their-arguments) lists. A map declares each hook at most once.

The package resolves each operation and hook class from the container, so a constructor can take dependencies. Declined: `new` with no arguments.

The classes live in the consumer's entity namespace plus a final Compatibility segment, as a documented convention that no tool checks. Aryeo's is App\Entities\<Plural>\Compatibility. This amends decision 5 of the plan, which said `<model namespace>\Compatibility`.

The other closures in a map stay closures: `when()`, `choose:`, the scope of `hide()`, the condition of `guard()`, the value of `isNotNull()`, and the read of `external()`. Each one selects rows, picks a class or computes a value, and none of them writes.

Declined: closures for these five hooks, a class with its own interface for each declaration closure, and the literal `<model namespace>\Compatibility` rule.

## Consequences

- Every operation and hook is a named class, which the application can test alone.
- The map names application behavior and never contains it.
- [The map](../specification/the-map.md#classes-only) states the rule and the convention.
