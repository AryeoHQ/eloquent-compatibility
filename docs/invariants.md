# Invariants

Each rule below holds today, and a change must keep it. Each entry states the rule, the reason for it and the code that must agree. An entry marked **Untested** names the parts no test pins, so a change there needs extra care.

## INV-1: Locks follow one order

**Rule.** The event bridge and the triggers lock the primary row before the retained row. The bridge and the update trigger lock the dependent rows last. For a dependent model's write, the bridge locks the primary row of each primary foreign key the write names. A `FOR UPDATE` read through the view locks the primary row before the retained row. A refuse function on a dependent's table or the retained table locks the primary row before it takes the refused-state lock. `EventBridge::mirror()` refuses a map that makes one model the primary current model of one mirrored map and a dependent current model of another.

**Why.** The bridge locks before the statement, and the trigger locks the same rows again. Two writers that lock in one order cannot deadlock on these rows. A model in both current roles has no such order. One map locks its row before that map's retained row, and the other map's triggers lock it after theirs. A bridged write of that model can then deadlock a concurrent write through the other view with 40P01.

The view's order relies on PostgreSQL's observed row locking, which its documentation does not state. ExecLockRows locks the rows in row-mark order, and the planner builds the row marks in range-table order. So the order of the view's `FROM` list sets the order of the locks. Tests pin that order on PostgreSQL 14, 16 and 18.

**Exceptions.** Two lock paths break the order:

- **A writer that locks a dependent row before the primary row.** It can deadlock a concurrent update through the view with 40P01. The view writer holds the primary row `FOR UPDATE`, and waits on the dependent row that the conflict check locks on every update. The other writer holds the dependent row, and its refuse function's `FOR KEY SHARE` on the primary row waits on the view writer. A write through the event bridge locks the primary row before its statement, so it waits instead. It still deadlocks when the application locked the dependent row itself before the write.
- **A newcomer.** The bridge finds the affected records a second time under its first locks, for every operation. A record found only on that second pass is a newcomer. A dependent row another transaction committed before the first lock is one, and so is another map's previous record for it. The bridge locks the newcomers after every record of the first pass, in role order among themselves. So the role order does not hold across the two passes.

**Limits of the second pass.** The second pass finds every dependent row another transaction inserts, because an uncommitted insert waits on the locked primary row. Its foreign key check takes `FOR KEY SHARE` on that row. That holds only when each dependent's primary foreign key has a foreign key constraint to the primary table. A refuse function on the dependent's table serves too, and the package requires neither.

Without one, a row another transaction inserts after the second pass escapes it. A removing delete then removes that row without its delete events. An update then replays the row's create events in place of its update events.

An update can also make an existing dependent row readable with no change to its primary foreign key. Such an update takes no lock on the primary row. Only a refuse function on that dependent's table blocks such an update, because the function takes `FOR KEY SHARE` on the primary row first. The workbench place has one, `second_root_place`. Without one, the update can commit after the second pass. The bridge then holds no row from before for that dependent row, and replays its create events in place of its update events.

**Code that must agree.**

- `RecordRole::lockOrder()` puts primary records first, previous records second and dependent records last. The private `AffectedRecords::lock()` sorts by it, then by lock table and key.
- `AffectedRecords::before()` adds a dependent write's primary records from the private `AffectedRecords::lockedOnly()`, so it locks them and takes no record snapshot of them. It then calls `AffectedRecords::of()` again to find the newcomers.
- `KeyTranslator::previousRecord()` makes a previous record lock its retained table.
- `Compiler::updateFunction()` and `Compiler::deleteFunction()` call `Compiler::lockPrimary()` before `Compiler::loadPrevious()`. `Compiler::conflict()` then locks each dependent row the record reads.
- `Compiler::refuseFunction()` takes `FOR KEY SHARE` on the primary row before `pg_advisory_xact_lock()`.
- `Compiler::view()` names the primary table first in the view's `FROM` list, and joins the retained table to it.
- `EventBridge::mirror()` compares each current model's role with its role in every map already mirrored, through `MirrorIndex::forModel()`, before it registers the map.

**Untested.** No test pins the dependent-row deadlock, the bridged write that waits instead, or the order of the primary and retained locks inside the triggers.

## INV-2: One expression locates a dependent row

**Rule.** The view, the update trigger and the event bridge pick a dependent row with the same subquery, from `ResolvedMap::locate()`.

**Why.** The bridge must mirror the row the view reads and the trigger writes, and two copies of the condition could pick two rows. A delete that removes the row is the one exception: the trigger removes every dependent row, and [INV-25](#inv-25-a-delete-through-the-previous-model-mirrors-every-row-it-removes) mirrors them all.

**Code that must agree.** `ResolvedMap::locate()`, which `Compiler::read()`, `Compiler::updateDependent()` and `KeyTranslator::currentKeys()` call.

## INV-3: The view's own writes skip this map's current-side triggers

**Rule.** The insert, update and delete triggers hold the applying flag at the map's name while they run. Each one restores the flag's earlier value when it returns. The primary insert, primary delete and designate triggers return early while the flag holds that name.

**Why.** An insert through the view writes the primary row itself. Without the flag, the primary insert trigger would add a second retained row. The flag holds the map's name, so another map over the same primary table still reacts.

An application trigger can write through a second compatibility view inside the first view's write. The restore keeps the outer map's name in place after that nested write. Without it, the outer primary insert trigger would add a second retained row.

**Code that must agree.** `Setting::Applying` and `Setting::for()`. `Compiler::function()` gives `Compiler::insertFunction()`, `Compiler::updateFunction()` and `Compiler::deleteFunction()` a `SET` clause for the flag. PostgreSQL then sets it on entry and restores it on every exit, an error included. `Compiler::skipWhileApplying()` makes `Compiler::primaryInsertFunction()`, `Compiler::primaryDeleteFunction()` and `Compiler::designateFunction()` return early.

## INV-4: Every database refusal is CM001

**Rule.** Every refusal the compiled SQL raises uses SQLSTATE CM001, with a message, a detail and a hint.

**Why.** PHP recognises a refusal by that one code. It splits the three parts into what was refused, why, and what to do instead.

**Code that must agree.** `SqlState::Refused`, `SqlFragments::refuse()` and `SqlFragments::raise()`. Every refusal in `Compiler` goes through `SqlFragments::refuse()`: in `Compiler::updateFunction()`, `Compiler::notNullChecks()`, `Compiler::updateDependent()`, `Compiler::missing()` and `Compiler::refuseFunction()`. `CompatibilityWriteRefused::isRefusal()` and `CompatibilityWriteRefused::fromQueryException()` read it back.

## INV-5: A concurrent change raises 40001

**Rule.** After its locks, the update trigger locks each dependent row the record reads, then reads every attribute again. When that read differs from `OLD`, it raises SQLSTATE 40001, not CM001. A dependent row it no longer finds is not a conflict: its attributes compare as `OLD`. The trigger then writes only the dependent row it locked. When it locked none, but the view's subquery finds a row now, it raises 40001 too.

**Why.** An INSTEAD OF trigger receives `OLD` from the statement's snapshot. Without the check, the trigger would overwrite a change committed after that snapshot. A serialization failure tells the caller to retry.

**Code that must agree.** `Compiler::conflict()`, which `Compiler::updateFunction()` calls after `Compiler::loadPrevious()`. It locks each dependent row through `ResolvedMap::locate()` into its `key_<alias>`, and joins that row by its key. It reads each other attribute with `Compiler::read()`, as the view does, and compares each one as [INV-36](#inv-36-an-attribute-without-a-btree-equality-compares-as-text) requires. `Compiler::updateDependent()` writes the row in `key_<alias>`, and selects no row of its own. Both raise `SqlState::SerializationFailure` through the private `Compiler::concurrentChange()`. `CompatibilityWriteRefused::isRefusal()` must not accept it.

## INV-6: A change to a shared current table mirrors every previous model over it

**Rule.** When a current record changes, every map with a previous model over that record's table on the same connection raises its previous model's events. That holds when another map names a different model class over the same table. A map whose current model of that table name uses another connection raises nothing, and the bridge sends it no query.

**Why.** Two previous models can read one current row, so a write through either side must reach the listeners of both. The bridge finds a model's own maps by its exact class. It finds the other maps over the record's table by the write's connection and the table, whatever class they name. Two connections can each hold a table of one name, and those tables share no row. A model without a connection name counts under the default connection at the write, not at registration, so registration still needs no database.

**Code that must agree.** `AffectedRecords::acrossMaps()`, `MirrorIndex::forRelation()`, `MirrorIndex::add()` and `KeyTranslator::previousKeys()`. `AffectedRecords::of()` also hands `AffectedRecords::acrossMaps()` the current model's own record in each of its maps, and leaves that record out of the result.

## INV-7: The update trigger writes only what changed

**Rule.** The update trigger writes a table only when one of its attributes changed, and a column's value only when its own attribute changed. Its `UPDATE` still assigns every mapped column of that table, the retained table's included, and an unchanged column gets its own stored value. So PostgreSQL fires an `UPDATE OF` trigger on that table for a write that left its column unchanged. `compatibility:check` warns of each such trigger without a `WHEN` clause, and its exit status stays the same.

**Why.** An unchanged table keeps its rows as they are, and fires none of its triggers. PostgreSQL fires an `UPDATE OF` trigger for each column in the `SET` list, changed or not. The SQL stays as it is, and the check names each trigger that needs a guard. The event bridge compares rows, not `SET` lists, so its events are unaffected.

**Code that must agree.** `SqlFragments::changed()`, `Compiler::updateTable()`, `Compiler::updateDependent()` and `Compiler::update()`. `DriftQueries::unguardedColumnTriggers()` reads the same `ResolvedMap::assignmentsTo()` targets as `Compiler::update()`, for each current table and the retained table. `CheckDrift::handle()` prints its result and leaves the exit status alone.

## INV-8: Each stored column has one owner

**Rule.** At most one attribute writes a given column, and no attribute writes the primary foreign key or a designation column. A read-only attribute stored in a column that a writable attribute writes yields to that owner on insert. Two or more read-only attributes stored in one column need a writable owner, or registration refuses the map. A fill from `PrimaryCurrentModel::fillWhenCreatedFromPrevious()` or `DependentCurrentModel::fillWhenCreatedFromPrevious()` never sets a column an attribute writes. Registration refuses an attribute that stores a dependent's primary foreign key, read-only or not. It also refuses a fill that sets a dependent's primary foreign key, the retained table's primary foreign key or a designation column.

**Why.** The update trigger writes each column from one attribute's change, and the map maintains the link and the designation itself. An insert stores a read-only attribute too, as [INV-49](#inv-49-an-insert-through-the-view-stores-a-read-only-attribute) says. Without the yield, a read-only `title_copy` stored in the column of a writable `title` would overwrite `title` with NULL. With no writable owner, nothing says which read-only value an insert stores.

An insert writes a dependent's link first, and its attributes and fills after. An attribute stored in `gadget_places.gadget_id` would therefore replace the link, and the new place would belong to another gadget. A retained fill of `gadget_id` would likewise leave a native gadget's retained row unlinked.

**Code that must agree.** `ResolvedMap::validateAttributes()`, the private `ResolvedMap::validateLinks()`, `Compiler::validateColumns()` and `Compiler::insertValues()`. `ResolvedMap::validateLinks()` reads each attribute's `Attribute::$writesTo` and its `Attribute::$storedAs`, so a read-only attribute counts too. The private `Compiler::insertAssignmentsTo()` skips a read-only mapped attribute whose column `ResolvedMap::writes()` reports.

## INV-9: Each map owns a prefix no other map's prefix begins

**Rule.** Every trigger, function, index and statistics object of a map starts with `compatibility__<name>__` and fits in 63 characters. A map name has at most 24 lowercase letters, digits and single underscores, and does not end in an underscore. The Installer drops only the prefixed triggers on the current schema's tables, the prefixed functions of that schema, the view in that schema, and on an uninstall the prefixed indexes and statistics objects of that schema.

**Why.** The Installer finds a map's triggers, functions, indexes and statistics objects by prefix and schema alone. A name with a double or trailing underscore could make one map's prefix begin another's. An uninstall of one map would then drop the other's objects. Without the schema, an install in one schema would drop the same map's triggers in another, or a trigger on another schema's table.

**Code that must agree.**

- `ResolvedMap::of()` checks the name. `ResolvedMap::objectPrefix()`, `ResolvedMap::functionName()`, `ResolvedMap::refuseFunctionName()`, `ResolvedMap::designateFunctionName()`, `ResolvedMap::statisticsName()` and `ResolvedMap::indexName()` build every name, through the private `ResolvedMap::object()`. `ResolvedMap::of()` also builds each declared index's name, so an index name over 63 characters is refused at registration, and a function name over the limit at compile.
- The private `Installer::plannerDropStatements()` finds the indexes and statistics objects by that prefix in `current_schema()`, from the catalog, and lists a `DROP INDEX IF EXISTS` and a `DROP STATISTICS IF EXISTS` for each. Only an uninstall lists them.
- The private `Installer::dropStatements()` matches the prefix through `SqlFragments::like()`. It drops a trigger only when its table and its function are both in `current_schema()`, and a function only when it is in that schema. It names the schema through `to_regnamespace(quote_ident(current_schema()))`, so a schema whose name needs quoting resolves.
- The private `Installer::relationKind()` and `Installer::relation()` look the view up in that schema alone. `Installer::install()` refuses when the first finds one, and `Installer::recompile()` and `Installer::uninstall()` act on the view only when the second finds one. A recompile or an uninstall under `--retained-table-is-currently` that names the view's own name treats that relation as no view, because it is the retained table before the rename.
- `Compiler` creates every function in that same schema.


## INV-10: Reads and writes carry their types

**Rule.** The view casts each mapped attribute to its declared type. The triggers cast each written value to its column's type in the catalog. The compiler names each catalog type and default as the trigger functions' `search_path` resolves it. So a type or a default from another schema carries that schema, and the compiling connection keeps its own search path.

**Why.** A mapped attribute's type can differ from the column that stores it. The workbench `price` is text in the view and integer cents in `gadgets`. PostgreSQL leaves out the schema of a type or a function that the current search path finds. Read under the installer's search path, an enum from a later schema would compile unqualified. The functions search only the installation schema and `pg_temp`, so every write through the view would then fail with 42704.

**Code that must agree.** `ResolvedMap::validateAttributes()` requires `Attribute::asType()`. `Compiler::read()` casts reads, and `Compiler::value()` casts writes through `ColumnCatalog::type()`. The private `Compiler::searchPath()` names the search path for both `Compiler::function()` and the compiler's `ColumnCatalog`. `ColumnCatalog::of()` reads under that search path, inside a transaction or savepoint it rolls back. `EventReplayer` builds its `ColumnCatalog` without one, so it reads in one statement under the connection's search path.

## INV-11: A write through the view needs both rows

**Rule.** An update or delete through the view refuses with CM001 when the retained row is missing. An update that writes the primary row also refuses when it touches other than one row.

**Why.** Otherwise a write with nowhere to store its change would succeed silently. The hint points at `compatibility:check`.

**Code that must agree.** `Compiler::loadPrevious()`, `Compiler::updateTable()` for the primary alias, and `Compiler::missing()`.

## INV-12: A designation pins the dependent row

**Rule.** When a dependent declares a designation, the previous model uses only the row it names. It reads, writes and mirrors that row only while the row is readable. A write to a dependent attribute refuses while the named row exists but is not readable.

**Why.** Without it, the previous model would switch to another row when the first stops meeting its readable condition.

**Code that must agree.** `ResolvedMap::locate()` adds the designation to the condition. `Compiler::designateFunction()` fills an empty designation when an insert or an update makes a dependent row readable, and `Compiler::triggers()` attaches it `AFTER INSERT OR UPDATE`. `Compiler::designate()` sets the designation for rows the view creates. `Compiler::updateDependent()` refuses, and `Compiler::validateColumns()` requires the column. `DriftQueries::brokenDesignations()` reports a broken designation, and an empty one whose record has a readable row.

A record that already exists at install time has no designation. [Adopting a map](adopting-a-map.md#after-the-install-backfill-the-designations) describes the backfill.

## INV-13: Hidden records are hidden from the drift check too

**Rule.** A record a hidden state covers is absent from the view, and the drift check does not report it.

**Why.** A hidden record has no current rows on purpose. A report of it would fail the check on every run.

**Code that must agree.** `Compiler::view()`, `DriftQueries::previousWithoutCurrent()` and `DriftQueries::brokenDesignations()` build the same `COALESCE((condition), false)` test.

## INV-14: A refused state checked on a table holds for every writer

**Rule.** A refused state that names `UnrepresentableState::checkedOn()` compiles to a trigger on that table. The trigger runs for every insert and update, the view's included.

**Why.** The state is unrepresentable whoever writes it, so the applying flag does not exempt the view. The trigger checks each writer against committed rows and its own. On the primary table, two concurrent writers can both reach a cross-row state, as [INV-23](#inv-23-a-refused-state-check-is-serialized-per-record) records.

**Code that must agree.** `Compiler::refuseFunction()`, which never calls `Compiler::skipWhileApplying()`. `Compiler::triggers()` attaches it `BEFORE INSERT OR UPDATE`. `DriftQueries::refusedStates()` reports rows already in the state.

## INV-15: A mirrored listener may refuse a write, never change it

**Rule.** A mirrored listener of an `-ing` event that returns false, or changes an attribute, refuses the whole write with `CompatibilityWriteRefused`. The bridge decides what counts as a change by the attribute's cast, or else by its column type, and compares only values that differ:

- A numeric cast or column compares two numbers by their exact decimal value, never through a float. So `'47.600000000000'` equals `47.6`, and a change below float precision, such as `'47.600000000000001'`, still counts. A number the bridge cannot read, such as INF or NAN, equals only an identical value.
- A JSON cast, or a `json` or `jsonb` column, compares the decoded JSON. An object and an array differ, empty or nested, and the order of an object's keys does not count. Each JSON number compares by its exact decimal value, never through a PHP integer or float. So `9223372036854775808` and `9223372036854775809` differ, and so do two fractions a float cannot tell apart. A JSON number the bridge cannot read refuses the write.
- An encrypted cast or a class cast compares as Laravel's `Model::originalIsEquivalent()` does, against the value from before the listener. Such a cast can store a new string for a value a listener only read: `Illuminate\Database\Eloquent\Casts\AsEncryptedCollection` encrypts again with a new IV.
- Every other attribute, a text column included, compares exactly, so `'1'` and `'001'` differ.

**Why.** The write has already reached the database when mirrored events fire, so a listener's change has nowhere to go.

**Code that must agree.** `EventReplayer::ing()` refuses, through `CompatibilityWriteRefused::byMirroredListener()` and `CompatibilityWriteRefused::byMirroredChange()`. `EventReplayer::changed()` reads the column type through `ColumnCatalog` only when a value changed. `EventReplayer::equivalent()` applies the comparisons, with `EventReplayer::number()` for numbers and the private `EventReplayer::json()` for JSON. The class cast compares on a copy of the model whose original holds the value from before the listener. A replay tells a moved state column, and a column a trigger's save changed, through `EventReplayer::changed()` too.

## INV-16: A mirrored listener's write begins its own pending mirrored write

**Rule.** A native write inside a mirrored listener begins its own pending mirrored write, on top of the connection's stack. That holds for a write on the instance the bridge replays too.

**Why.** The replay's own events never reach the bridge, because `EventReplayer::replay()` dispatches them through the model dispatcher, not through `HasCompatibility::fireModelEvent()`. So the bridge sees a beginning event on a replayed instance only when a listener writes that instance. That write sends its own statement. If the bridge ignored it, the write would run inside the bridge's transaction with no lock, no record snapshot and no mirrored events.

**Code that must agree.** `EventReplayer::replay()`, `EventBridge::begin()` and `EventBridge::top()`. The private `EventBridge::sees()` does not tell a replayed instance apart.

## INV-17: A delete completes on an UPDATE

**Rule.** A pending delete opens and completes on the statement its model's own save query sends. For a soft delete, Eloquent sends that statement as an `UPDATE` of the deleted-at column. [INV-33](#inv-33-a-pending-mirrored-write-completes-only-on-its-own-statement) states the full rule.

**Why.** The bridge must not expect a `DELETE` for a delete, because a soft delete never sends one.

**Code that must agree.** `ModelEvent::operation()`, `HasCompatibility::setKeysForSaveQuery()` and `EventBridge::markOwnStatement()`. `AffectedRecords::of()` reads a soft delete from `Operation::Delete` and the model's `isForceDeleting()`, never from the statement.

## INV-18: An excluded listener skips mirrored dispatches only

**Rule.** The bridge skips an excluded listener only while it dispatches for the instance it replays, or while a state machine's trigger runs on the instance the bridge built for it. The listener runs for every other native event, one raised inside a mirrored listener or inside `handle()` included. That holds for a listener registered under the event's name, for one registered on the model's custom event class, and for one registered by a wildcard such as `eloquent.updated: *` on Laravel's own dispatcher.

**Why.** An event mirror excludes a listener from writes through the other side, not from the model's own writes. A trigger that the bridge runs for a write through the other side saves natively, so its excluded listeners would otherwise run for exactly that write. Laravel keeps wildcard listeners apart from the listeners of each name. A mask of the name alone would therefore leave a wildcard listener running for mirrored events.

**Code that must agree.** `EventReplayer::dispatch()`, `EventReplayer::mask()`, `EventReplayer::guard()` and `EventReplayer::unmask()`, with the `Exclusion` held per subject. `ExcludedListener::matches()` matches the listener. `EventReplayer::dispatch()` masks the listeners on the real dispatcher behind an `EventFake`, and dispatches through the model's dispatcher. So a faked event is recorded, and none of its listeners runs. The private `EventReplayer::maskWildcards()` wraps each listener of every matching wildcard in place, through the dispatcher's protected `$wildcards`. Its wrapper passes on the wildcard arguments, the event name and the payload. The private `EventReplayer::unmaskWildcards()` restores them, so the wildcards keep their order. Both need an `Illuminate\Events\Dispatcher`, so a decorating dispatcher's wildcard listeners stay unmasked. `EventReplayer::transition()` masks every model event of the record's model through the private `EventReplayer::maskModelEvents()`, until its last trigger returns. A wrapper then finds the trigger's instance through the private `EventReplayer::transitioning()`, as the payload or as a property of a custom event. It reads the model event from the event's name. `EventBridge::fireModelEvent()` runs each dispatch through `EventReplayer::dispatching()`, which keeps a stack of the model events the instance dispatches. For a custom event, the wrapper reads the top of that stack when `Model::dispatchesEvents()` maps that event to the custom event's class. So a class the model maps to two events skips a listener only for the event it excludes.

## INV-19: A failed write at level 0 releases its transaction

**Rule.** The event bridge mirrors a write only on a connection whose class uses `DispatchesQueryFailed`, and resolves the trait's methods to the trait. It refuses every mirrored write on any other connection, from the first one on. The refusal names the connection, its class, and the trait or the class that replaces its method. The trait rolls back a level-0 write whose own statement failed, before it raises `QueryFailed` and before the `QueryException` reaches the caller. It does so whatever `select 1` returns. A rollback that fails there never replaces the statement's `QueryException`. When it fails while Laravel still counts the transaction, the bridge keeps the write, and each later statement tries the rollback once. Any other level-0 write left pending is closed by the first of five events. They are the next statement, the next bridged write, the next transaction the application begins, a queue worker's next loop and application termination. The first three close such a write only when `select 1` fails, because the bridge's transaction is then aborted.

**Why.** The bridge opened that transaction, and nothing else would close it. PostgreSQL refuses every further statement in an aborted transaction, a `SAVEPOINT` included. Laravel creates the savepoint of `Connection::transaction()` and `createOrFirst()` through `getPdo()->exec()`, which no `beforeExecuting` callback sees. Released only at the next statement, a failed write would make both fail with 25P02. A `Connection::afterCommit()` callback registered in that window would join the bridge's transaction and be lost with it. Only the trait reaches the bridge at the failure itself, so the bridge refuses a connection that cannot call it. A statement that PDO refuses before the database sees it leaves the transaction healthy, so a release that waited for a failed `select 1` would keep it open. The trait calls the bridge before it raises `QueryFailed`, so no listener can stop the release by returning false or throwing. The bridge refuses at the first mirrored write rather than at registration, so registration still needs no database, as [decision 0011](decisions/0011-registration-needs-no-database.md) records. Laravel begins a transaction through PDO too, so without the third event, `Connection::transaction()` right after a caught failure would raise 25P02.

The failure of another statement leaves the write alone. Every other `beforeExecuting` callback runs before the write opens, so its failure aborts no transaction of the bridge's. A StatementPrepared listener runs inside the write's own statement, before PDO executes it. A statement it runs pairs with its own call, so the write's own failure still releases the write. A `QueryExecuted` listener registered before the bridge's runs after the write's own statement, and can run another statement there. When the listener catches that statement's failure, the write's completion fails with 25P02 and rolls the write back. When the failure escapes, the write stays pending until one of the five events.

A statement can also start while the write's own statement runs. A `QueryExecuted` listener registered before the bridge's runs after the statement, and before `EventBridge::handleQuery()`. Such a statement finds the transaction healthy, so the bridge leaves the write open, and the write completes on its own `QueryExecuted`. Only a queue worker's next loop and termination commit a healthy write. So when such a listener throws, the write stays open and unmirrored until one of those two. A `beforeExecuting` callback that throws stops the statement before the write opens, and leaves no transaction.

**Code that must agree.** `EventBridge::watch()` refuses a connection through `CompatibilityConnectionUnsupported::byMissingTrait()` or `CompatibilityConnectionUnsupported::byOverride()`, before it adds its callbacks. It compares the file and the first line of each resolved method with those of the trait's method, as `EventBridge::mirror()` does for a model. It then hands three private callbacks to `DispatchesQueryFailed::registerEventBridge()`. `DispatchesQueryFailed::handleQueryException()` calls the third, `EventBridge::handleFailure()`, before it raises `QueryFailed` and outside its guard against a second event. When a parent class and its subclass both use the trait, only the copy in the subclass does so, even through another trait. The trait reports a throwable from a `QueryFailed` listener and drops one from the report, so the caller always receives the statement's `QueryException`. The trait reads the read/write type for `QueryFailed` before that call. `EventBridge::openStatement()` returns the write it opened, and the trait's callback holds that write through a weak reference. `DispatchesQueryFailed::runQueryCallback()` takes and clears it as the statement starts, and pairs a `QueryException` the statement throws with it, in a weak map keyed by the exception. `DispatchesQueryFailed::handleQueryException()` passes `EventBridge::handleFailure()` the write paired with the exception that `Connection::run()` caught, or null. `EventBridge::handleFailure()` acts only on that write, while it is the top pending write, on the connection object the write began on. It sets `PendingMirroredWrite::$failed` and passes the write to the private `EventBridge::closeFailed()`, which then skips `select 1` for a level-0 write. The private `EventBridge::endTransaction()` keeps a level-0 write whose rollback throws while Laravel still counts its transaction, and sets the same property. `EventBridge::startStatement()`, the `beforeStartingTransaction` callback in `EventBridge::watch()`, `EventBridge::finishFailed()`, `EventBridge::releaseFailed()` and `EventBridge::begin()` close every other pending write. `EventBridge::closeFailed()` runs `select 1` before it closes any other write, and leaves a healthy write open unless `EventBridge::releaseFailed()` asks. `Provider::boot()` listens for `Looping` and registers the terminating callbacks. Only a write whose statement started holds a transaction, so `EventBridge::finishFailed()` reaps only such a write, as [INV-34](#inv-34-a-pending-mirrored-write-opens-at-its-statement) records.

## INV-20: Inside a caller's transaction, a failure behaves as a native one

**Rule.** Inside a caller's transaction, the bridge works in a savepoint Laravel does not count. A PHP failure rolls back only that savepoint. A failed statement leaves the caller's transaction aborted, as a native failed statement would. A statement that fails before it reaches the database leaves the caller's transaction healthy, as a native one would, and the bridge rolls back to its savepoint and releases it.

**Why.** The caller owns its transaction and decides how to recover from a failed statement. A statement that never reached the database changed nothing, so the bridge's savepoint and its locks must not outlive it.

**Code that must agree.** The private `EventBridge::open()` names the savepoint, and `EventBridge::close()` rolls it back or releases it. `EventBridge::complete()` runs the replay inside `Connection::transaction()`. `EventBridge::startStatement()` and `EventBridge::finishFailed()` drop a savepoint write whose statement failed, without a rollback. A failed statement shows as a failed `select 1`, or as a level below the write's after the caller rolled back past its savepoint. A healthy savepoint write stays open, as [INV-19](#inv-19-a-failed-write-at-level-0-releases-its-transaction) describes for level 0. `EventBridge::handleFailure()` passes a savepoint write whose own statement failed to `EventBridge::closeFailed()`. That forgets the write when `select 1` fails. When `select 1` succeeds, the statement never reached the database, so `EventBridge::closeFailed()` rolls back to the savepoint and releases it, through `EventBridge::close()`.

## INV-21: A faked dispatcher cannot hide a mirrored write

**Rule.** Under a faked dispatcher, a mirrored model's own write throws `CompatibilityEventMirrorBypassed` before its statement. That holds when the model dispatcher is an `EventFake` that recorded the write's beginning event for that instance, during that event's own dispatch. The fake recorded the event instead of running its listeners, so the bridge forgets the write it began. An event the fake recorded earlier does not count, so a write after `EventFake::except()` mirrors.

A state change through the previous model throws `CompatibilityEventMirrorBypassed` too, when the bound bus dispatcher is a bus fake that would record the trigger. The statement has run by then, so the bridge rolls the whole write back.

**Why.** A test that fakes every event and writes through a mirrored model proves nothing about the other side. `EventFake::except()` changes which events the fake records, and keeps the events it recorded. A check of the whole record would therefore refuse every later write of the same instance.

**Code that must agree.** `EventBridge::fireModelEvent()` counts the fake's recorded beginning events for the instance through the private `EventBridge::recorded()`, before and after the dispatch. It forgets the write when the count grew. `EventBridge::markOwnStatement()` calls the private `EventBridge::guard()` when the model has no pending mirrored write to claim. The guard reads `MirrorIndex::forModel()` and asks `EventBridge::recorded()` about each of `ModelEvent::beginning()`, and ignores a model in no mirrored map. Inside `EventBridge::withoutMirroring()`, `EventBridge::markOwnStatement()` returns before the guard. A raw write runs no model method, so it never throws. `EventReplayer::transition()` asks the private `EventReplayer::fakes()` before `Trigger::now()`. It calls the protected `Illuminate\Support\Testing\Fakes\BusFake::shouldFakeJob()` through a closure bound to the fake, so a fake of other jobs runs the trigger.

## INV-22: Compiled identifiers are full words

**Rule.** Every name the compiler adds is a full word: `previous_row`, `affected_rows`, `key_<alias>`, `created_key`, the object prefix and the function suffixes. An alias is a lowercase identifier other than `new`, `old` and the PostgreSQL reserved words. It is also no variable the triggers declare: not `previous_row`, `affected_rows` or `created_key`, and no name that starts with `key_`. The workbench maps use full-word aliases, and an application chooses its own.

**Why.** A reader of the compiled SQL should not decode abbreviations. A reserved word as an alias would fail the install with a syntax error. An alias that names a variable makes PL/pgSQL refuse every reference to it as ambiguous, so every update would fail.

**Code that must agree.** `ResolvedMap::of()`, `ReservedWords::POSTGRESQL` and `ResolvedMap::VARIABLES`. `Compiler::variables()` names `previous_row`, `affected_rows` and `key_<alias>`, and `Compiler::primaryInsertFunction()` names `created_key`. A test checks `ReservedWords::POSTGRESQL` against the server.

## INV-23: A refused-state check is serialized per record

**Rule.** A refuse function on a dependent's table or the retained table first takes `FOR KEY SHARE` on the primary row its row links, then `pg_advisory_xact_lock()`. The advisory lock's key is the refuse function's name, a colon and the row's primary foreign key, hashed with `hashtextextended()`. A refuse function on the primary table takes no lock.

**Why.** Without the lock, a condition that reads other rows of the same record would miss a concurrent writer's uncommitted row. Both writers would then commit the state. The key must name both parts. Without the function name, two states would wait on each other. Without the primary foreign key, every writer of the table would wait on one lock. The primary row lock comes first, so that the refused-state lock joins the order of [INV-1](#inv-1-locks-follow-one-order), which records the deadlock path that remains. A primary row has no primary foreign key, so the primary table stays unserialized, as [0016](decisions/0016-a-refused-state-on-the-primary-table-is-not-serialized.md) records.

**Code that must agree.** `Compiler::refuseFunction()` builds the lock from `ResolvedMap::refuseFunctionName()`, `ResolvedMap::dependent()` and `ResolvedMap::primaryForeignKey()`, or from the previous model's primary foreign key. The two locks are the function's first statements, before the `IF EXISTS` check. `Compiler::function()` declares no volatility, so the check after the wait reads a new snapshot under READ COMMITTED.

**Untested.** No test pins the primary-table limit or the REPEATABLE READ limit.

## INV-24: Each Installer method is atomic

**Rule.** `Installer::install()`, `Installer::recompile()` and `Installer::uninstall()` each run every statement inside one `Connection::transaction()`. Inside a caller's transaction, that is a savepoint. `Installer::prepare()` is the one exception. It runs outside a transaction, one statement at a time, and throws `InvalidCompatibilityMap` inside one.

**Why.** Each method drops the map's triggers before it creates or drops anything else, and a recompile drops the view too. A failure part way would leave a half-installed map, or a map without its triggers. A new primary row would then get no retained row. A concurrent writer waits on the dropped triggers' locks until the commit. `Installer::prepare()` changes none of that. It creates only statistics and indexes, and each statement takes effect alone, so a failure part way leaves the objects already built and no half-installed map. `CREATE INDEX CONCURRENTLY` cannot run in a transaction, so a prepare cannot join one.

**Code that must agree.** Each of the three methods passes its `InstallerAction` to the private `Installer::run()`. Inside one `Connection::transaction()`, `Installer::run()` asks `Installer::statements()` for the list and runs every statement on it. The catalog reads that build the list therefore see the catalog the statements change. Every refusal and every compile error throws while the list is built, before the first statement runs. `Installer::prepare()` asks `Installer::statements()` for its `InstallerAction::Prepare` list and runs each statement with `Connection::unprepared()`, after it checks `Connection::transactionLevel()` is 0.

## INV-25: A delete through the previous model mirrors every row it removes

**Rule.** A delete through the previous model that removes the row mirrors every dependent row under the record's primary foreign key. That includes a row another transaction commits before the bridge locks the primary row. It holds when the dependent's primary foreign key has a foreign key constraint, or its table has a refuse function. [INV-1](#inv-1-locks-follow-one-order) states that limit. A soft delete mirrors only the rows the view reads.

**Why.** The delete trigger removes every dependent row of the primary row, rows the view does not read included. Each removed row must raise its own delete events.

**Code that must agree.** `Compiler::deleteFunction()` deletes by primary foreign key, and `KeyTranslator::dependentKeys()` selects by the same key. The private `AffectedRecords::removed()` turns those keys into records when the private `AffectedRecords::removes()` holds. `AffectedRecords::of()` calls it, and `AffectedRecords::before()` calls `AffectedRecords::of()` a second time under the locks, so it finds newcomers too. A change to which rows the trigger deletes must change `KeyTranslator::dependentKeys()` with it.

## INV-26: The printed statements are the statements that run

**Rule.** `Installer::install()`, `Installer::recompile()`, `Installer::uninstall()` and `Installer::prepare()` each run exactly the list `Installer::statements()` returns for their action, in its order. `compatibility:sql` prints that list. Building it runs only catalog reads. Every refusal of an action throws while its list is built, so `compatibility:sql` refuses every map that action refuses.

The list has this order. An install lists the drops of the map's prefixed triggers and functions, then the compiled statements, which end with the planner list, then one block that revokes every grant on the view except its owner's, then the grants the view takes from the retained table. A recompile lists the drops, then a drop of the view when it exists, then the compiled statements, then the same block, then the view's saved grants. An uninstall lists the drops, then a drop of the view when it exists, then a drop of each index and statistics object of the map. A prepare lists the planner list alone, with each index built `CONCURRENTLY`. The compiled statements carry a `REVOKE EXECUTE ... FROM PUBLIC` after each function, as [INV-37](#inv-37-a-trigger-function-runs-only-for-its-own-relation) requires.

**Why.** A reviewer or a database administrator reads the printed SQL before a migration runs it. A statement that runs without being printed, or that runs in another form, would reach the database unread. A write while the list is built would change the database from a command that promises only to read it.

**Code that must agree.**

- Each Installer method passes its `InstallerAction` to the private `Installer::run()`, which runs the list `Installer::statements()` returns and nothing else. `CompileSql::handle()` prints that list with nothing added but the header comments and a semicolon on its own line after each statement. A line break in a header value prints as `\r` or `\n`, so no header line leaves its comment.
- The view does not exist before an install, so the private `Installer::grantStatements()` derives its grants from the retained table: its grants, and its column grants on the map's attribute columns, which are the view's columns. It leaves out the grants of `current_user`, which runs the install and so owns the view.
- The view's default privileges exist only once the view does, so the private `Installer::revokeStatement()` lists a block that reads the view's grants when it runs, as [INV-48](#inv-48-the-new-view-carries-no-grant-its-default-privileges-add) describes.
- `Installer::run()` builds its list inside `Connection::transaction()`, where Laravel reads through the write connection. `CompileSql::handle()` runs outside a transaction, so it calls `Connection::useWriteConnectionWhenReading()` before it reads anything. Otherwise a connection with a read host would print a list read from a lagging replica.
- Before a recompile, the private `Installer::viewGrants()` saves the view's grants. A column grant returns only for a column the map still has an attribute for, because the map's attribute columns are the new view's columns.
- Under `--retained-table-is-currently`, `Installer::statements()` passes the current name to all three actions. The private `Installer::dropStatements()` names a trigger on the retained table by the map's table name for each of them, so a printed uninstall drops the triggers the uninstall after the rename drops. `CompileSql::handle()` names the current name in a header line for every action.
- The refusals for row-level security, for views that read the retained table and for the view's dependents run while the list is built. So no statement may depend on a catalog read made after an earlier statement runs.

## INV-27: A moved state runs its trigger without its transition

**Rule.** A write through the previous model can move a current model's state column. Before any mirrored event, the bridge then runs the trigger of the one `#[Transition]` that leads from the stored state to the new one, once per state column. It calls the trigger through that column's proxy, with the arguments the map declares, then `Trigger::withoutTransition()` and `Trigger::now()`. The bridge never calls `handle()` itself, never writes the state column again, and writes nothing else.

**Why.** The trigger owns the transition's checks, its before and after events and its side effects. A listener on the current model must see what a native transition gives it. The statement already stored the state, so the trigger must not store it a second time.

**Code that must agree.**

- `EventBridge::complete()` calls the private `EventBridge::transitions()` before `EventReplayer::replay()`. `EventBridge::transitions()` asks `EventReplayer::states()` about current records only.
- `EventReplayer::transition()` builds a fresh instance for each moved state column, from a read of the record just before that column's trigger. That column is back at its stored value in the attribute and the raw original.
- The private `EventReplayer::trigger()` returns the transition's trigger class, and `EventReplayer::transition()` names its method as the column's proxy does.
- `Trigger::now()` runs the trigger's lifecycle at once, and the bridge never dispatches it to a queue.

## INV-28: Retired

**Retired.** This invariant held the state guard's scope during a replay. eloquent-state-machines ships no state guard, so nothing refuses a mirrored event or a previous model's own write to a column it casts to a state machine. [0017](decisions/0017-a-cast-previous-model-changes-its-state-only-inside-the-guards-scopes.md) is retired with it. The number stays, so INV-29 to INV-31 keep theirs.

## INV-29: A moved state whose trigger cannot run refuses the whole write

**Rule.** The bridge refuses the whole write with `CompatibilityWriteRefused` and rolls it back when a moved state's trigger cannot run. That covers a stored or new value that is no case of the enum, no `#[Transition]` or more than one to the new state, a transition the trigger refuses, a trigger the container cannot build, a closure of `triggerArguments()` that throws, and a trigger whose constructor takes a model argument without a default that the map does not declare. The container would build such a model without attributes, even for a nullable argument. A trigger the container cannot build includes one whose declared argument has a type its constructor rejects. The rule also covers a trigger that one of its own middleware does not pass on, which throws the Interrupted exception of aryeo/actions. It covers a unique trigger whose lock another run holds, which throws the Prevented exception of aryeo/actions. It covers two transitions of the stored state whose triggers share a class basename, for which the proxy throws the Duplicate exception of eloquent-state-machines. It also covers a trigger whose last save of its instance a `saving` or `updating` listener vetoes. A save or an `increment()` that a listener makes inside that save does not count, and neither does an earlier save from `handle()`, vetoed or completed. An `Invalid` or a `BindingResolutionException` from `handle()` refuses it too. Any other exception from the trigger rolls the write back and reaches the caller unchanged. So does a deadlock or a serialization failure from a closure of `triggerArguments()`, which the caller's `Connection::transaction()` may retry.

**Why.** The statement already stored a state no trigger reached. Keeping it would leave the current model in a state its state machine forbids.

**Code that must agree.**

- The private `EventReplayer::trigger()` checks the cases and the transitions. It then reflects the constructor of the one trigger, and refuses a parameter that the map does not declare, compared without case, and that `EventReplayer::buildsEmptyModel()` accepts: no default, and a named type that is a subclass of `Model`, nullable or not. The private `EventReplayer::state()` counts a value of a type the enum cannot take as no case.
- `EventReplayer::transition()` turns a `BindingResolutionException`, a `\TypeError`, or the Duplicate exception of eloquent-state-machines from the proxy call that builds the trigger into `CompatibilityWriteRefused::byTransition()`. It does the same for an `Invalid`, a `BindingResolutionException`, or the Interrupted or Prevented exception of aryeo/actions from `Trigger::now()`. Each refusal keeps the exception as its previous exception. A `\TypeError` from `Trigger::now()` passes through unchanged. `EventReplayer::transition()` also refuses when the last outermost save of the instance during `Trigger::now()` did not complete, or when no save ran. `EventBridge::fireModelEvent()` passes every model event to `EventReplayer::trackSave()` before any listener runs, and every result to `EventReplayer::trackSaveResult()` after the dispatch. For an instance a trigger runs on, the replayer keeps the open saves as a stack, each in a phase. `saving` opens a save, and a result other than false moves it to writing. A `creating` or `updating` belongs to the top save only while that save is writing, and it moves the save out of writing. `saved` moves the save out of writing too, before its dispatch. So a `creating` or `updating` of an `increment()` belongs to no save, and its veto changes nothing. A false result from `saving`, or from a `creating` or `updating` that belongs to the save, closes the save as vetoed. `saved` closes it as completed after its dispatch, so a save that a `saved` listener makes sits inside it. The save that closes the last open one sets the outcome of the instance. A `saved` listener that returns false still lets the dispatch return, so the save counts. A faked `saved` event passes through the hook too, so it counts as well.
- The private `EventReplayer::arguments()` turns any exception from a closure into `CompatibilityWriteRefused::byTriggerArgument()` the same way, before the trigger is built. It rethrows unchanged an exception that the bound `Illuminate\Contracts\Database\ConcurrencyErrorDetector` classifies as a concurrency error, the same test `Connection::transaction()` applies before it retries.
- `CompatibilityWriteRefused::byTransition()` names the previous model's attribute and the current model's column.
- The trigger's lifecycle transaction is a savepoint inside the write's transaction, and `EventBridge::complete()` rolls the write back on any throw.

**Limit.** When a save inside `handle()` or a listener throws and the surrounding code catches the exception, the tracking cannot detect whether that save completed or failed. The frame stays on the stack. A later successful save, or the enclosing save, cannot empty the stack, and the bridge refuses a write that commits natively. Eloquent's `touchOwners()` runs after the trigger's save closes, so a vetoed save of the instance from a touched owner's `saved` listener refuses such a write too. This is a limit of the interim guard. [eloquent-state-machines#60](https://github.com/AryeoHQ/eloquent-state-machines/pull/60) makes `Trigger::after()` throw on a vetoed save. Once that ships, the bridge catches that exception, and this tracking is deleted.

## INV-30: A replay reports each change once on each side

**Rule.** The trigger saves what `handle()` changed, and the bridge mirrors that save as a native write. The bridge's own events, on the current model and on the previous model's instance, leave those changes out. They report the new state and the write's other changes.

A column that both the write and `handle()` change is reported once on each side, from the value the write stored, not from the value before the write. The trigger's save reports it on the current model, and the mirror of that save reports it on the previous model. The caller's own `getChanges()` does not list that column. A row that the trigger removes reports only the statement's own change to it.

**Why.** A native transition reports each change once on each side. Two reports of one change would run a listener twice for it.

**Code that must agree.** The private `EventBridge::transitions()` reads the source model's own record before the first trigger, and every record and that own record after the last. For a row the second read no longer finds, it keeps the row after the statement. The private `EventBridge::replayedBefore()` builds a current record's row from before, and the private `EventBridge::copy()` updates the source model's instance. Both tell a changed column through `EventReplayer::changed()`, by the rule [INV-15](#inv-15-a-mirrored-listener-may-refuse-a-write-never-change-it) states. `EventBridge::copy()` writes each changed column into the attribute and the raw original through a closure bound to the model. It drops only those columns from the model's cast caches.

## INV-31: The state machine package stays optional

**Rule.** Without `aryeo/eloquent-state-machines`, or with a release that has no `Trigger::withoutTransition()`, every write mirrors as it did before state changes ran a trigger. A map that declares trigger arguments is refused at registration instead. `EventReplayer` is the only production class that names the package.

**Why.** Composer suggests the package and does not require it. [0018](decisions/0018-the-state-machine-package-is-suggested-not-required.md) records the choice.

**Code that must agree.**

- `EventReplayer::states()` and `EventReplayer::triggers()` check `Trigger::withoutTransition()` before they load any other class of the package. `ResolvedMap::validateTriggerArguments()` reaches the package only through `EventReplayer::triggers()`.
- `composer-dependency-analyser.php` allows the development dependency in `EventReplayer` alone.
- Each test that needs the package carries `#[RequiresMethod(Trigger::class, 'withoutTransition')]`, and the CI job `tests-without-state-machines` runs the suite without it.
- `bin/check-doc-names.php` accepts the package's names unchecked when it is absent.

## INV-32: Registration checks every declared trigger argument against the trigger's constructor

**Rule.** `ResolvedMap::of()` refuses a map whose trigger arguments the trigger could not take. That covers a trigger no `#[Transition]` of the current model's state machines names, an argument name that is not a constructor parameter, two names that differ only in case, a name of a variadic parameter, a constructor whose own parameter names differ only in case, and a left-out parameter the container cannot resolve. Names compare without case. A refused map is never registered.

**Why.** The trigger runs inside a write. A declaration error found there fails that write, and the container's own error names no map. Registration runs when the application boots, and needs no database. [0020](decisions/0020-one-closure-per-trigger-argument.md) gives each argument its own closure so that registration can read the names.

**Exception.** The check cannot see a contextual binding. It treats every class or interface type as resolvable, except a model and an enum. A model or an enum without a default counts as unresolvable, nullable or not: the container builds a model without attributes, and it cannot build an enum. A trigger the container still cannot build refuses the write by [INV-29](#inv-29-a-moved-state-whose-trigger-cannot-run-refuses-the-whole-write).

**Code that must agree.**

- The private `ResolvedMap::validateTriggerArguments()` reads `EventReplayer::triggers()` and the trigger's constructor. The private `ResolvedMap::unresolvable()` decides which parameters the container cannot resolve. It calls `EventReplayer::buildsEmptyModel()`, the predicate of the write-time check in [INV-29](#inv-29-a-moved-state-whose-trigger-cannot-run-refuses-the-whole-write), so the two checks cannot disagree.
- `Trigger::make()` in eloquent-state-machines matches names without case, as the check does.
- `EventBridge::mirror()` resolves the map before it calls `CompatibilityMaps::register()`.

## INV-33: A pending mirrored write completes only on its own statement

**Rule.** A pending mirrored write opens and completes only on the statement its model's own write method sends. The model marks that statement itself, through `HasCompatibility`, and the bridge reads no call stack. The armed write opens only on an `INSERT`, `UPDATE` or `DELETE` on the model's own table, written as the model's query grammar writes that table. It completes only on that statement's `QueryExecuted`. [INV-50](#inv-50-every-mapped-model-marks-its-own-statement) requires the mark of every mapped model.

**Why.** A listener of the beginning event can write the same table, or the same record, before the model's own statement. A match on each statement's SQL text cannot follow every form Eloquent sends. An increment's JSON-path or qualified extra and an `IS DISTINCT FROM` value each defeat it. A miss sends the model's own statement with no events mirrored, no lock and no record snapshot. Laravel 12 and 13 call `Model::setKeysForSaveQuery()` and `Model::getAttributesForInsert()` only inside the model's own write method, after its beginning event. No listener's query passes through them, so a mark there needs no guess. [Decision 0023](decisions/0023-a-mapped-model-marks-its-own-statement.md) records the designs it replaced.

Callbacks can still run a statement around the model's own. A `beforeQuery` callback can run a `SELECT` between the arm and the model's statement, or an insert into another table, such as an audit row. Without the table test, that statement would open the write and complete it, and the model's own statement would then run unmirrored. The test compares the statement with the grammar's own output and parses nothing. So it matches a table prefix, a schema-qualified table and the `ctid` form of a limited update or delete. A `beforeExecuting` callback registered before the bridge's once ran its statement first, so its write to the model's own table took the armed write. The trait now runs `EventBridge::startStatement()` before every other callback, so the model's statement takes the armed write first. Each other callback's statement then finds no armed write, and runs before the write opens. A `QueryExecuted` listener registered before the bridge's can still run a statement after the write opens. Without the identity test on `QueryExecuted`, that statement would complete the write in place of the model's.

A reflection test fails when a Laravel version adds a public instance write method that no test covers.

**Limits.**

- A write to the model's own table that runs between the arm and the model's own statement opens the write. A later `beforeQuery` callback on the model's own query can run one. That write completes the mirror, and the model's own statement runs unmirrored. `EventBridge::watch()` refuses a connection class that declares `beforeExecuting()` itself, so no `beforeExecuting` callback can run one first.
- A throw between the arm and the bridge's first `beforeExecuting` callback leaves the write armed. The connection's next write to the model's table then opens it, unless the next beginning event, `Looping` or termination drops it first.
- An insert that Laravel skips, for a model with no attributes, arms a write that no statement follows. The model's saved event forgets it.

**Code that must agree.**

- `HasCompatibility::setKeysForSaveQuery()` hands the save query of an update, an increment, a decrement, a delete, a soft delete or a force delete to `EventBridge::markOwnStatement()`. `HasCompatibility::getAttributesForInsert()` marks an insert.
- `EventBridge::markOwnStatement()` claims the topmost unclaimed write of the same instance begun under the current model dispatcher, and sets `PendingMirroredWrite::$claimed`. It claims nothing inside `EventBridge::withoutMirroring()`. A save query arms its write from a `beforeQuery` callback, when the query runs, and an insert arms its write at once.
- The private `EventBridge::arm()` records the table in `PendingMirroredWrite::$table`, through the builder's own grammar. A save query's table is `wrapTable()` of its `from`, and an insert's is `wrapTable()` of the model's table. Every update, delete and insert form of Laravel's PostgreSQL grammar writes the table through the same call.
- `DispatchesQueryFailed::registerEventBridge()` moves the connection's `beforeExecuting` callbacks into the trait's own list, and gives Laravel one callback of the trait's. `DispatchesQueryFailed::beforeExecuting()` adds every later callback to that list. So `EventBridge::startStatement()` runs before every other `beforeExecuting` callback, and `EventBridge::openStatement()` after every other one, whichever registered first.
- `EventBridge::startStatement()` asks the private `EventBridge::writesTo()` whether the statement starts with `update `, `insert into ` or `delete from `, then that table and a space. Any other statement leaves the write armed.
- The private `EventBridge::open()` records the statement's SQL string and bindings in `PendingMirroredWrite::$statement` and `PendingMirroredWrite::$bindings`. They are the final values, after every other `beforeExecuting` callback, which can rewrite them by reference. `EventBridge::handleQuery()` completes the open write only on a `QueryExecuted` whose SQL and bindings are identical to them. It parses neither.

## INV-34: A pending mirrored write opens at its statement

**Rule.** A beginning event only records a pending mirrored write. When that write's own statement starts, the bridge opens its transaction or savepoint, locks the affected records and reads them. It opens only a write its model's own write method armed, and `EventBridge::finishFailed()` reaps only a write whose statement started.

Before each statement, and before every other `beforeExecuting` callback, the bridge closes every write on top of the stack whose statement failed, as a failed `select 1` shows. It stops at a healthy one, as [INV-19](#inv-19-a-failed-write-at-level-0-releases-its-transaction) describes. For a write statement with an armed write, it takes the armed write for that statement, and forgets every other write above it. After every other `beforeExecuting` callback, it cleans the top of the stack again. It then forgets every unclaimed, unsent write of the taken write's instance and operation at its depth, and opens the taken write. The bridge pairs the two steps by the call of the trait's callback, not by SQL and bindings.

**Why.** Code can throw between the beginning event and the statement: a cast, the encrypter, a later listener or another `beforeExecuting` callback. A transaction opened at the event would then outlive the write, and hold its locks for the rest of the request.

A listener on the beginning event can also write another mapped model. Without the rule, that inner write would reap the outer write before the outer statement ran, and the outer write would mirror nothing. When the inner write fails and the listener catches the failure, the outer statement comes next. So the callback must go on past the closed inner write and open the outer one.

The bridge opens the write after every other `beforeExecuting` callback, so those callbacks run outside its transaction, as they would without the bridge. A callback that caught a failed statement would otherwise abort the bridge's transaction. Its next read would then roll that transaction back, and the model's statement would run unmirrored. A callback can also make a mirrored write of its own on the model's statement. That inner write replaces the armed write, and opens on its own statement. Each call of the trait's callback holds the write its own statement took, so the outer write still opens on its own statement.

A match on SQL and bindings could not pair the two steps. A callback that rewrote them by reference left no match. A failed statement with the same SQL and bindings, stopped before its last callback, left a wrong match. Either way the model's statement ran with no transaction and nothing mirrored.

A save that throws after its beginning event, and before its statement, leaves its write on the stack. Without the rule, a later write without events to the same record would complete it. The bridge would then mirror a write the application made without events. A retry of that save, or of a delete whose `touchOwners()` threw, begins a second write of the same instance at the same depth. Its own statement completes it. Its finishing event would then pair with the first write, find it unclaimed, and refuse the retry as unmarked. One write method fires one beginning event, so a second at that depth means the first method has returned.

**Lifetime of an unsent write.** A vetoed write, and a write whose beginning listener throws, leave the stack when their dispatch ends. Another write whose statement never comes stays until one of five things removes it. They are a write that opens below it, and a write of the same instance and operation that opens at its depth. They are also its own finishing event, the next `Looping` event and termination. A cast or a `beforeExecuting` callback that throws after the beginning event leaves such a write, and it holds no transaction and no lock.

**Code that must agree.**

- `EventBridge::begin()` pushes the `PendingMirroredWrite`, and drops an armed write whose statement never came. `EventBridge::markOwnStatement()` claims the write and arms it.
- `EventBridge::startStatement()` and `EventBridge::openStatement()` loop over the top of the stack through the private `EventBridge::closeFailedAbove()`. It closes or forgets each write whose statement failed, through the private `EventBridge::closeFailed()`, and stops at a healthy one. With a taken write, it forgets the other writes above it. Without one, a statement leaves the stack alone, so an unclaimed write never opens.
- `EventBridge::startStatement()` returns the write it takes, or null. The trait's callback in `DispatchesQueryFailed::registerEventBridge()` passes that value to `EventBridge::openStatement()` in the same call, with the final SQL and bindings. A throw before then ends the call, and nothing holds the value after it. `EventBridge::openStatement()` then calls the private `EventBridge::open()`, which sets `PendingMirroredWrite::$statementSent`. It returns the write it opened, and the trait holds that write only weakly, and only until `DispatchesQueryFailed::runQueryCallback()` starts that statement.
- `EventBridge::finishFailed()` stops at a write whose statement has not started.
- `EventBridge::fireModelEvent()` forgets a write whose beginning dispatch returns false or throws. `EventBridge::finish()` forgets an unsent write at its own finishing event. `EventBridge::releaseFailed()` forgets every write that holds no transaction.

## INV-35: A pending mirrored write leaves the stack only once its close finishes

**Rule.** The bridge forgets a pending mirrored write only after its commit, rollback or savepoint release finishes. When a commit at level 0 fails, it rolls the connection back to the write's level, forgets the write and rethrows. When a rollback at level 0 fails while Laravel still counts the write's transaction, it keeps the write as failed. The next statement then rolls it back, and nothing commits it. A completed write leaves the stack once its close finishes. Each finishing event pairs with its own write or with none, by the dispatch depth of its model's write.

**Why.** A `COMMIT` can fail, for example on a deferred constraint or a lost connection, and Laravel then still counts the bridge's transaction. A write forgotten before its commit would leave that level with nothing to close it. A `ROLLBACK` that fails without a lost connection leaves Laravel's level as it was too. A write forgotten then would leave every later statement on the connection to fail with 25P02.

A listener can also save the same instance inside its write. A finishing event that paired by instance alone would find the outer write once its own had gone. The bridge would then forget the outer write, and the outer statement would mirror nothing. A save with nothing to change, and the saved event `touchOwners()` fires on a parent, would do the same. A completed write that stayed on the stack would also hold its model until `Looping` or termination. Eloquent fires a write's own events from one method, outside any listener, so the dispatch depth tells them apart from a listener's write.

**Code that must agree.**

- The private `EventBridge::endTransaction()` commits, rolls back or releases the savepoint, and forgets the write only when that throws. After a failed commit it rolls back once more. It keeps a level-0 write whose transaction Laravel still counts after that, and sets `PendingMirroredWrite::$failed`, so `EventBridge::closeFailed()` rolls it back without `select 1`. `EventBridge::finish()` rolls back, rather than commits, a write with that property. It commits and rolls back outside `EventBridge::internally()`. Laravel runs after-commit work inside `Connection::commit()`, so a mapped write there opens, locks and mirrors like any other.
- `EventBridge::close()` calls it, then forgets the write. `EventBridge::complete()` ends through `EventBridge::close()`, so a completed write leaves the stack. Laravel's `Connection::rollBack()` resets the level whether or not PDO still holds the transaction.
- `HasCompatibility::fireModelEvent()` hands every model event to `EventBridge::fireModelEvent()`. That raises the dispatch depth around the dispatch, and lowers it in a `finally` block. `EventBridge::begin()` records the depth in `PendingMirroredWrite::$depth`.
- `EventBridge::finish()` takes only the instance's writes at the current depth. A created, updated or deleted event takes a write of its own operation, and a saved event a write of either save operation.

## INV-36: An attribute without a btree equality compares as text

**Rule.** The triggers compare an attribute as text when its type has no default btree equality operator. That covers the read-only check, `SqlFragments::changed()`, each `CASE WHEN` in `Compiler::update()` and the conflict check's `ROW()`. `json` and `xml` are such types, and so is a domain over one. A composite type compares as text too.

**Why.** `json` has no `=` operator, so `IS DISTINCT FROM` fails on every update that compares it, although the install succeeds. Text keeps a whitespace or key-order change the caller wrote, which a cast to `jsonb` would discard.

**Code that must agree.** `ColumnCatalog::equatable()` follows a domain to its base type and an array to its element type. The private `Compiler::compared()`, `Compiler::comparesAsText()` and `Compiler::comparedAsText()` apply it. `SqlFragments::changed()` takes the columns it compares as text. A retained attribute takes its column's catalog type, and a mapped attribute its `Attribute::asType()`.

## INV-37: A trigger function runs only for its own relation

**Rule.** Every compiled function refuses with CM001 when `TG_RELID` names a relation other than its own or a partition of its own. Install and recompile revoke `EXECUTE` on each function from `PUBLIC`. The compiled SQL qualifies every built-in function it calls with `pg_catalog`. Only trusted roles may create objects in the installation schema, and the package does not check that.

**Why.** The functions are `SECURITY DEFINER`. Without the relation check, any role that can create a temporary table could attach one as a trigger there. The trigger would then run the body with the owner's rights, for example to delete retained rows. PostgreSQL checks `EXECUTE` when a trigger is created, not when it fires, so the revoke leaves the map's own triggers working. PostgreSQL clones a row trigger on a partitioned table onto each partition, and the clone fires with the partition as `TG_RELID`. Only the partitioned table's owner can attach a partition, so the partition root admits no other role's table.

PostgreSQL picks a function by its closest argument match, whatever its schema's place on the search path. An unqualified `hashtextextended()` call would therefore run a `hashtextextended(text, integer)` in the installation schema, in place of the built-in `hashtextextended(text, bigint)`, with the owner's rights. A qualified call finds only the built-in. An operator resolves the same way, and so does every function and operator in a map's own expressions. The compiled SQL leaves those unqualified, so the schema's privileges are what keep another role's code out. A type name has no argument match, and `pg_catalog` precedes the installation schema, so built-in type names stay unqualified.

**Code that must agree.** `Compiler::function()` writes the check as the first statement of every body, and accepts a relation whose `pg_partition_root()` is its own. `Compiler::compile()` adds a `REVOKE` after each function. The relation is the view for the insert, update and delete functions, and the primary table for the primary insert and primary delete functions. A refuse function's relation is its checked table, and a designate function's is its dependent's table. `Compiler::function()`, `Compiler::refuseFunction()`, `Compiler::skipWhileApplying()`, `Compiler::insertFunction()` and `Compiler::primaryInsertFunction()` qualify their built-in calls. `ColumnCatalog::of()` qualifies the `nextval()` default it builds for a `GENERATED BY DEFAULT` identity.

**Untested.** No test pins the qualified `set_config()`, `current_setting()`, `pg_advisory_xact_lock()` or `nextval()` calls, and nothing checks the installation schema's privileges.

## INV-38: The schema refuses a table with row-level security

**Rule.** `Installer::install()` and `Installer::recompile()` refuse a map whose retained table or any current table has row-level security enabled.

**Why.** The view reads with its owner's rights, and the `SECURITY DEFINER` triggers write with them. A role granted the view would read and write past the table's policies, for example into another tenant's rows. The package cannot enforce a caller's policies yet.

**Code that must agree.** The private `Installer::applyStatements()`, before it compiles, for both actions. With `--retained-table-is-currently`, it reads the retained table under that name.

## INV-39: Every insert fills each required column

**Rule.** Compile refuses a `NOT NULL` column without a default that an insert leaves empty. It checks the retained table on a primary insert and on an insert through the view. It checks the primary table and each inserted dependent's table on an insert through the view. An identity or generated column counts as filled.

**Why.** Otherwise the install would succeed, and every insert into the current table would then fail with 23502, factories and seeders included.

**Code that must agree.** The private `Compiler::validateFills()` and `Compiler::refuseEmpty()`, with `Column::needsAValue()` and `Column::$generated` from `ColumnCatalog::of()`. The columns they count as filled must match what `Compiler::primaryInsertFunction()`, `Compiler::insertFunction()` and `Compiler::dependentValues()` insert, so both read the private `Compiler::insertAssignmentsTo()`. A dependent counts only when the view inserts its rows, so only when it has an attribute or a fill. A read-only mapped attribute counts, as [INV-49](#inv-49-an-insert-through-the-view-stores-a-read-only-attribute) requires. `Compiler::compile()` runs the check after it builds the functions, so a missing column refuses first. A column with a default counts as filled, so the insert must let that default fill it, as [INV-46](#inv-46-an-insert-through-the-view-keeps-a-not-null-columns-default) requires.

## INV-40: An insert through the view returns the row as the view reads it

**Rule.** Before `RETURN NEW`, the insert trigger reads every attribute back from the stored rows into `NEW`, as the view reads it.

**Why.** `INSERT ... RETURNING` reports `NEW`. Without the read, it reports only what the caller sent: no default, fill or trigger value, and no value a read expression formats.

**Code that must agree.** `Compiler::insertFunction()` reads through `Compiler::read()`, which `Compiler::view()` uses too. The read leaves out the hidden states' filter, so a hidden record still returns its row.

## INV-41: A recompile replaces the view and keeps its grants

**Rule.** `Installer::recompile()` drops the view and creates it again in its transaction. It saves the view's grants first and grants them again after, a column grant only when the new view has that column. It refuses with `InvalidCompatibilityMap` while another object depends on the view or uses its row type, and names each one. It also refuses, by name, a trigger or a rule on the view that is not the map's own. It never drops with `CASCADE`. The new view carries no grant its default privileges add, as [INV-48](#inv-48-the-new-view-carries-no-grant-its-default-privileges-add) requires.

**Why.** `CREATE OR REPLACE VIEW` cannot drop, retype or reorder a column. A dependent object would block the drop with a raw 2BP01 error, and `CASCADE` would drop that object silently. `DROP VIEW` drops a trigger or a rule on the view silently, even without `CASCADE`. [0022](decisions/0022-a-recompile-replaces-the-view-and-refuses-its-dependents.md) records the decision.

**Code that must agree.** The private `Installer::recompileStatements()`, `Installer::viewGrants()` and `Installer::applyStatements()`. `Installer::applyStatements()` reads `pg_depend` for normal dependencies on the view and its row type, and for automatic ones from a trigger or a rule. A trigger named with the map's object prefix is the map's own, as for `Installer::dropStatements()`. `Installer::recompileStatements()` keeps a column grant when the map still has an attribute of that name, because the map's attribute columns are the new view's columns. `Installer::viewGrants()` saves a view with no explicit grants as its owner's default privileges, through `acldefault()`. It saves no grant of the recompiling role, which owns the new view.

## INV-42: Only the compatibility view reads the retained table

**Rule.** `Installer::install()` refuses while another view or materialized view reads the retained table, and names each one.

**Why.** A view on the old table follows its rename and keeps reading the retained table. It then misses every mapped attribute, and the triggers never see its writes. [Adopting a map](adopting-a-map.md#before-the-install-views-on-the-old-table) describes how to point such a view at the compatibility view.

**Code that must agree.** The private `Installer::installStatements()` finds the readers through `pg_depend` and `pg_rewrite`, before it lists any statement. With `--retained-table-is-currently`, it looks for the readers of the table under that name.

## INV-43: The event bridge refuses a Parental model

**Rule.** `EventBridge::mirror()` refuses a map that names a class using a trait named Tightenco\Parental\HasChildren, which it finds through Laravel's `class_uses_recursive()`.

**Why.** Parental hydrates a child class, and the child fires its model events under its own class name. The bridge finds a model's maps by the exact class name, so it would silently mirror none of those writes. Its replays would also build the parent class, not the child.

**Code that must agree.** `EventBridge::mirror()` checks the previous model and every current model before it adds the map to its index. It resolves the map for its checks, and calls `CompatibilityMaps::register()` only after every check passes. So a refused map never reaches `compatibility:check`. The package adds no dependency on Parental.

## INV-44: No map expression ends a function body

**Rule.** `Compiler::function()` refuses a body that contains `$compatibility$`, the tag that quotes it.

**Why.** A fill, a condition, a read or a write expression with that tag would end the body early. The rest of the body would then run as SQL of its own when the install runs.

**Code that must agree.** `Compiler::function()`, which builds every function. A default lands in an `ALTER VIEW` statement, never in a body, so the check does not cover it.

## INV-45: A generated column is read-only through the view

**Rule.** The catalog reports no default for a generated column. Compile refuses a map whose attribute is not read-only and writes a generated column or a `GENERATED ALWAYS` identity column. The message names the attribute and the column, and says to declare it read-only. A `GENERATED BY DEFAULT` identity stays writable. An insert through the view leaves out a read-only attribute, retained or mapped, over a column the database always generates.

**Why.** `pg_attrdef` holds a generated column's expression. Reported as a default, it would reach `ALTER VIEW ... SET DEFAULT`, which PostgreSQL rejects, because a default cannot reference a column. So a map that exposes a generated column would fail to install or recompile. A write of a value to a generated column, or to a `GENERATED ALWAYS` identity, fails on every write through the view. So compile refuses the map instead.

An insert that omits a `GENERATED BY DEFAULT` identity attribute takes the identity's next value. The catalog reports that column's default as `pg_catalog.nextval(pg_catalog.pg_get_serial_sequence('<schema>.<table>', '<column>')::regclass)`. The table name carries its schema, because a view default runs on the writer's search path. It is the map's table name, so a compile before the rename names the retained table as it is called after the rename. A retained or primary attribute takes it as its view default, and [INV-46](#inv-46-an-insert-through-the-view-keeps-a-not-null-columns-default) fills every other insert target.

**Code that must agree.** `ColumnCatalog::of()` reads `attgenerated` and `attidentity` into `Column::$default`, `Column::$generated` and `Column::$alwaysGenerated`. `Compiler::validateColumns()` refuses, and `Compiler::defaults()` takes the identity's default. `Compiler::insertFunction()` skips a retained attribute whose column has `Column::$alwaysGenerated`, and the private `Compiler::insertAssignmentsTo()` skips such a read-only mapped attribute. `Column::needsAValue()` keeps counting every generated and identity column as filled, so [INV-39](#inv-39-every-insert-fills-each-required-column) still holds.

## INV-46: An insert through the view keeps a NOT NULL column's default

**Rule.** An insert through the view writes `COALESCE(<value>, <default>)` to each attribute's target column that is `NOT NULL` and has a catalog default. That covers the primary table, the retained targets of mapped attributes and each dependent's table. A nullable column with a default stores NULL when the insert omits its attribute. A column's catalog default is its own default. Without one, it is the default of the column's domain, or else of the nearest base domain that declares one. A column is `NOT NULL` when the column or any domain in that chain declares it. A column whose type is a domain receives `CAST(COALESCE(<value>, <default>) AS <domain>)` instead, with the value cast to the domain's base type. A NULL into a `NOT NULL` domain without a default still fails with 23502.

**Why.** The insert writes every target column explicitly, so without the `COALESCE` an omitted attribute's NULL would replace the column's default. A dependent's view column has no default, and a `writesTo()` expression of NULL is NULL. A `NOT NULL` column with a default would then fail with 23502, although [INV-39](#inv-39-every-insert-fills-each-required-column) counts it as filled. A cast of NULL to a `NOT NULL` domain fails with 23502 too, before a `COALESCE` around it runs. So the default applies before the cast to the domain, and the cast checks the default as a native insert does.

**Code that must agree.** `Compiler::insertValues()` wraps each assignment's value, for `Compiler::insertFunction()` and for both dependent inserts through `Compiler::dependentValues()`. The update trigger's create branch in `Compiler::updateDependent()` is one of those two. `Column::$notNull`, `Column::$default` and `Column::$baseType` come from `ColumnCatalog::of()`. `Column::$baseType` is the base type of the deepest domain in the chain, and it is null for a column whose type is not a domain. A fill from `fillWhenCreatedFromPrevious()` keeps its own expression.

## INV-47: A map's tables resolve in the current schema alone

**Rule.** Compile, install and recompile look up the retained table and every current table in `current_schema()` alone. A table that exists only in a later schema on the search path refuses at compile with `InvalidCompatibilityMap`: "the current table <table> does not exist".

**Why.** Each compiled function pins its `search_path` to the current schema, and its relation check names that schema. A table found further down the search path would install without error. Its trigger would then land on the other schema's table, and every insert there would fail with 42P01. `Installer::uninstall()` and `Installer::recompile()` could not remove that trigger.

**Code that must agree.** `ColumnCatalog::of()` and every `to_regclass()` on a map table in `Installer` name the table as `quote_ident(current_schema()) || '.' || <table>`. The private `Installer::relation()` does the same for the view. Under `--retained-table-is-currently`, they name the retained table by its current name, in the current schema too. `Compiler::validateColumns()` refuses a retained or current table that `ColumnCatalog::of()` does not find. `EventReplayer::changed()` reads column types through `ColumnCatalog`, so it reads the current schema alone too.

## INV-48: The new view carries no grant its default privileges add

**Rule.** After `Installer::install()` or `Installer::recompile()` creates the view, it revokes every grant on the view that its owner does not hold. Install then copies the retained table's grants, and recompile restores the saved grants. So the view carries those grants alone.

**Why.** PostgreSQL applies `ALTER DEFAULT PRIVILEGES` to every view a role creates. A recompile would otherwise give back access a revoke had removed, such as `SELECT` for `PUBLIC`. A default `UPDATE` or `DELETE` grant would also reach the tables through the `SECURITY DEFINER` triggers.

**Code that must agree.** The private `Installer::revokeStatement()`, which the private `Installer::applyStatements()` lists after `CompiledSql::statements()`, for both actions. It lists one `DO` block, which reads the view's ACL when it runs and revokes all from every grantee except the view's owner. So a default privilege that another session adds after the list is built, or after `compatibility:sql` prints it, still never reaches the view. It leaves the owner's own privileges as they are. `Installer::grantStatements()` and the saved grants of `Installer::recompileStatements()` follow it.

## INV-49: An insert through the view stores a read-only attribute

**Rule.** A read-only attribute cannot change through the view, and an insert through the view still stores its value. A retained attribute stores into its own column, and a mapped attribute into its `storedAs()` column. A mapped attribute with only `readAs()` has no stored column, so it stores nothing. Three cases leave the attribute out of the insert or change its value:

- A read-only attribute stored in a column that a writable attribute writes stays out of the insert, as [INV-8](#inv-8-each-stored-column-has-one-owner) says.
- An attribute over a column the database always generates stays out of the insert, as [INV-45](#inv-45-a-generated-column-is-read-only-through-the-view) says.
- An omitted value takes the column's default, as [INV-46](#inv-46-an-insert-through-the-view-keeps-a-not-null-columns-default) says.

The update trigger refuses a change to a read-only attribute with CM001.

**Why.** An Eloquent `create()` with a read-only mapped `created_at` sends the value, and the model holds it. If the insert left it out, the stored column would stay NULL and nothing would raise. A retained attribute and a mapped attribute must also behave alike.

**Code that must agree.** `Attribute::readOnlyBecause()` clears a mapped attribute's writes. The private `Compiler::insertAssignmentsTo()` therefore adds each read-only mapped attribute's `storedAs()` target to an alias's writes, unless `ResolvedMap::writes()` reports a writable owner. `Compiler::insertFunction()` reads it for the primary, retained and dependent inserts, and for each dependent's default `createdWhen()` condition. `Compiler::validateFills()` reads it too, so [INV-39](#inv-39-every-insert-fills-each-required-column) counts such a column as filled. `Compiler::insertFunction()` writes each retained attribute, read-only or not. `Compiler::updateFunction()` refuses a change through `ResolvedMap::readOnlyAttributes()`. The update trigger's create branch in `Compiler::updateDependent()` reads `ResolvedMap::assignmentsTo()` alone, because an unchanged read-only value there is NULL.

## INV-50: Every mapped model marks its own statement

**Rule.** Every model of a mirrored map implements `Compatible` and uses `HasCompatibility`. Its `setKeysForSaveQuery()`, `getAttributesForInsert()` and `fireModelEvent()` resolve to the trait's methods. `EventBridge::mirror()` refuses any other map with `InvalidCompatibilityMap`. It also refuses a model that uses `Illuminate\Database\Eloquent\Relations\Concerns\AsPivot`, as every Eloquent pivot does. A created, updated or deleted event for an instance whose pending mirrored write no mark claimed refuses the write with `CompatibilityEventMirrorBypassed`. Inside a transaction the throw rolls the write back, and at level 0 the statement has already committed.

**Why.** The bridge opens a write only on the statement its model marks, as [INV-33](#inv-33-a-pending-mirrored-write-completes-only-on-its-own-statement) records. A model without the trait would mark nothing. So would a model whose own declaration replaces a method of the trait, because PHP gives a class's method precedence over a trait's. The bridge also sees a model's events only through `HasCompatibility::fireModelEvent()`. The model's writes would then mirror nothing, with no lock and no record snapshot, and nothing would say so.

A future Laravel write path that calls neither marking method has the same effect. `Illuminate\Database\Eloquent\Relations\Concerns\AsPivot::delete()` is such a path today. It deletes a pivot loaded without its key through its own query, so the delete would reach the database before the deleted event refused it. Eloquent fires the created, updated and deleted events only after its own statement, so an unclaimed write there proves a statement ran without its mark.

**Code that must agree.** `EventBridge::mirror()` checks the previous model and every current model, after the Parental check of [INV-43](#inv-43-the-event-bridge-refuses-a-parental-model) and before it registers the map. It finds the pivot trait through `class_uses_recursive()`, before the mark check. It finds the interface through `is_subclass_of()` and the trait through `class_uses_recursive()`. Through `\ReflectionMethod`, it compares the file and the first line of each resolved method with those of the trait's method. So a declaration in the class, in any class it extends or in another trait fails, and a trait the class inherits passes. The `HasCompatibilityMustNotRedeclareWriteHooks` PHPStan rule makes the same comparison for a named class. `EventBridge::finish()` forgets the unclaimed write, then throws `CompatibilityEventMirrorBypassed::byUnmarkedStatement()`. A saved event can follow a save that sent no statement, so it never throws.

## INV-51: A compile before the rename refuses a default or a column type that references the retained table

**Rule.** Under `--retained-table-is-currently`, compile refuses with `InvalidCompatibilityMap` a column default that references the retained table, such as `'widgets'::regclass`. A reference to the table's row type or its array type, such as `'widgets'::regtype`, refuses too. It refuses only a default the compiled SQL takes: a view default, or a `NOT NULL` insert target's default. That default is the column's catalog default of [INV-46](#inv-46-an-insert-through-the-view-keeps-a-not-null-columns-default), so a domain's default is checked when the column takes it. A written column whose type references the retained table's row type or that type's array type refuses too, such as a `widgets[]` column of a current table. A type in its domain chain counts. That message also names the table and the column, and says to compile after the rename. A serial column's default references its sequence, not the table, so it compiles. A generated column's expression is no default, so it compiles.

**Why.** PostgreSQL prints a relation that a default references under that relation's name when the catalog is read. It prints a column's type, and its domain's base type, the same way. Before the rename, that name is the retained table's current name, often the view's own name. The printed SQL would then reference the view after the rename, and an insert through the view would take a different value than after a real install. An identity's default is built from the map's table name instead, as [INV-45](#inv-45-a-generated-column-is-read-only-through-the-view) records, so it needs no refusal.

**Code that must agree.** `ColumnCatalog::of()` reads `Column::$defaultReferencesSource` from `pg_depend`, for a column that is neither generated nor a `GENERATED BY DEFAULT` identity. It is a normal dependency on the retained table under its current name: on its `pg_class` row, its row type or that type's array type. The dependent row is the column's `pg_attrdef` row when the column has a default of its own. Otherwise it is the `pg_type` row of the first domain in the chain that declares a default. The automatic dependency every default has on its own column does not count. The private `Compiler::storedDefault()` refuses, for `Compiler::defaults()` and `Compiler::insertValues()`.

`ColumnCatalog::of()` reads `Column::$typeReferencesSource`. It holds when the column's type, or a base type in its domain chain, is the retained table's row type or that type's array type under the table's current name. The private `Compiler::value()` refuses, for every value it casts to a column's type. `Compiler::insertValues()` calls it before its domain cast to the base type, so that cast never prints a refused type.

## INV-52: Every planner expression comes from the structured parts of the view column

**Rule.** Every expression in a statistics object or an index comes from `Attribute::plannerExpression()`, which builds it from the same parts that build the view column: the stored column, the declared zone and the declared type. The package parses no expression an author wrote. An attribute that has no stored column, or that only says `Attribute::readAs()`, has no planner expression, and `ResolvedMap::of()` refuses an index that names it. A statistics object covers only a zoned attribute that the primary current model's table stores.

**Why.** A query on the view can use an index only when the index expression is the view's expression. An author who writes the same expression a second time, for the index, can let the two drift, and the index then never serves a query and nothing says so. Parsing `Attribute::readAs()` text to recover its parts would let a new form of SQL slip past the parser. The zone and the type are declared once as values, so the view column, the write, the default, the statistics and the index all read one value.

**Code that must agree.** `Attribute::storedAs()` and `Attribute::inTimeZone()` build the read and the write, and `Attribute::plannerExpression()` builds the planner expression, each through the private `Attribute::inZone()`. The private `Compiler::inTimeZone()` converts the same way, for the write of a read-only attribute on an insert through the view and for the stored column's default, in `Compiler::insertAssignmentsTo()` and `Compiler::defaults()`, so it must change with `Attribute::inZone()`. `ResolvedMap::statisticsExpressions()`, `ResolvedMap::indexColumns()` and `ResolvedMap::indexFilter()` return only what `Attribute::plannerExpression()` returns, and `ResolvedMap::statisticsExpressions()` returns each distinct expression once. The private `Compiler::planner()` writes every planner statement from them, and `Compiler::prepare()` returns the same list with `CREATE INDEX CONCURRENTLY`. A change to how the view reads a mapped column must change `Attribute::plannerExpression()` with it.
