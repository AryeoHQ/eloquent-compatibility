# 0024. Writes are split in PHP, without triggers

## Context

Until this decision the package split every write to the previous model in the database. `INSTEAD OF` triggers on the compatibility view wrote each value to the table that owns it. Triggers on the current tables created and deleted retained rows, filled designations and refused unrepresentable states. A PHP event bridge then replayed the other side's model events after each statement.

That design caught every writer, Eloquent or raw SQL. Its costs grew with each review:

- **Events came after the data.** The current models never ran their own save, so the bridge replayed their events after the statement. A listener could refuse a write but not change it ([INV-15](../invariants.md#inv-15-a-mirrored-listener-may-refuse-a-write-never-change-it) at the time), and state machines needed a veto guard.
- **The bridge had to find the model's own statement.** Laravel hands the connection SQL, not a model. Decision 0023 marked the statement through three overridden model methods, and the bridge kept its own transaction and savepoint bookkeeping around it.
- **Mapping logic lived in SQL strings.** Each write expression compiled into `SECURITY DEFINER` PL/pgSQL, which brought the schema-trust requirement, the row-level-security refusal, and a lock and conflict-check protocol written twice, once in PHP and once in SQL.

The user asked on 2026-11-01 whether the split could happen in the application, through the current models themselves, and then chose to implement it with zero database triggers.

## Decision

The package installs no trigger and no function. Each write is split in PHP, through the real Eloquent models.

- **The view is read-only.** `Compiler::compile()` emits one `CREATE VIEW`. The view joins two tables, so PostgreSQL refuses an `INSERT`, `UPDATE` or `DELETE` through it. A write that bypasses Eloquent fails loudly instead of being split wrongly.
- **Every write of a mapped model's builder reaches the write router.** `HasCompatibility::newEloquentBuilder()` gives each mapped model a builder that uses `RoutesCompatibleWrites`. The trait overrides every write method of the Eloquent builder, and hands each to `WriteRouter`.
- **A write through the previous model becomes saves of the current models.** `PreviousWriter` locks the record, reads it through the view, computes each changed column with the map's PHP closures, and saves each current model through Eloquent. On a model's own write those saves fire the current models' own events. A query builder write saves them quietly, because Eloquent's own mass writes fire no events.
- **A write of a current model keeps the previous models in step.** `CurrentWriter` does what the current-side triggers did, and replays the previous model's events for a model's own write.
- **Write expressions are PHP closures.** `Attribute::writesTo()`, the fills, `DependentCurrentModel::createdWhen()` and `UnrepresentableState::checkedOnPreviousUpdate()` take closures. Reads stay SQL, because the view runs them. Readable conditions and refused-state conditions stay SQL too, because the writers run them as queries.
- **Laravel's transactions wrap each write.** `WriteRouter` runs each routed write inside `Connection::transaction()`, which nests as a savepoint inside a caller's transaction.
- **A recompile moves an installed map over.** `Installer::recompile()` drops every trigger and function with the map's legacy prefix, `ResolvedMap::legacyObjectPrefix()`.

## Declined: a hook on the database connection

The question first named the connection: intercept the statement before it runs, and write the models instead. `Connection::beforeExecuting()` cannot cancel a statement, and the connection sees only SQL and bindings, never the model. Decision 0023 had already declined a link from the connection alone for that reason. The builder sees the model, the values and the conditions, so the router lives there.

## Declined: keeping the triggers as a fallback

Keeping the `INSTEAD OF` triggers for raw SQL would hold every write rule twice, once in PHP and once in SQL, and the two would drift apart. The view refuses those writes instead.

## Consequences

- **Coverage narrows to Eloquent.** Raw SQL through the view fails. Raw SQL on a current table skips the retained row, the designation and the refused-state check; `compatibility:check` reports what it leaves behind. Another service that writes these tables directly must write through the application, or accept that drift.
- **The current models' listeners run before their rows are written.** A listener may change a value, and the stored value follows it. A listener that vetoes a save refuses the whole write with `CompatibilityWriteRefused::byCurrentListener()`.
- **Events fire once, natively, on both sides of a write through the previous model.** Only a write of a current model replays the previous model's events, through `EventReplayer`, with [0006](0006-mirrored-events-are-replayed-after-the-statement.md)'s limits.
- **The router locks before it reads.** A write reads the record only after it holds its rows, so the old conflict check and SQLSTATE 40001 are gone. A stale in-memory model can still overwrite a column another writer changed, as plain Eloquent can.
- **A mass write runs record by record.** `Widget::query()->where(...)->update()` selects the matching keys, then locks, reads and writes each record. Each record's condition is checked again under its lock. A raw SQL value in such a write cannot be split, and `CompatibilityWriteRefused::rawExpression()` refuses it.
- **Set-based inserts are refused where a row needs work.** `upsert()`, `insertUsing()` and `insertOrIgnoreUsing()` refuse on a previous model, and on a current model whose rows need a retained row, a designation or a refused-state check. `insertOrIgnore()` works row by row, and skips a conflicting row as the old table did.
- **Schema trust and row-level security relax.** No `SECURITY DEFINER` function runs, so the installation schema no longer needs locking down for the package's sake. Writes reach the current tables under the application's own role, so their row-level-security policies apply. The view still reads with its owner's rights, so the installer still refuses a mapped table with row-level security.
- **The view copies only `SELECT` grants.** A write grant on a view no one can write would mislead.
- **A mapped model cannot name its own builder through `newEloquentBuilder()`.** It names one through Laravel's `Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder` attribute or `$builder` property instead, and that builder uses `RoutesCompatibleWrites`. `WriteRouter::register()` refuses a model that declares the method itself.
- **Superseded decisions.** This decision supersedes 0001 (CM001), 0002 (40001), 0003 (the bridge locks before the statement), 0004 (the bridge's savepoints), 0005 (releasing a failed statement), 0008 (the applying flag) and 0023 (marking the model's own statement). Their files were removed; git history keeps them.
