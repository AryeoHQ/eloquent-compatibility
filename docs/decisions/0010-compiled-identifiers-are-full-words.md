# 0010. Compiled identifiers are full words

## Context

Maintainers read the compiled SQL to review a map and to debug a refusal. Short aliases such as single letters make that SQL hard to follow. An alias that is a PostgreSQL reserved word breaks the SQL.

## Decision

Every name the compiler adds is a full word. The test fixtures use full-word aliases. The package refuses an alias that is one of `ReservedWords::POSTGRESQL` when the map is declared.

## Consequences

- A reserved word fails at registration, not as a syntax error during the install.
- A short identifier in a test fixture fails a test.
- An application still chooses its own aliases. The package checks only that they are valid.
