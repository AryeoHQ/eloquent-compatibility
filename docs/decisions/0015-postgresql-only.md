# 0015. PostgreSQL only

## Context

The package compiles PL/pgSQL trigger functions and reads PostgreSQL's catalogs. The Testbench command line otherwise passes its skeleton's SQLite default to the tests.

## Decision

The package supports PostgreSQL only, and the suite runs on PostgreSQL 14, 16 and 18. `Tests\TestCase` throws when the selected connection's driver is not pgsql. Every local and CI run selects the PostgreSQL connection explicitly.

## Consequences

- A run that selects SQLite fails at once, instead of passing against the wrong database.
- A test checks that the server's major version is the one `POSTGRES_VERSION` names.
- Every database feature must work on PostgreSQL 14.
