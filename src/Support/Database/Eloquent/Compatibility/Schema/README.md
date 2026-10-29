# Schema

This namespace is the database half of the package. `Compiler` turns a resolved map into the compatibility view and its trigger functions, and `Installer` runs that SQL. The triggers route every write to the table that owns each value, whatever client sends it.

Entry points:

- `Installer::install()`, `Installer::recompile()` and `Installer::uninstall()` change the database, each in one transaction.
- `Installer::statements()` returns the statements one of those methods runs, named by an `InstallerAction`, without running them. The `compatibility:sql` command prints them.
- `Compiler::compile()` returns the `CompiledSql` without running it.

`ColumnCatalog` reads the catalog, and `SqlFragments` quotes names and raises refusals. `SqlState`, `Setting` and `TriggerFunction` name the codes, settings and function suffixes the SQL uses.

Flows: [compiling and installing the schema](../../../../../../docs/flows/compiling-and-installing-the-schema.md), [writing through the view](../../../../../../docs/flows/writing-through-the-view.md) and the refusals in [failure and rollback](../../../../../../docs/flows/failure-and-rollback.md).

[Invariants](../../../../../../docs/invariants.md) held here: INV-1 on the trigger side, INV-3, INV-4, INV-5, INV-7, INV-9 on the cleanup side, INV-11, INV-13, INV-14, INV-23, INV-24, INV-26, INV-36 to INV-42 and INV-44.
