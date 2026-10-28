# 0013. Enums and value objects type the fixed sets

## Context

The package passed fixed sets as strings, such as operations, model event names and SQLSTATEs. It passed fixed shapes as nested arrays, such as a record with its row.

## Decision

Enums type each fixed set: `Operation`, `ModelEvent`, `DriftCheck`, `SqlState`, `TriggerFunction`, `Setting` and `RecordRole`. Readonly value objects type each fixed shape: `RecordSnapshot`, `Assignment`, `Column`, `AliasedTable`, `MirroredMap`, `MapMember` and `Exclusion`. The compiled SQL and the output of `compatibility:check` did not change.

## Consequences

- `ExcludedListener::forEvent()`, `RecordMirrored::$events` and `InteractsWithCompatibilityMaps::assertMirrored()` take `ModelEvent` cases.
- `ModelEvent` sits in Mapping beside `ExcludedListener`, which takes it as public API. It imports `Operation` from Bridge for `ModelEvent::operation()`.
- Tests that register listeners keep Laravel's event names. See [0014](0014-tests-listen-by-laravel-event-names.md).
