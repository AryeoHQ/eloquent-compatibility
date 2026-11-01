# 0009. Map names fit the object name limit

## Context

PostgreSQL truncates identifiers longer than 63 characters. Every database object the package names is `compatibility__`, the map name, `__` and a suffix. The package finds a map's objects by that prefix alone.

## Decision

A map name has at most 24 lowercase letters, digits and single underscores, and does not end in an underscore. The package refuses any other when the map is declared. It refuses an object name over 63 characters when the map compiles.

## Consequences

- The prefix takes at most 41 characters, which leaves 22 for a suffix.
- No map's prefix begins another's, so uninstalling one map never drops another's objects.
- A long unrepresentable state or alias name can still exceed the limit. The compile fails, and names the object.
