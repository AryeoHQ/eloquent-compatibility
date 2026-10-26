# 0002. A concurrent change raises 40001

## Context

An INSTEAD OF trigger receives `OLD` from the statement's snapshot, and cannot re-read the row through it. Another transaction can commit a change to the record after that snapshot. The trigger would then write over it.

## Decision

After taking its locks, the update trigger reads the record again through the tables, in `Compiler::conflict()`. When that read differs from `OLD`, it raises SQLSTATE 40001, a serialization failure, and writes nothing. The hint tells the caller to retry, or to write through the model.

## Consequences

- A caller's retry logic for serialization failures covers this case.
- The check compares the attributes the primary and retained rows hold. Dependent attributes are left out.
- A write through a model does not meet the check, because the event bridge locks the record before its statement. See [0003](0003-the-bridge-locks-before-the-statement.md).
- `CompilerTest::it_raises_a_serialization_failure_when_a_primary_or_retained_attribute_changed_after_the_statement_read_the_view()` pins the check. `CompilerTest::it_lets_a_write_through_proceed_when_only_a_dependent_attribute_changed_after_the_statement_read_the_view()` pins that dependent attributes are left out.
