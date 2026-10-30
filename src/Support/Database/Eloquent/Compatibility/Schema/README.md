# Schema

This namespace is the database half of the package. `Compiler` turns a resolved map into the compatibility view and its trigger functions, and `Installer` runs that SQL. The triggers route every write to the table that owns each value, whatever client sends it.

Entry points:

- `Installer::install()`, `Installer::recompile()` and `Installer::uninstall()` change the database, each in one transaction. Install and recompile plan a map's planner statistics and indexes from the catalog: they build what is missing, rebuild a statistics object whose expressions changed or that another table holds, refuse an invalid index, then analyze and write each finished object's marker comment, but only when an object lacks its marker. An uninstall drops them.
- `Installer::prepare()` plans only the planner statistics and indexes, the same way, and builds with `CREATE INDEX CONCURRENTLY`. It runs outside a transaction, one statement at a time, ahead of the install migration or of a recompile that adds or renames an index or changes a zoned attribute, so the install or recompile lists no planner statement and no `ANALYZE`. A prepare stopped part way leaves an object without its marker, which the next run finishes.
- `Installer::statements()` returns the statements one of those methods runs, named by an `InstallerAction`, without running them. The `compatibility:sql` command prints them, with `--for=prepare` among its actions.
- `Compiler::compile()` returns the `CompiledSql` without running it. Its `CompiledSql::$planner` holds the planner list, after the triggers. `Compiler::prepare()` returns that list for a prepare. Both read the catalog to plan it and run nothing.

`ColumnCatalog` reads the catalog, and `SqlFragments` quotes names, converts `AT TIME ZONE` and raises refusals. `SqlState`, `Setting` and `TriggerFunction` name the codes, settings and function suffixes the SQL uses.

## Concurrency revision handling

A compiled mirror `INSERT` into a table with a `concurrency_revision` column sets that column to 1. A compiled mirror `UPDATE` into such a table raises the revision by exactly 1. This allows the mirrored write to pass a refusing trigger that enforces application-raised revisions, as `refuse_unraised_concurrency_revision()` from eloquent-concurrency does.

Mirroring into an ordered item table's watched columns is refused at commit by eloquent-ordering's deferred check, because the compatibility map does not raise the order revision.

Flows: [compiling and installing the schema](../../../../../../docs/flows/compiling-and-installing-the-schema.md), [writing through the view](../../../../../../docs/flows/writing-through-the-view.md) and the refusals in [failure and rollback](../../../../../../docs/flows/failure-and-rollback.md).

[Invariants](../../../../../../docs/invariants.md) held here: INV-1 on the trigger side, INV-3, INV-4, INV-5, INV-7, INV-9 on the cleanup side, INV-11, INV-13, INV-14, INV-23, INV-24, INV-26, INV-36 to INV-42, INV-44 and INV-52.
