# 0013. Enums and value objects type the fixed sets

## Context

The package passed fixed sets as strings, such as operations, model event names and SQLSTATEs. It passed fixed shapes as nested arrays, such as a record with its row.

## Decision

Enums type each fixed set. Readonly value objects type each fixed shape, such as `Column`.

## Consequences

- Public API takes enum cases, not strings, for a fixed set.
- Tests that register listeners keep Laravel's event names. See [0014](0014-tests-listen-by-laravel-event-names.md).
