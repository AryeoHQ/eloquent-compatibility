# 0011. Registration needs no database

## Context

An application registers its maps with `WriteRouter::register()` from a service provider, so `ResolvedMap::of()` runs while the application boots. The Installer runs in a migration, where the retained table and the current tables exist.

## Decision

`ResolvedMap::of()` and `WriteRouter::register()` check only what the declaration and the model classes say. They read a model's table and key from a fresh instance, and its methods through reflection, without a query. Every check that needs the catalog waits for `Compiler::compile()`.

## Consequences

- A missing `Attribute::asType()`, an unknown alias, two owners of one column, or a column set both by an attribute and by a fill fail at registration.
- A missing table or column fails when the map compiles, during the install or a recompile.
- A model class must be constructible without arguments.
- The writers read the catalog lazily, through one `ColumnCatalog` per connection, on the first write.
