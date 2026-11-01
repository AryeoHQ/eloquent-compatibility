# Adopting a map

This page is for the pull request that adds a compatibility map to an application. Each section names one change to the application's code or tests once a model is mapped. It says why the change happens, and what the pull request does about it.

## Audit every write that bypasses Eloquent

The package installs no trigger, so only writes through a mapped model's Eloquent builder are split and kept in step, as [decision 0024](decisions/0024-writes-are-split-in-php-without-triggers.md) records. Before the install, find every other writer of the old table and of each current table:

- DB::table('<old table>'), and raw SQL through DB::statement('...') or DB::unprepared('...'), that write the old table. After the install they reach the read-only view and fail with PostgreSQL's "cannot insert into view", "cannot update view" or "cannot delete from view". Rewrite each one through the previous model or its query builder.
- DB::table('<current table>') and raw SQL that insert or delete primary current rows, or write a table a refused state checks. They succeed, but create no retained row, delete none, fill no designation and check no refused state. Rewrite them through the current model, or accept the drift `compatibility:check` reports.
- Other services, scripts, console sessions and BI tools that write these tables directly. They follow the same two rules.
- Test fixtures that insert rows with DB::table('...'). A fixture of the old table fails; a fixture of a primary current table creates a record the previous model cannot see.

A search such as `grep -rn "table('<old table>')"` over the application finds most of them.

## Mark each mapped model

Every model a map names, the previous model and each current model, implements `Compatible` and uses `HasCompatibility`. The trait gives the model an Eloquent builder that routes its writes, and marks the model's own writes so the router can tell them from query builder writes.

The trait overrides three methods of `Model`, and each override calls its parent first:

- `HasCompatibility::newEloquentBuilder()` returns the model's builder when it uses `RoutesCompatibleWrites`, a `CompatibleBuilder` when the model names no builder, and throws for any other builder.
- `HasCompatibility::setKeysForSaveQuery()` marks the builder of the model's own update or delete.
- `HasCompatibility::getAttributesForInsert()` marks the model's own insert.

A model with a custom builder names it through Laravel's `Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder` attribute or its `$builder` property, and the builder uses `RoutesCompatibleWrites`, or extends `CompatibleBuilder`. A model that declares `newEloquentBuilder()`, `setKeysForSaveQuery()` or `getAttributesForInsert()` itself, takes one from another trait, or extends a class that does, cannot be mapped. `WriteRouter::register()` refuses it, and the `HasCompatibilityMustNotRedeclareWriteHooks` PHPStan rule reports it earlier. [INV-21](invariants.md#inv-21-every-mapped-model-routes-its-writes) records the rule.

## Register each map

A service provider registers each map with the write router, through an event mirror:

```php
resolve(WriteRouter::class)->register(new WidgetEventMirror);
```

The registration reads no database, so it is safe in `boot()`. Until a map is registered, its previous model cannot write, because its writes reach the read-only view. Register every map in every process that writes: web, queue workers, console commands and tests.

## Move write expressions from SQL to PHP

A map's writes are PHP closures. Each receives the previous model's raw attributes after the write, as the database stores them, not cast:

| Declaration | Receives | Returns |
| :-- | :-- | :-- |
| `Attribute::writesTo()` | the record's attributes after the write | the column's raw value |
| `PrimaryCurrentModel::fillWhenCreatedFromPrevious()`, `DependentCurrentModel::fillWhenCreatedFromPrevious()` | the inserted attributes | the column's value, or null to leave it to the default |
| `PreviousModel::fillWhenCreatedFromCurrent()` | the primary current model, as stored | the retained column's value, or null to leave it to the default |
| `DependentCurrentModel::createdWhen()` | the record's attributes after the write | whether to create the row |
| `UnrepresentableState::checkedOnPreviousUpdate()` | the attributes after, then before | whether to refuse |

Reads stay SQL: `Attribute::readAs()`, `DependentCurrentModel::readableWhen()`, `UnrepresentableState::checkedOn()` and the condition of `UnrepresentableState::hide()`. A map written for an earlier version converts each `NEW.<column>` expression into a closure over `$attributes['<column>']`.

## Move an installed map onto the write router

A map an earlier version installed carries `INSTEAD OF` triggers on its view and triggers on its current tables. One migration moves it over:

```php
(new Installer(DB::connection()))->recompile(new WidgetCompatibilityMap);
```

`Installer::recompile()` drops every trigger and function with the map's legacy prefix, and creates the view again. Deploy the registration with the migration, because a previous model cannot write between the two.

## Before the install: views on the old table

A view on the old table follows the table's rename, so it keeps reading the retained table. It then misses every mapped attribute. So `Installer::install()` refuses while another view or materialized view reads the retained table, and names each one, as [INV-25](invariants.md#inv-25-only-the-compatibility-view-reads-the-retained-table) records.

Redirect each such view in the migration that installs the map:

1. Drop the view after the rename, and before `Installer::install()`.
2. Install the map.
3. Create the view again over the compatibility view, which now has the old table's name.

A view over the compatibility view blocks a later recompile in the same way. `Installer::recompile()` drops the compatibility view and creates it again, so it refuses while another object depends on it. A migration that recompiles drops those objects first and creates them again after.

## What the install and the registration refuse

The install checks the tables before it changes anything, and `WriteRouter::register()` checks the models before it registers a map. Each refusal is an `InvalidCompatibilityMap`.

The install refuses:

- **Row-level security.** The view reads with its owner's rights, so it would bypass the table's policies. See [INV-26](invariants.md#inv-26-the-schema-refuses-a-table-with-row-level-security).
- **A required column nothing fills.** Every `NOT NULL` column without a default needs a value on each insert path. Fill a retained column with `PreviousModel::fillWhenCreatedFromCurrent()`, and a current column with an attribute or `PrimaryCurrentModel::fillWhenCreatedFromPrevious()`. See [INV-27](invariants.md#inv-27-every-insert-fills-each-required-column).
- **A writable attribute on a generated column.** Declare such an attribute read-only with `Attribute::readOnlyBecause()`. See [INV-29](invariants.md#inv-29-a-generated-column-is-read-only).
- **A table outside the installation schema.** Run the migration with that schema first on the search path. See [INV-30](invariants.md#inv-30-a-maps-tables-resolve-in-the-current-schema-alone).

The registration refuses a Parental model, a pivot model, a model without the mark, a model in two roles, and a map registered twice, as [declaring and registering a map](flows/declaring-and-registering-a-map.md#registering) lists.

## After the install: backfill the designations

A dependent declared with `DependentCurrentModel::designatedBy()` reads only the row its designation names. The router fills an empty designation when a write through a model makes a dependent row readable. A record that exists before the install gets no such write, so its designation stays empty.

In the migration that installs the map, fill each empty designation with the row the view would read: the first readable row by key. `compatibility:check` reports every empty designation whose record has a readable row. [INV-12](invariants.md#inv-12-a-designation-pins-the-dependent-row) records the rule.

## Current models' listeners now run for writes through the previous model

A write through the previous model saves each current model through Eloquent, so the current model's own listeners and observers run, before its row is written. A listener may change a value, and the stored value follows it. A listener that returns false vetoes the whole write with `CompatibilityWriteRefused`. [INV-16](invariants.md#inv-16-a-current-models-own-listeners-run-before-its-row-is-written) records the rule.

Review each current model's listeners for writes they now see. A listener that must not run for writes through the previous model checks `WriteRouter::applyingMap()` for its model, or the application moves its logic elsewhere.

The previous model's events still fire natively for its own writes. For a write of a current model, the router replays the previous model's events after the write, and those mirrored listeners can refuse but not change the record. Exclude a listener from mirrored events with `ExcludedListener` in the map's event mirror.

## Query builder writes run record by record

`Widget::query()->where(...)->update([...])` selects the matching keys, then locks, reads and writes each record. It fires no model events on either side, as Eloquent's own mass writes fire none. Three things change:

- A raw SQL value, such as DB::raw('price * 2'), cannot be split across the current models, and refuses with `CompatibilityWriteRefused::rawExpression()`. Compute the value in PHP, or use `increment()`, which the router computes from the locked value.
- `upsert()`, `insertUsing()` and `insertOrIgnoreUsing()` refuse on the previous model, and on a current model whose rows need a retained row, a designation or a refused-state check.
- A large mass write costs a few queries per record. Chunk it where that matters.

`insertOrIgnore()` and `saveOrIgnore()` skip a conflicting row, as they did on the old table.

## Tests that fake every event

`Event::fake()` no longer blocks a mapped write. The write still splits and stays in step, but the faked dispatcher records the current models' events and the mirrored events without running their listeners. A test that asserts on mirrored events fakes only the events it asserts, or uses `InteractsWithCompatibilityMaps::recordMirroredEvents()`.

## Tests at transaction level 0

`Illuminate\Foundation\Testing\RefreshDatabase` wraps each test in a transaction, so the router always works in a savepoint there. Production web requests and queue jobs usually run at level 0, where the router's transaction commits on its own. Add a test class per map that uses `Illuminate\Foundation\Testing\DatabaseTruncation`, or that turns off the test transaction, and write through both sides outside a transaction.

## A lost connection is not retried at level 0

At transaction level 0, Laravel reconnects and retries a statement once when the connection drops. A mapped write gives that retry up, because the router's transaction runs its statements at level 1. [Decision 0021](decisions/0021-a-mapped-write-at-level-0-gives-up-the-lost-connection-retry.md) records the trade-off.

## Jobs dispatched inside a mapped write

At level 0, the router's transaction commits only after the current models' listeners and the mirrored listeners have run, while it still holds the records' row locks. A queue connection with `after_commit` set to false pushes a job at once. A worker can then pick the job up before the row it reads has committed, and read the old row or wait on the lock.

Make each job such a listener dispatches run after the commit: call `afterCommit()` on the dispatch, or implement `Illuminate\Contracts\Queue\ShouldQueueAfterCommit` on the job.
