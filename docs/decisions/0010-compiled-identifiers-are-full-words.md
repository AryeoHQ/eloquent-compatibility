# 0010. Compiled identifiers are full words

## Context

Maintainers read the compiled SQL to review a map and to debug a refusal. Short aliases such as single letters make that SQL hard to follow. An alias that is a PostgreSQL reserved word breaks the SQL.

## Decision

Every name the compiler adds is a full word, such as `previous_row` and `key_<alias>`. The workbench maps use full-word aliases. `ResolvedMap::of()` refuses an alias that is `new`, `old` or one of `ReservedWords::POSTGRESQL`.

## Consequences

- A reserved word fails at registration, not as a syntax error during the install.
- `InstallerTest::it_compiles_only_full_word_aliases_variables_and_object_names()` fails on a short identifier in a workbench map.
- An application still chooses its own aliases. The package checks only that they are valid.
