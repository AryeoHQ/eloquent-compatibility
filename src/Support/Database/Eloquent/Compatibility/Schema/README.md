# Schema

This namespace is the database half of the package. `Compiler` turns a resolved map into the compatibility view and its trigger functions, and `Installer` runs that SQL. The triggers route every write to the table that owns each value, whatever client sends it.

Entry points:

- `Installer::install()`, `Installer::recompile()` and `Installer::uninstall()` change the database, each in one transaction. Install and recompile plan a map's planner statistics and indexes from the catalog: they build what is missing, rebuild a statistics object whose expressions changed or that another table holds, refuse an invalid index, then analyze and write each finished object's marker comment, but only when an object lacks its marker. An uninstall drops them.
- `Installer::prepare()` plans only the planner statistics and indexes, the same way, and builds with `CREATE INDEX CONCURRENTLY`. It runs outside a transaction, one statement at a time, ahead of the install migration or of a recompile that adds or renames an index or changes a zoned attribute, so the install or recompile lists no planner statement and no `ANALYZE`. A prepare stopped part way leaves an object without its marker, which the next run finishes.
- `Installer::statements()` returns the statements one of those methods runs, named by an `InstallerAction`, without running them. The `compatibility:sql` command prints them, with `--for=prepare` among its actions.
- `Compiler::compile()` returns the `CompiledSql` without running it. Its `CompiledSql::$planner` holds the planner list, after the triggers. `Compiler::prepare()` returns that list for a prepare. Both read the catalog to plan it and run nothing.

`ColumnCatalog` reads the catalog, and `SqlFragments` quotes names, converts `AT TIME ZONE` and raises refusals. `SqlState`, `Setting` and `TriggerFunction` name the codes, settings and function suffixes the SQL uses.

## Concurrency revision handling

The compiler owns the `concurrency_revision` column of every table a map writes. A compiled mirror `INSERT` into such a table sets that column to 1, whatever a mapped attribute, a retained attribute or a fill gives it. So the column may be `NOT NULL` without a default. A compiled mirror `UPDATE` drops any assignment to the column. It raises the revision by 1 only when the statement changes a stored value. That includes the `UPDATE` that sets a designation column.

The `UPDATE` compares each stored value by its `to_jsonb()` form, as the refusing trigger does. So an `interval` that changes from `1 mon` to `30 days` raises the revision, although SQL calls the two values equal. The `UPDATE` computes each mapped value once per row, so a volatile mapping such as `nextval()` runs once.

A change to `updated_at` or to a `*_concurrency_revision` column alone raises nothing. A change to `deleted_at` raises the revision. A value that a mapping stores as the same number, such as a rounded price, raises nothing. A map may still expose the column through the view, and a write through the view cannot set it. So the mirrored write passes a refusing trigger that enforces application-raised revisions, as `refuse_unraised_concurrency_revision()` from eloquent-concurrency does.

An insert through the view may omit an exposed revision, and an update may set it to null, even where the retained column is `NOT NULL`. `UPDATE ... RETURNING` through the view reports the revision the trigger stored, not the value the write gave.

The compiler reads the catalog when it compiles a map. Recompile the map with `Installer::recompile()` after a mapped table gains or loses `concurrency_revision`, and after you upgrade to this release. Until then, every mirrored write into a table that gained the column fails its refusing trigger.

Mirroring into an ordered item table's watched columns is refused at commit by eloquent-ordering's deferred check, because the compatibility map does not raise the order revision.

Flows: [compiling and installing the schema](../../../../../../docs/flows/compiling-and-installing-the-schema.md), [writing through the view](../../../../../../docs/flows/writing-through-the-view.md) and the refusals in [failure and rollback](../../../../../../docs/flows/failure-and-rollback.md).

[Invariants](../../../../../../docs/invariants.md) held here: INV-1 on the trigger side, INV-3, INV-4, INV-5, INV-7, INV-9 on the cleanup side, INV-11, INV-13, INV-14, INV-23, INV-24, INV-26, INV-36 to INV-42, INV-44 and INV-52.
