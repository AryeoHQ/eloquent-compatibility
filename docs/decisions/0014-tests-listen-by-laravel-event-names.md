# 0014. Tests listen by Laravel's event names

## Context

`ModelEvent::for()` builds a name such as `eloquent.updated: <class>`. An application registers its listeners with Laravel's own strings, not with this package's enum.

## Decision

Tests that register a listener directly use Laravel's string names, as an application does. Calls into the package's API pass `ModelEvent` cases.

## Consequences

- A test proves the bridge raises the events an application listens for, not the names the package builds.
- Do not convert those strings to `ModelEvent::for()`.
