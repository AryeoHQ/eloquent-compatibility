# 0012. Tests pin printed values as literals

## Context

`SqlState` and `DriftCheck` back their cases with values the outside world sees: the SQLSTATE a caller checks, and the labels `compatibility:check` prints. A test that compares a case with itself passes whatever the backing value is.

## Decision

Tests compare those values as literal strings: CM001 in `EventBridgeOutsideTransactionTest`, and each drift label in `CheckDriftTest`.

## Consequences

- Changing a backing value fails a test, so the change is deliberate.
- Do not replace those literals with enum cases.
