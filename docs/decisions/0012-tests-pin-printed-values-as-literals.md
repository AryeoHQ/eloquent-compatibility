# 0012. Tests pin printed values as literals

## Context

`DriftCheck` backs its cases with values the outside world sees: the labels `compatibility:check` prints. `CompatibilityWriteRefused` builds messages callers may match. A test that compares a case or a message with itself passes whatever the value is.

## Decision

Tests compare those values as literal strings: each drift label in `CheckDriftTest`, and each refusal message in `CompatibilityWriteRefusedTest` and `WriteRouterTest`.

## Consequences

- Changing a printed value fails a test, so the change is deliberate.
- Do not replace those literals with enum cases or calls to the factories.
