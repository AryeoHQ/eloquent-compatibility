# 0011. Registration needs no database

## Context

An application registers an event mirror from a service provider, so `ResolvedMap::of()` runs while the application boots. The Installer runs in a migration, where the retained table and the current tables exist.

## Decision

`ResolvedMap::of()` checks only what the declaration and the model classes say. It reads a model's table and key from a fresh instance, without a query. Every check that needs the catalog waits for `Compiler::validateColumns()` and the rest of `Compiler::compile()`.

## Consequences

- A missing `Attribute::asType()`, an unknown alias or two owners of one column fail at registration.
- A missing table or column fails when the map compiles, during the install or a recompile.
- A model class must be constructible without arguments.
