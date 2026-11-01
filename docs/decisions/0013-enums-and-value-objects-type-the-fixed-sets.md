# 0013. Enums and value objects type the fixed sets

## Context

The package passed fixed sets as strings, such as model event names and drift labels. It passed fixed shapes as nested arrays, such as a record with its key.

## Decision

Enums type each fixed set: `ModelEvent`, `DriftCheck` and `UnrepresentableStateKind`. Readonly value objects type each fixed shape: `PreviousRecord`, `Assignment`, `TargetColumn`, `Column`, `AliasedTable`, `MapMember` and `Exclusion`.

## Consequences

- `ExcludedListener::forEvent()`, `RecordMirrored::$events` and `InteractsWithCompatibilityMaps::assertMirrored()` take `ModelEvent` cases.
- `ModelEvent` sits in Mapping beside `ExcludedListener`, which takes it as public API.
- Tests that register listeners keep Laravel's event names. See [0014](0014-tests-listen-by-laravel-event-names.md).
