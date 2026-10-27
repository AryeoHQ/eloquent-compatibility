# 0019. A connection trait raises QueryFailed

## Context

Laravel raises `QueryExecuted` only for a statement that succeeds. A failed statement raises nothing, so the bridge releases a failed level-0 write later, as [decision 0005](0005-a-failed-statement-is-released-later.md) records. Until then the connection reports a transaction the caller never opened.

Laravel pull request #62168 proposed a framework event for a failed statement. The maintainer closed it unmerged and suggested a package instead.

## Decision

The package ships `Connections\DispatchesQueryFailed`, a trait the application adds to its own connection class, and its event `Connections\Events\QueryFailed`. The trait overrides `DispatchesQueryFailed::handleQueryException()` to raise the event. `EventBridge::mirror()` listens for the event, and `EventBridge::handleFailure()` rolls the failed write back at once. The trait also overrides `DispatchesQueryFailed::beforeExecuting()`, which runs the callback of `EventBridge::watch()` before every other `beforeExecuting` callback.

The bridge requires the trait. `EventBridge::watch()` refuses every mirrored write on a connection whose class does not use it, through `CompatibilityWriteRefused::byConnection()`. The refusal names the connection, its class and the trait.

## Consequences

- The trait is required, not opt-in. Without it, a failed level-0 write left the bridge's transaction aborted until the next statement. Laravel creates the savepoint of `Connection::transaction()` and `createOrFirst()` through `getPdo()->exec()`, so both failed with 25P02 in that window. A `Connection::afterCommit()` callback registered there joined the bridge's transaction and was lost with it.
- The refusal comes at the first mirrored write on each connection, not at registration. `EventBridge::mirror()` resolves no connection, so boot and `package:discover` still need no database, as [decision 0011](0011-registration-needs-no-database.md) records.
- `EventBridge::handleFailure()` releases a write only when its own statement failed. A failure of another statement in the write's window leaves the transaction aborted. The write's own statement then fails with 25P02, rather than running unmirrored after a rollback.
- The releases of [decision 0005](0005-a-failed-statement-is-released-later.md) stay for a level-0 write that `QueryFailed` does not close. That covers a write whose transaction another statement in its window aborted, and a write whose statement another `beforeExecuting` callback stopped. No event reports either case as the write's own.
- A `beforeExecuting` callback registered before the bridge's first mirrored write once ran first. On the model's update, its own insert into the same table opened the armed write and completed it, and the model's statement then ran with nothing mirrored. The pre-adoption review of PR #1, thread 4398063512, raised this. The bridge's callback now runs first, whichever registered first, so such a statement runs inside the open write.
- The trait overrides no other method. It therefore composes with a connection class that overrides `Connection::run()` or the lost-connection retry, such as Aryeo's ResilientPostgresConnection. A class that declares `beforeExecuting()` itself replaces the trait's, and the bridge's callback then runs in the order it registered. `DispatchesQueryFailedTest` runs each case on the fixture `ResilientConnection`, which overrides both.
- The event fires after the lost-connection retry, and never for a statement a retry recovers.
- An application that already resolves its own connection class adds the trait there. Any other application registers a resolver with `Connection::resolverFor()`, as the README and [adopting a map](../adopting-a-map.md) show.
- The event is the package's own class, not a Laravel event. `QueryFailed::$time` is null, because no statement completed.
