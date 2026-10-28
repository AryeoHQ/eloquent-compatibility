# 0023. A mapped model marks its own statement

## Context

The event bridge opens a pending write on the model's own statement. It must ignore a listener's statement to the same table or the same record. It once matched the SQL text of each statement against the model: the relation, the operation, the columns and the key's placeholder. It also counted the event dispatcher's frames on the call stack, so that a listener's statement never matched. Each new form of Eloquent's SQL needed a new rule. An increment's JSON-path or qualified extra and an `IS DISTINCT FROM` value each defeated the match. A miss sent the model's own statement with no events mirrored, no lock and no snapshot.

A spike on 2026-10-27 tested a link that reads no SQL. Laravel 12 and 13 call `Model::setKeysForSaveQuery()` and `Model::getAttributesForInsert()` only inside the model's own write method, after its beginning event. No listener's query passes through either method.

## Decision

Every model of a mirrored map implements `Compatible` and uses `HasCompatibility`. The trait overrides the two protected methods, calls each parent first, and hands the model to `EventBridge::markOwnStatement()`. The bridge claims the model's pending write and arms it. The connection's next `INSERT`, `UPDATE` or `DELETE` opens the armed write.

`EventBridge::mirror()` refuses a map whose model lacks the interface or the trait. It also refuses a model whose two methods resolve to another declaration. A created, updated or deleted event for an unclaimed pending write throws `CompatibilityEventMirrorBypassed::byUnmarkedStatement()`.

The user chose this design on 2026-10-27, over a design on the connection alone.

## Declined: a link from the connection alone

A design on the connection alone would change no model. It cannot tell the two statements apart, for four reasons:

1. Laravel hands the connection neither the model nor the Eloquent builder. `Model::newBaseQueryBuilder()` calls `Connection::query()` with no argument, and the Eloquent builder passes the base query only the table name.
2. A listener's write through the same instance's `Model::newQuery()` comes down the same chain. It has the same connection and the same table, and it can bind the same key. Any test on the connection therefore guesses at the statement's shape, as the matcher did.
3. Timing does not separate the two either. `Model::save()` builds its query before the saving event, so the model's own builder exists before the bridge begins. `Model::delete()` touches the model's owners between its deleting event and its own statement, at the same dispatch depth as that statement.
4. The only code that runs in the model's own write method, after its beginning event and on no listener's query, is on the model.

Two other designs were declined too:

- A custom Eloquent builder through `Model::newEloquentBuilder()`. The model's own write and a listener's query use the same builder class.
- An arm inside `setKeysForSaveQuery()` itself. A soft delete sets its deleted-at and updated-at attributes after that method, and a mutator there is user code that can send a statement.

## Consequences

- An application adds one interface and one `use` line to every mapped model. The package overrides two protected Laravel methods.
- A model that declares either method itself cannot be mapped. A composite-key package that sets the save query's keys is one example.
- A future Laravel write method that calls neither method never claims its write. `HasCompatibilityTest::it_covers_each_public_instance_write_method_of_eloquent_with_a_test_of_its_own()` fails when Laravel adds a public write method that no test covers. At run time, the write's created, updated or deleted event refuses it, and inside a transaction the refusal rolls it back.
- The bridge reads one thing from the SQL, the first keyword. So a `SELECT` that an earlier `beforeExecuting` callback runs cannot open the armed write.
- The faked-dispatcher guard now throws before the statement, because the mark runs before it. A raw write runs no model method, so it never throws.
- Registration still needs no database, as [decision 0011](0011-registration-needs-no-database.md) records. The new checks read the model classes through reflection.
- [INV-33](../invariants.md) and [INV-50](../invariants.md) record the rules.
