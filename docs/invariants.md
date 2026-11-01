# Invariants

Each invariant is a rule every change must keep. It names why the rule holds, and the code that must agree with it. The package installs no trigger, so each rule holds for writes that reach the write router: every write of a mapped model's Eloquent builder. [Decision 0024](decisions/0024-writes-are-split-in-php-without-triggers.md) records that boundary.

## INV-1: Locks follow one order

**Rule.** A write locks the primary row first, then the retained rows, then the dependent rows. A write of a dependent row locks its primary rows, before and after, before it locks the dependent row. Within one table, rows lock in key order. `WriteRouter::register()` refuses a model that one map makes its primary current model and another a dependent current model.

**Why.** Two writers that lock in one order cannot deadlock on these rows. A model in both current roles has no such order.

**Code that must agree.** `PreviousWriter::update()` and `PreviousWriter::delete()` call `Records::lockPrimaries()` before `Records::retainedRow()` and `Records::locate()`. `CurrentWriter::update()` and `CurrentWriter::delete()` lock primaries before their own rows. `Records::lock()` sorts the keys. `WriteRouter::register()` checks the roles through `MirrorIndex::forModel()`.

**Untested.** No test pins the order under concurrent writers.

## INV-2: One expression locates a dependent row

**Rule.** The view and the writers pick the same dependent row for a record: the designated row when the dependent declares a designation, else the first readable row by key.

**Why.** A write must change the row the previous model reads, and no other.

**Code that must agree.** `ResolvedMap::locate()` builds the view's subquery. `Records::locate()` builds the writers' query from the same parts: the primary foreign key, the designation, `DependentCurrentModel::readableWhen()`, the key order and `LIMIT 1`.

## INV-3: A previous model's own current saves skip that map's current-side work

**Rule.** While `PreviousWriter` saves a current model, that save creates no retained row, deletes none, fills no designation and mirrors no event for the writing map. It still does all of that for every other map over the same table, and it still checks refused states.

**Why.** The previous writer creates the retained row and the designation itself. Without the skip, an insert through the previous model would create two retained rows.

**Code that must agree.** `WriteRouter::applying()` marks the one model instance for the length of its save, and `CurrentWriter` reads the mark through `WriteRouter::applyingMap()`. A listener's own write inside that save is another instance, so it does all the work.

## INV-4: Every refusal is a `CompatibilityWriteRefused` inside the write's transaction

**Rule.** The package refuses a write by throwing `CompatibilityWriteRefused` from inside the router's transaction. The transaction or savepoint rolls back, so nothing of the write is stored.

**Code that must agree.** Every refusal goes through a `CompatibilityWriteRefused` factory. `WriteRouter` runs each write inside `Connection::transaction()`. `InteractsWithCompatibilityMaps::assertWriteRefused()` catches the exception and nothing else.

## INV-5: A write reads its record only after its locks

**Rule.** `PreviousWriter::update()` reads the record through the view only after it has locked the primary row, the retained row and each dependent row it will write. It computes every change, an increment included, from that read.

**Why.** No other writer can change those rows between the read and the write, so no change is lost and no conflict check is needed.

**Code that must agree.** `PreviousWriter::update()` calls `Records::viewRow()` after its locks, and hands that row to the change closure. `WriteRouter::increment()` adds each amount to the locked value through `Values::add()`.

## INV-6: A change to a shared current table keeps every previous model over it in step

**Rule.** A write of a current table does the current-side work of every map with a current model over that table on the same connection, whatever model class the map names.

**Why.** Two previous models can read one current row, so a write must reach both.

**Code that must agree.** `WriteRouter::routes()` and `CurrentWriter` find their maps through `MirrorIndex::forRelation()`, by the connection and the table.

## INV-7: A write writes only what changed

**Rule.** A write through the previous model writes each attribute whose value differs from the locked record, and nothing else. Two values are the same when the column's type says so: numbers by value, booleans by truth, JSON by content and timestamps by instant.

**Why.** An unchanged attribute must not wake a current model's listeners or its `UPDATE OF` triggers.

**Code that must agree.** `PreviousWriter::update()` compares through `Values::same()` with the view column's type from `ColumnCatalog::type()`. A current model saves only when it is dirty.

## INV-8: Each stored column has one owner

**Rule.** No two attributes write one column, and no attribute and fill set one column on insert.

**Code that must agree.** `ResolvedMap::of()` refuses both, the second through `ResolvedMap::insertAssignmentsTo()`.

## INV-9: The compatibility view is read-only

**Rule.** The view joins the primary current table to the retained table, and carries no trigger and no rule. PostgreSQL refuses every write through it. The installer copies only `SELECT` grants to it.

**Why.** A write that bypasses Eloquent must fail, not reach the wrong table.

**Code that must agree.** `Compiler::compile()` emits one `CREATE VIEW` with a `JOIN`. `Installer` copies only `SELECT` privileges.

## INV-10: Reads carry their types

**Rule.** Every mapped attribute is read as `CAST(... AS <asType>)`, so the view's column types are the ones the map declares.

**Code that must agree.** `Compiler::compile()`.

## INV-11: A write through the previous model needs both rows

**Rule.** A record whose primary row or retained row is gone is not written. The view does not show it, and a write that finds a row missing after its locks skips the record or refuses with `CompatibilityWriteRefused::missingRow()`.

**Code that must agree.** `PreviousWriter::update()` and `PreviousWriter::delete()`.

## INV-12: A designation pins the dependent row

**Rule.** A dependent declared with `DependentCurrentModel::designatedBy()` reads only the row its designation names. An insert or update of a readable dependent row through its model fills an empty designation. A write through the previous model refuses to write a designated row that is no longer readable.

**Code that must agree.** `Records::designate()`, `Records::locate()`, `PreviousWriter::update()` and `CurrentWriter`.

## INV-13: Hidden records are hidden from the drift check too

**Rule.** A retained row a hidden unrepresentable state covers is neither shown by the view nor reported by the drift check.

**Code that must agree.** `Compiler::compile()` and `DriftQueries::find()`.

## INV-14: A refused state checked on a table holds for every write through a model

**Rule.** After any insert or update through a model of a table that a refused state checks, the writer reads the written rows and refuses when one is in the state. That includes the saves `PreviousWriter` makes.

**Why.** The state is a rule of the table, not of one side.

**Code that must agree.** `Records::refuseStates()`, called by `CurrentWriter` for each written row and by `PreviousWriter` for the retained row.

## INV-15: A mirrored listener may refuse a write, never change it

**Rule.** A mirrored listener of an `-ing` event that returns false, or changes an attribute, refuses the whole write with `CompatibilityWriteRefused`. The replayer decides what counts as a change by the attribute's cast, or else by its column type.

**Why.** Mirrored events fire after the write has reached the database, so a listener's change has nowhere to go.

**Code that must agree.** `EventReplayer::replay()`, through `CompatibilityWriteRefused::byMirroredListener()` and `CompatibilityWriteRefused::byMirroredChange()`.

## INV-16: A current model's own listeners run before its row is written

**Rule.** A write through the previous model saves each current model through Eloquent. Its `saving`, `creating` and `updating` listeners run before the row is written, may change a value, and may veto the save. A veto refuses the whole write with `CompatibilityWriteRefused::byCurrentListener()`.

**Code that must agree.** `PreviousWriter`, which saves through `Model::save()`, soft-deletes and restores through the model's own methods, and force-deletes through `forceDelete()`.

## INV-17: A query builder write fires no model events on either side

**Rule.** A write of a mapped model's query builder, rather than a model's own write, saves the current models quietly and mirrors nothing.

**Why.** Eloquent fires no events for its own mass writes.

**Code that must agree.** `HasCompatibility` marks a model's own update or delete through `setKeysForSaveQuery()`, and its own insert through `getAttributesForInsert()`. `PreviousWriter` runs a quiet save through `Model::withoutEvents()`. `CurrentWriter` mirrors only for a marked write.

## INV-18: An excluded listener skips mirrored dispatches only

**Rule.** An excluded listener does not run for a mirrored event, and runs for every native event, a native write inside a mirrored listener included.

**Code that must agree.** `EventReplayer`, as [decision 0007](decisions/0007-excluded-listeners-are-masked-per-dispatch.md) describes.

## INV-19: Every routed write runs in one transaction or savepoint

**Rule.** The router wraps each routed write, with its locks, reads, refusals and replays, in `Connection::transaction()`. Inside a caller's transaction that is a savepoint.

**Code that must agree.** `WriteRouter::insert()`, `WriteRouter::update()`, `WriteRouter::increment()` and `WriteRouter::delete()`.

## INV-20: A refused state is serialized per record

**Rule.** Before a write of a dependent's table or the retained table that a refused state checks, the writer locks the primary row for key share, then takes a transaction-scoped advisory lock keyed on the map, the state and the primary foreign key. A state checked on the primary table takes no lock, as [decision 0016](decisions/0016-a-refused-state-on-the-primary-table-is-not-serialized.md) records.

**Why.** A condition that compares a record's own rows must see the other writer's committed row.

**Code that must agree.** `Records::serialize()`, called by `CurrentWriter` and by `PreviousWriter` for the retained table.

**Untested.** No test pins the serialization under concurrent writers.

## INV-21: Every mapped model routes its writes

**Rule.** Every model a registered map names implements `Compatible`, uses `HasCompatibility`, and resolves `setKeysForSaveQuery()`, `getAttributesForInsert()` and `newEloquentBuilder()` to the trait's own methods. Its builder uses `RoutesCompatibleWrites`. `WriteRouter::register()` refuses any other model, a Parental model and a pivot model. `HasCompatibility::newEloquentBuilder()` throws for a custom builder that does not use the trait.

**Code that must agree.** `WriteRouter::register()`, `WriteRouter::HOOKS`, `HasCompatibility`, and the `HasCompatibilityMustNotRedeclareWriteHooks` PHPStan rule.

## INV-22: Every write method of the builder is routed or refused

**Rule.** `RoutesCompatibleWrites` overrides every write method of the Eloquent builder: `insert()`, `insertGetId()`, `insertOrIgnore()`, `insertUsing()`, `insertOrIgnoreUsing()`, `upsert()`, `update()`, `touch()`, `increment()`, `decrement()`, `incrementEach()`, `decrementEach()`, `delete()` and `forceDelete()`. A write the router cannot split row by row refuses. A raw SQL value through the previous model refuses. A base query method the trait does not override reaches the read-only view and fails there.

**Code that must agree.** `RoutesCompatibleWrites`, `WriteRouter::refuseSetInsert()` and `PreviousWriter`. `HasCompatibilityTest` lists the routed methods.

## INV-23: Each Installer method is atomic

**Rule.** `Installer::install()`, `Installer::recompile()` and `Installer::uninstall()` each run in one transaction, and leave the schema as it was when they fail.

**Code that must agree.** `Installer`.

## INV-24: A recompile replaces the view, keeps its grants and drops legacy triggers

**Rule.** `Installer::recompile()` drops the view and creates it again, grants its own grants again, and drops every trigger and function with the map's legacy prefix. It refuses while another object depends on the view, and names each one.

**Code that must agree.** `Installer::recompile()` and `ResolvedMap::legacyObjectPrefix()`, as [decision 0022](decisions/0022-a-recompile-replaces-the-view-and-refuses-its-dependents.md) describes.

## INV-25: Only the compatibility view reads the retained table

**Rule.** `Installer::install()` refuses while another view or materialized view reads the retained table, and names each one.

**Why.** A view on the old table follows its rename, and would miss every mapped attribute.

**Code that must agree.** `Installer::install()`.

## INV-26: The schema refuses a table with row-level security

**Rule.** `Installer::install()` and `Installer::recompile()` refuse a mapped table with row-level security enabled.

**Why.** The view reads with its owner's rights, so it would bypass the table's policies.

**Code that must agree.** `Installer`.

## INV-27: Every insert fills each required column

**Rule.** Each `NOT NULL` column without a default has a value on every insert path: an insert through the previous model, and a primary current row created through its own model.

**Code that must agree.** `Compiler::compile()` checks it against the catalog. `PreviousWriter` writes `ResolvedMap::insertAssignmentsTo()` and the fills. `CurrentWriter` writes `PreviousModel::fillWhenCreatedFromCurrent()`.

## INV-28: An insert keeps a column's default

**Rule.** An insert that omits an attribute leaves its column to the database's default. An explicit null for a `NOT NULL` column with a default takes the default. A fill that returns null leaves its column to the default. A dependent row created by an update stores the record's values as the view read them, nulls included.

**Code that must agree.** `PreviousWriter`, through `ColumnCatalog::of()`.

## INV-29: A generated column is read-only

**Rule.** An attribute that writes a column the database always generates is refused at compile time. An insert leaves out a read-only attribute stored in such a column.

**Code that must agree.** `Compiler::compile()` and `PreviousWriter`.

## INV-30: A map's tables resolve in the current schema alone

**Rule.** The compiler and the installer look every mapped table up in `current_schema()`. A table found only further down the search path is refused as missing.

**Code that must agree.** `ColumnCatalog`, `Compiler` and `Installer`.

## INV-31: A definition compiles without a database

**Rule.** `Compatibility::toMap()` reads only the declaration, the model classes and their relations. It writes each binding of a compiled relation or scope as a literal itself, and never opens a connection.

**Why.** An application registers its maps while it boots, as [decision 0011](decisions/0011-registration-needs-no-database.md) records. Laravel's `toRawSql()` would connect to escape a string.

**Code that must agree.** `RelationCompiler` and `SqlFragments::inline()`. `CompatibilityTest` counts the queries a registration sends.

## INV-32: A relation decides the row a dependent attribute reads

**Rule.** A definition's dependent reads the first row of its `HasOne` relation: the relation's constraints and its `SoftDeletes` scope are the readable condition, and its order comes before the key. A created row takes the relation's `withAttributes()`. Each alias is its table's name, so Eloquent's qualified columns resolve in the view and in the writers' queries.

**Code that must agree.** `RelationCompiler::hasOne()`, `ResolvedMap::locate()` and `Records::locate()`, which both read `DependentCurrentModel::orderedBy()`.

## INV-33: No global scope but SoftDeletes reaches the view

**Rule.** `RelationCompiler` refuses a relation whose related model has a global scope other than `SoftDeletingScope`.

**Why.** The view is compiled once, at install time. A scope that reads the application's state, such as the current tenant, would freeze that state for every reader.

**Code that must agree.** `RelationCompiler`.

## INV-34: A query on a converted attribute reaches SQL in the stored form, or refuses

**Rule.** On an attribute whose cast implements `QueryableCast`, the builder converts each filter value, increment amount and aggregate result through the cast. It refuses a range, order or `min()` and `max()` the cast does not preserve, and a `sum()`, `avg()` or increment it is not proportional for. On an attribute with a filter translation, it applies the translation to `where()` and `whereIn()`, and refuses ordering, ranges and arithmetic. Raw SQL is not translated.

**Code that must agree.** `RoutesCompatibleWrites`, `QueryableCast` and `CompatibilityQueryRefused`.

## INV-35: The drift check compares the installed view with the code

**Rule.** `compatibility:check` reports an installed view whose normalized definition differs from the view the map compiles to now.

**Why.** Relations are part of the schema after [decision 0025](decisions/0025-a-map-is-declared-through-the-models-relations.md), and a code change does not recompile the view.

**Code that must agree.** `DriftQueries::find()` and `Compiler::viewQuery()`.

