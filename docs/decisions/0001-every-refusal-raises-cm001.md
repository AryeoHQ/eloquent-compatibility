# 0001. Every refusal raises CM001

## Context

The compiled SQL refuses several kinds of write, such as a change to a read-only attribute or a write that reaches a refused state. PHP code and tests must tell a refusal from any other database error, and show the user what to do.

## Decision

Every refusal raises one SQLSTATE, CM001, with a message, a detail and a hint. `SqlFragments::refuse()` is the only way the compiler raises one. `CompatibilityWriteRefused::fromQueryException()` reads the three parts into what was refused, why, and what to do instead.

## Consequences

- `CompatibilityWriteRefused::isRefusal()` tests the code alone, never the message.
- The event bridge's own refusals throw `CompatibilityWriteRefused` with the same three parts, so callers handle both forms alike.
- A concurrent change is not a refusal, and keeps its own code. See [0002](0002-a-concurrent-change-raises-40001.md).
- Changing the code breaks every caller that checks for it. A test asserts the literal CM001. See [0012](0012-tests-pin-printed-values-as-literals.md).
