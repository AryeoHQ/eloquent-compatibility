# 0019. A connection trait raises QueryFailed

## Context

Laravel raises `QueryExecuted` only for a statement that succeeds. A failed statement raises nothing, so the bridge releases a failed level-0 write later, as [decision 0005](0005-a-failed-statement-is-released-later.md) records. Until then the connection reports a transaction the caller never opened.

Laravel pull request #62168 proposed a framework event for a failed statement. The maintainer closed it unmerged and suggested a package instead.

## Decision

The package ships `Connections\DispatchesQueryFailed`, a trait the application adds to its own connection class, and its event `Connections\Events\QueryFailed`. The trait overrides `DispatchesQueryFailed::handleQueryException()` to raise the event. `EventBridge::watch()` hands the trait three callbacks through `DispatchesQueryFailed::registerEventBridge()`, and the trait calls the bridge through them:

- `EventBridge::startStatement()` runs before every other `beforeExecuting` callback. It closes failed writes, and takes the armed write for the model's own statement.
- `EventBridge::openStatement()` runs after every other `beforeExecuting` callback. It opens the write the first callback took for the same SQL and bindings.
- `EventBridge::handleFailure()` runs before the trait raises `QueryFailed`, outside the trait's guard against a second event. It rolls back a write whose own statement failed.

`DispatchesQueryFailed::beforeExecuting()` keeps that order for every callback registered later. The trait reports a throwable from a `QueryFailed` listener, and throws the statement's `QueryException` again.

The bridge requires the trait. `EventBridge::watch()` refuses every mirrored write on a connection whose class does not use it, through `CompatibilityConnectionUnsupported::byMissingTrait()`. The refusal names the connection, its class and the trait. A class that resolves `beforeExecuting()`, `handleQueryException()` or `registerEventBridge()` to a declaration of its own gets `CompatibilityConnectionUnsupported::byOverride()`, which names the declaring class. `CompatibilityWriteRefused::isRefusal()` rejects both, because neither refuses a rule of the map.

## Consequences

- The trait is required, not opt-in. Without it, a failed level-0 write left the bridge's transaction aborted until the next statement. Laravel creates the savepoint of `Connection::transaction()` and `createOrFirst()` through `getPdo()->exec()`, so both failed with 25P02 in that window. A `Connection::afterCommit()` callback registered there joined the bridge's transaction and was lost with it.
- The refusal comes at the first mirrored write on each connection, not at registration. `EventBridge::mirror()` resolves no connection, so boot and `package:discover` still need no database, as [decision 0011](0011-registration-needs-no-database.md) records.
- `EventBridge::handleFailure()` releases a write only when its own statement failed, on the connection object the write began on. At level 0 it rolls the write back without `select 1` first. A statement that PDO refuses before the database sees it, such as one with mixed named and positional parameters, leaves the transaction healthy. A `select 1` would then have kept the transaction open, with its locks and every later statement inside it.
- The release runs before `QueryFailed` reaches any listener. When the bridge listened for the event itself, an earlier listener that returned false or threw stopped the release, and the transaction stayed open. A throwing listener also replaced the `QueryException` the caller received. A mirrored write that fails inside a `QueryFailed` listener raises no second event, and the release still runs for it.
- Every other `beforeExecuting` callback runs before the write opens, as it would without the bridge. When the bridge opened the write first, a callback that caught a failed statement aborted the bridge's transaction. Its next read then rolled that transaction back, and the model's statement ran unmirrored at level 0. Now the caught failure aborts nothing, and the write is mirrored. A callback that throws stops the statement before the write opens, so it leaves no transaction behind.
- A callback can make a mirrored write of its own on the model's statement, such as an insert of another mapped model. That write opens and closes on its own statement first, outside the outer write's transaction. The inner write replaces the armed write, so the first callback records the write it takes with each starting statement. The outer write therefore still opens on its own statement.
- The releases of [decision 0005](0005-a-failed-statement-is-released-later.md) stay for a level-0 write that the trait does not close. A `QueryExecuted` listener registered before the bridge's can run another statement after the write's own, and let its failure escape. That failure leaves the write's transaction aborted, and nothing reports it as the write's own.
- A `beforeExecuting` callback registered before the bridge's first mirrored write once ran first. On the model's update, its own insert into the same table opened the armed write and completed it, and the model's statement then ran with nothing mirrored. The pre-adoption review of PR #1, thread 4398063512, raised this. `EventBridge::startStatement()` now runs first, whichever registered first, so the model's statement takes the armed write before any other callback runs. Such an insert then finds no armed write, and runs before the write opens.
- The trait overrides no other method of Laravel's connection. It therefore composes with a connection class that overrides `Connection::run()` or the lost-connection retry, such as Aryeo's ResilientPostgresConnection. `DispatchesQueryFailedTest` runs each case on the fixture `ResilientConnection`, which overrides both. It also runs each case on `ChildConnection`, whose parent class uses the trait too. Only the copy of the trait in the subclass calls the bridge and raises the event, so each failure raises it once.
- The event fires after the lost-connection retry, and never for a statement a retry recovers.
- An application that already resolves its own connection class adds the trait there. Any other application registers a resolver with `Connection::resolverFor()`, as the README and [adopting a map](../adopting-a-map.md) show. The refusal offers `Connection::resolverFor()` only for a framework class, and otherwise names the class that needs the trait.
- The event is the package's own class, not a Laravel event. `QueryFailed::$time` is null, because no statement completed.
