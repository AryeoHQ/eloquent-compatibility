# 0012. Tests pin printed values as literals

## Context

Some enum cases carry values the outside world sees, such as an SQLSTATE a caller checks or a label a command prints. A test that compares a case with itself passes whatever the backing value is.

## Decision

Tests compare those values as literal strings, never as enum cases.

## Consequences

- Changing a backing value fails a test, so the change is deliberate.
- Do not replace those literals with enum cases.
