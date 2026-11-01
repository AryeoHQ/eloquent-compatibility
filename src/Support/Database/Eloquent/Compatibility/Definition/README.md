# Definition

This namespace compiles a previous model's own declaration into a compatibility map. `Compatibility`, in the root namespace, is the builder an application writes; the classes here turn it into the parts the rest of the package reads.

Entry points:

- `Compatibility::of()` reads a model's `DefinesCompatibility::compatibility()` declaration, and `Compatibility::toMap()` compiles it into a `DefinedMap`.
- `RelationCompiler::belongsTo()` and `RelationCompiler::hasOne()` read the relation hops, and `RelationCompiler::scope()` compiles a scope into a view condition.

Nothing here reads the database: `SqlFragments::inline()` writes each binding as a literal.

Flows: [declaring and registering a map](../../../../../../docs/flows/declaring-and-registering-a-map.md#declaring-through-the-models).

[Invariants](../../../../../../docs/invariants.md) held here: INV-31, INV-32 and INV-33.
