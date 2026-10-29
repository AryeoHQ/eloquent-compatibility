# Adopting a map

This page is for the pull request that adds a compatibility map to an application. Each section names one change to the application's code or tests once a model is mapped. It says why the change happens, and what the pull request does about it.

## Mark each mapped model

Every model a map names, the previous model and each current model, implements `Compatible` and uses `HasCompatibility`. The mark lets the event bridge tell the model's own statement from a listener's, and shows the bridge every model event.

The trait overrides three protected methods of `Model`, and each override calls its parent first. Laravel calls `Model::setKeysForSaveQuery()` and `Model::getAttributesForInsert()` only inside the model's own write method, so a statement that passes through them is the model's own. `Model::fireModelEvent()` shows the bridge every model event of the model, whatever its listeners return. [Decision 0023](decisions/0023-a-mapped-model-marks-its-own-statement.md) records why the mark lives on the model.

Two kinds of model cannot carry the mark:

- **A model that declares any of the three methods itself**, takes one from another trait, or extends a class that does. PHP prefers the class's method to the trait's, so the statement goes unmarked, or the bridge never sees the model's events. `EventBridge::mirror()` refuses such a model with `InvalidCompatibilityMap`. The `HasCompatibilityMustNotRedeclareWriteHooks` PHPStan rule reports a named class whose method resolves anywhere but `HasCompatibility` earlier, at analysis time. A composite-key package that sets the save query's keys is one example.
- **A model whose own write method sends its statement through neither marking method**, such as an override of `performUpdate()` that builds its own query. The bridge cannot see that at registration. The write's created, updated or deleted event throws `CompatibilityEventMirrorBypassed`, which names the model and the event. Inside a transaction, the throw rolls the write back. Outside one, the write has already committed.

[INV-50](invariants.md#inv-50-every-mapped-model-marks-its-own-statement) records the rule.

## Before the first mirrored write: the connection class

The event bridge refuses to mirror a write on a connection whose class does not use `Connections\DispatchesQueryFailed`. The refusal is a `CompatibilityConnectionUnsupported` that names the connection, its class and the trait. `CompatibilityWriteRefused::isRefusal()` rejects it, so a test that expects a refusal fails rather than passes. It comes at the first mirrored write on that connection, not when the application registers the map. [INV-19](invariants.md#inv-19-a-failed-write-at-level-0-releases-its-transaction) records the rule.

When a statement fails, the trait calls the bridge before it raises `QueryFailed`, and the bridge rolls back the transaction it opened for a write outside a transaction. Without it, the next `Connection::transaction()` or `createOrFirst()` would fail with 25P02, and a `Connection::afterCommit()` callback could be lost.

Add the trait to the connection class the application already resolves, such as Aryeo's ResilientPostgresConnection. The trait overrides `handleQueryException()`, `beforeExecuting()` and `runQueryCallback()`, so it composes with a class that overrides `run()` or the lost-connection retry. Its `beforeExecuting()` runs the event bridge's first callback before every other one, and its last after every other one. So a statement another callback runs never takes the write the bridge armed, and runs before the write opens. The bridge refuses a class that declares `beforeExecuting()`, `runQueryCallback()`, `handleQueryException()` or `registerEventBridge()` itself, and names that class. An application that resolves no class of its own registers one with `Connection::resolverFor('pgsql', …)` in a service provider's `register()`, as the README's "Failed statements" section shows.

## Before the install: views on the old table

A view on the old table follows the table's rename, so it keeps reading the retained table. It then misses every mapped attribute, and the triggers never see its writes. So `Installer::install()` refuses while another view or materialized view reads the retained table, and names each one, as [INV-42](invariants.md#inv-42-only-the-compatibility-view-reads-the-retained-table) records.

Redirect each such view in the migration that installs the map:

1. Drop the view after the rename, and before `Installer::install()`.
2. Install the map.
3. Create the view again over the compatibility view, which now has the old table's name.

A view over the compatibility view blocks a later recompile in the same way. `Installer::recompile()` drops the compatibility view and creates it again, so it refuses while another object depends on it. A migration that recompiles drops those objects first and creates them again after. [INV-41](invariants.md#inv-41-a-recompile-replaces-the-view-and-keeps-its-grants) records that rule.

## Before the install: who can create objects in the installation schema

Only trusted roles may create objects in the installation schema, and the package does not check this. The compiled trigger functions are `SECURITY DEFINER`, so they run with their owner's rights. Each one looks names up in the installation schema, then in `pg_temp`. PostgreSQL picks a function or an operator by its closest argument match, whatever the schema. So a function or an operator in the installation schema can replace a built-in one, and the trigger then runs it with the owner's rights.

The compiled SQL calls each built-in function through `pg_catalog`. Its operators, and the functions and operators in the map's own expressions, stay unqualified. [INV-37](invariants.md#inv-37-a-trigger-function-runs-only-for-its-own-relation) records the rule.

Before the install, check which roles hold `CREATE` on the installation schema. PostgreSQL 14 grants it on `public` to every role by default, and PostgreSQL 15 and later do not. This statement withdraws it:

```sql
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
```

## What the install and the mirror refuse

The install checks the tables before it changes anything, and `EventBridge::mirror()` checks the models before it registers a map. Each refusal is an `InvalidCompatibilityMap`.

The install refuses:

- **Row-level security.** The view and its `SECURITY DEFINER` triggers would bypass the table's policies, so a table with row-level security cannot be mapped yet. See [INV-38](invariants.md#inv-38-the-schema-refuses-a-table-with-row-level-security).
- **A required column nothing fills.** Every `NOT NULL` column without a default needs a value on each insert path. Fill a retained column with `PreviousModel::fillWhenCreatedFromCurrent()`, and a current column with an attribute or `PrimaryCurrentModel::fillWhenCreatedFromPrevious()`. See [INV-39](invariants.md#inv-39-every-insert-fills-each-required-column).
- **A writable attribute on a generated column.** The database rejects every write to a generated column and to a `GENERATED ALWAYS` identity column. Declare such an attribute read-only with `Attribute::readOnlyBecause()`, and the insert leaves it out. A `GENERATED BY DEFAULT` identity stays writable, and an insert through the view that omits it takes the identity's next value. See [INV-45](invariants.md#inv-45-a-generated-column-is-read-only-through-the-view).
- **A table outside the installation schema.** The compiled functions run in `current_schema()`, so every mapped table must be there. A table found only further down the search path refuses with "does not exist". Run the migration with that schema first on the search path. See [INV-47](invariants.md#inv-47-a-maps-tables-resolve-in-the-current-schema-alone).

The mirror refuses:

- **A Parental model.** A class that uses Parental's HasChildren trait has children that fire their events under their own class names. See [INV-43](invariants.md#inv-43-the-event-bridge-refuses-a-parental-model).
- **A model without the mark.** That is a model that lacks `Compatible` or `HasCompatibility`, or declares its own `setKeysForSaveQuery()`, `getAttributesForInsert()` or `fireModelEvent()`. See [mark each mapped model](#mark-each-mapped-model).
- **A pivot model.** That is an Eloquent Pivot, or any model that uses the `Illuminate\Database\Eloquent\Relations\Concerns\AsPivot` trait. Its delete of a pivot loaded without its key sends a statement neither marking method sees. So the delete would reach the database before the bridge refused it. Map a plain `Model` over the pivot table instead. See [INV-50](invariants.md#inv-50-every-mapped-model-marks-its-own-statement).
- **A model in both current roles.** That is a model that one mirrored map makes its primary current model and another a dependent current model. The two maps lock that model's row at different points of their order, so a bridged write can deadlock a write through the other view. Map the model in one of those roles only. See [INV-1](invariants.md#inv-1-locks-follow-one-order).

## After the install: backfill the designations

A dependent declared with `DependentCurrentModel::designatedBy()` reads only the row its designation names. The designate trigger fills an empty designation when an insert or an update makes a dependent row readable. A record that exists before the install gets no such write, so its designation stays empty.

In the migration that installs the map, fill each empty designation with the row the view would read: the first readable row by key. `compatibility:check` reports every empty designation whose record has a readable row. [INV-12](invariants.md#inv-12-a-designation-pins-the-dependent-row) records the rule.

## Guard each column trigger on a mapped column

An update through the view fires every `UPDATE OF` trigger on a mapped column of each table it writes, whether or not the value changed. PostgreSQL fires such a trigger whenever the statement's `SET` list names its column. The view's update trigger assigns every mapped column of each current table it writes, and every written column of the retained table. An unchanged column gets its own stored value.

So an update through the view that changes only `title` fires an `UPDATE OF price_cents` trigger on `gadgets`. A trigger that audits price writes then records a write that never changed the price, and a trigger that refuses price writes refuses it. The event bridge compares rows, so its events are unaffected. [INV-7](invariants.md#inv-7-the-update-trigger-writes-only-what-changed) records the rule.

`compatibility:check` warns of each such trigger that has no `WHEN` clause, on a current table or the retained table. The warning names the trigger, its table and its mapped columns, and the command's exit status stays the same. A trigger with any `WHEN` clause counts as guarded. The check skips internal triggers and every `compatibility__` trigger a map installs.

Guard each trigger with `WHEN (OLD.<column> IS DISTINCT FROM NEW.<column>)`, one comparison for each column it names, joined with `OR`. An `INSERT OR UPDATE OF` trigger cannot take that clause, because the `WHEN` clause of an insert trigger cannot reference `OLD`. Split it into an insert trigger and a guarded update trigger. This migration splits an `AFTER INSERT OR UPDATE OF price_cents` trigger on `gadgets`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER audit_price ON gadgets;
            CREATE TRIGGER audit_price_insert AFTER INSERT ON gadgets
                FOR EACH ROW EXECUTE FUNCTION audit_price();
            CREATE TRIGGER audit_price_update AFTER UPDATE OF price_cents ON gadgets
                FOR EACH ROW WHEN (OLD.price_cents IS DISTINCT FROM NEW.price_cents) EXECUTE FUNCTION audit_price();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER audit_price_update ON gadgets;
            DROP TRIGGER audit_price_insert ON gadgets;
            CREATE TRIGGER audit_price AFTER INSERT OR UPDATE OF price_cents ON gadgets
                FOR EACH ROW EXECUTE FUNCTION audit_price();
            SQL);
    }
};
```

The column list of `INSERT OR UPDATE OF` limits only the update, so the insert trigger still fires on every insert, as the original did.

## Nullable columns keep no default on an insert

An insert through the view writes every column an attribute stores. When the insert omits the attribute, a `NOT NULL` column with a default takes its default. A nullable column stores NULL instead, even when it has a default. That holds for a dependent's column and for a `writesTo()` target. A retained or primary column that an attribute stores through `Attribute::storedAs()` takes the column's default as its view default. A column without a default of its own takes its domain's default. [INV-46](invariants.md#inv-46-an-insert-through-the-view-keeps-a-not-null-columns-default) records the rule.

## An ignored insert raises on a conflict

On the old table, `insertOrIgnore()` and `saveOrIgnore()` skip a row that conflicts with a unique constraint, and return 0 or false. Through the view, both raise `Illuminate\Database\UniqueConstraintViolationException` with SQLSTATE 23505. PostgreSQL accepts `ON CONFLICT DO NOTHING` on a view with an `INSTEAD OF` trigger, but the clause never reaches the trigger's own inserts. The trigger cannot see the clause, so it cannot skip the row either.

Find each call through a mapped model or its query builder. Check for the conflict before the insert, or catch the exception where the old code relied on the ignore. [Writing through the view](flows/writing-through-the-view.md#writes-the-view-cannot-take) lists the other writes the view cannot take.

## Tests that fake every event

Once a model is mapped, a test that writes it under `Event::fake()` or `Event::fakeExcept()` throws `CompatibilityEventMirrorBypassed`. The throw comes before the statement, so the write never reaches the database. The fake swallows the beginning event, so no listener runs and the bridge forgets the write it began. The test then proves nothing about the other side. The guard stays on by default, because it stops mirrored events from silently going missing. [INV-21](invariants.md#inv-21-a-faked-dispatcher-cannot-hide-a-mirrored-write) records it.

The pull request that adds each map moves every test it touches to one of two forms:

- `Event::fake()` given a list of only the events the test asserts, which leaves the model events alone.
- `EventBridge::withoutMirroring()` around the write, or `InteractsWithCompatibilityMaps::withoutEventMirroring()` in a test, which switches the mirror off on purpose.

## State changes and eloquent-state-machines

A write through the previous model that moves a current model's state column runs that state machine's trigger, as the [README](../README.md) describes. The bridge does that only when the installed `aryeo/eloquent-state-machines` has `Trigger::withoutTransition()`. So the upgrade to the first such release changes what those writes do, and the pull request that makes it reviews them as a behaviour change.

A test that writes such a state under a bus fake that fakes the trigger throws `CompatibilityEventMirrorBypassed`, and the bridge rolls the write back. `Illuminate\Support\Facades\Bus::fake()` with no list fakes every trigger. Give it a list of only the jobs the test asserts, or wrap the write in `EventBridge::withoutMirroring()`. [INV-21](invariants.md#inv-21-a-faked-dispatcher-cannot-hide-a-mirrored-write) records the rule.

## Tests at transaction level 0

`Illuminate\Foundation\Testing\RefreshDatabase` wraps each test in a transaction. Inside it, the bridge always works in savepoint mode, so a suite that uses only that trait never runs the level-0 path. That path opens the bridge's own transaction, commits it, and releases it after a failed statement. Production web requests and queue jobs usually take it.

Add a test class per map that uses `Illuminate\Foundation\Testing\DatabaseTruncation`, or that turns off the test transaction, and write through both sides outside a transaction. `EventBridgeOutsideTransactionTest` in this package shows the shape: its `connectionsToTransact()` returns no connection.

## A lost connection is not retried at level 0

At transaction level 0, Laravel reconnects and retries a statement once when the connection drops. A mapped write gives that retry up. The bridge opens its own transaction when the write's statement starts, so the statement runs at level 1, where `Connection::handleQueryException()` rethrows instead of retrying.

A mapped write that used to survive a dropped connection now fails, and so do the bridge's own reads. A connection class that hands non-idempotent writes to that retry loses it for mapped writes. [0021](decisions/0021-a-mapped-write-at-level-0-gives-up-the-lost-connection-retry.md) records the trade-off.

## Jobs dispatched by mirrored listeners

At level 0, the bridge replays the other side's events before it commits its transaction, while it still holds the records' row locks. A queue connection with `after_commit` set to false pushes a job at once. A worker can then pick the job up before the row it reads has committed, and read the old row or wait on the lock.

Make each job a mirrored listener dispatches run after the commit: call `afterCommit()` on the dispatch, or implement `Illuminate\Contracts\Queue\ShouldQueueAfterCommit` on the job. Inside a caller's transaction, the job then waits for the caller's commit too.
