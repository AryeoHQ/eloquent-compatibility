# Schema

This namespace builds and runs the SQL of the lifecycle operations, and compiles the view. [Storage](../../../../../../docs/specification/storage.md) states the objects it creates, and [Lifecycle](../../../../../../docs/specification/lifecycle.md) states what each operation runs. Every class here carries `@internal`.

## From an operation to its statements

`Compatibility::prepare()`, `Compatibility::update()` and `Compatibility::retire()` compile their statements and run them through `Installer::compileAndRun()`. `Compatibility::install()` and `Compatibility::rollback()` call `Installer::install()` and `Installer::rollback()`, which run passes, proofs and retries around the compiled statements.

`Statements` holds one operation's SQL, in parts:

| Part | What it holds |
| :-- | :-- |
| `transaction` | the statements that run in the operation's one transaction |
| `afterwards` | the statements that run after the commit, such as a foreign key validation |
| `steps` | the PHP steps that run inside the transaction, such as rollback's refill of a `computedAs()` column |
| `hooks` | the `ManagesDependents` hooks around the swap |
| `locks` | each lock the operation takes, with its mode and table |
| `notes` | what `compatibility:sql` prints beside the SQL |

`Statements::prepare()`, `Statements::install()`, `Statements::update()`, `Statements::rollback()` and `Statements::retire()` build each operation's `Statements`. `compatibility:sql` prints the same object, so the printed SQL is the SQL the operation runs. `Statements::guard()` adds a check to that printed SQL: it refuses when the catalog changed after the command printed it.

`Preparer` builds prepare's statements: the successor key's unique index, the `atMostOne()` indexes, the backfill tables and the model's `compatibility_backfills` row. `Preparer::unfinished()` refuses an install while that work is incomplete, or while an `atMostOne()` index is not the index `prepare()` builds. `Preparer::current()` makes the same refusal for `update()` and the rollback refill.

`Statements::dependents()` lists every object that depends on the legacy table. `Statements::refuseUndeclaredDependents()` refuses an install when one exists and the map declares no `dependents()` class.

## Install and rollback

`Installer::install()` runs [install](../../../../../../docs/specification/lifecycle.md#install) in two phases.

Before the lock, it holds the model's advisory lock. It runs a full pass when no pass has completed, then runs passes until one changes the outcome of fewer rows than the pass size. It runs the deletions, the install gate, and the existence and value proofs, and it catches up the keys the proofs flag.

Under the lock, it sets `statement_timeout` and checks the elapsed time against the budget before each step. It runs the final pass, the gate again, the native-root inserts, the swap and the dependent hooks. The successor key's foreign key is its last statement, and install retries that statement on a lock timeout or a deadlock.

`Installer::rollback()` runs the rollback statements in one transaction, retries on a lock timeout or a deadlock, then runs the statements that follow the commit.

## The view

`ViewCompiler` compiles the view from a resolved map. The view reads the retained table, and joins the root on the successor key with `ViewCompiler::root()`. `ViewCompiler::joins()` joins each related successor: plainly where `ViewCompiler::proven()` finds a unique index that proves one row, and laterally otherwise. `ViewCompiler::columns()` serves each legacy column, as an attribute's read or as a retained column.

`ViewCompiler::create()` and `ViewCompiler::replace()` build the view for install and for `update()`. `ViewCompiler::beforeInstall()` builds the same `SELECT` over the legacy table, for the backfill's round trip before the swap. `ViewCompiler::unhidden()` drops the `hide()` conditions, for the round trip of a record that a write hid.

## The catalog

- **`ServedTable`** answers whether a legacy name is the installed view on a connection, and caches the answer. `ServedTable::write()` routes a legacy write to the native table or to the writers.
- **`ColumnCatalog`** reads each table's columns, types, defaults and nullability, and `Column` holds one of them. `ColumnCatalog::leadsAnIndex()` tells whether an index starts with a column.
- **`SqlFragments`** quotes identifiers and literals, binds values, and classifies SQL types for the conversions.
