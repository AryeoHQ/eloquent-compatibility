# 0011. Registration needs no database

## Context

An application registers its maps while the application boots. The install runs in a migration, where the retained table and the current tables exist.

## Decision

Registration checks only what the declaration and the model classes say. It reads a model's table and key from a fresh instance, without a query. Every check that needs the catalog waits until the map compiles.

## Consequences

- Two owners of one column fail at registration.
- A missing table or column fails when the map compiles, during the install or a recompile.
- A model class must be constructible without arguments.
