# 0009. Compiled object names fit the identifier limit

## Context

PostgreSQL truncates identifiers longer than 63 characters. A truncated name can collide with another object's name, or miss the name the package looks up.

## Decision

The package refuses an object name over 63 characters when the map compiles.

## Consequences

- A long name fails the compile, and the refusal names the object. It never reaches the database as a silent truncation.
