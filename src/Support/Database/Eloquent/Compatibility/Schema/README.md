# Schema

This namespace is the database half of the package. `Compiler` turns a resolved map into the read-only compatibility view, after checking the map against the catalog, and `Installer` creates it. The database holds no trigger and no function: the write router splits every write in PHP.

Entry points:

- `Installer::install()`, `Installer::recompile()` and `Installer::uninstall()` change the database, each in one transaction. Each drops the triggers and functions an earlier version installed.
- `Compiler::compile()` returns the `CompiledSql` without running it.

`ColumnCatalog` reads the catalog for the compiler and the writers, and `SqlFragments` quotes names.

Flows: [compiling and installing the schema](../../../../../../docs/flows/compiling-and-installing-the-schema.md).

[Invariants](../../../../../../docs/invariants.md) held here: INV-9, INV-10, INV-13, INV-23 to INV-27, INV-29 and INV-30.
