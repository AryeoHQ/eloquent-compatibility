# 0021. Related successors are referenced by class

## Context

An operation writes the successors a legacy record owns: the root, and related rows such as a Place, a JobSite and a state row. It needs a way to name each one. The map's relations already name each related model class, and the worked Listing map relates each class once under its root.

## Decision

An operation references a related successor by its model class, through `related()`, `relatedOrNew()` and `deleteRelated()`. Each method is generic: a model's class string in, that model out. The unit of work an operation receives is generic over the root class, and `root()` returns the root.

The map declares each relation with a closure that returns the Eloquent relation, with its constraints inside, such as `withTrashed()`. The package reads the related class from the relation.

- The map refuses to compile two related successors of one class under one root.
- A PHPStan rule reports an operation that references a class the map does not relate.
- Attribute names and column names stay strings.
- A companion, such as the state row, is a declared relation. An operation reaches it with `relatedOrNew()`.
- `related()` returns null when the related row is absent, because a morph has one target at a time and a related row can be missing. Declined: refusing.

Declined: string names, an add() method for undeclared rows, and disambiguation of two relations of one class before a real case needs it.

## Consequences

- The type checker and the PHPStan rule check every reference an operation makes.
- A map that relates one class twice under one root cannot compile.
- The terms change: "primary" becomes "root", and the `Definition\Successors` builder becomes the targets that a morph's chooser picks between.
- [The map](../specification/the-map.md#related-successors) states the declarations.
