# 0009. Map names fit the object name limit

## Context

Versions before [0024](0024-writes-are-split-in-php-without-triggers.md) named every trigger and function `compatibility__`, the map name, `__` and a suffix, and PostgreSQL truncates identifiers longer than 63 characters. The package installs no such object now, but `Installer::recompile()` still finds the objects an earlier version installed by that prefix, `ResolvedMap::legacyObjectPrefix()`. The map name also keys the advisory lock of each refused state.

## Decision

A map name has at most 24 lowercase letters, digits and single underscores, and does not end in an underscore. `ResolvedMap::of()` refuses any other.

## Consequences

- No map's legacy prefix begins another's, so recompiling one map never drops another's leftover objects.
- A map installed by an earlier version keeps its name, so a recompile finds its triggers.
