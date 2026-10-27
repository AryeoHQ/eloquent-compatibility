# Failure and rollback

This flow follows a write that fails: refused by the database, refused by a mirrored listener, or failed for any other reason. It covers how a refusal reaches PHP, and how the event bridge gives back its transaction or savepoint.

```mermaid
flowchart TD
  Begin["EventBridge::begin()"] -->|connection without DispatchesQueryFailed| Refused["CompatibilityWriteRefused, nothing opened"]
  Begin -->|throw before the statement| Nothing["nothing opened"]
  Begin --> Open["EventBridge::open(), as the statement starts"]
  Open --> Statement{"statement"}
  Statement -->|succeeds| Complete["EventBridge::complete()"]
  Complete -->|replay succeeds| Commit["commit, or release the savepoint"]
  Complete -->|replay throws| Rollback["roll back"]
  Commit -->|commit fails| Rollback
  Statement -->|fails, level 0| Failure["EventBridge::handleFailure(), on QueryFailed"]
  Failure --> Rollback
  Open -->|another statement fails in the window,<br/>or another callback stops the statement, level 0| Pending["pending until the next statement,<br/>the next bridged write, the next transaction,<br/>Looping or terminate"]
  Pending --> Rollback
  Statement -->|fails, inside a caller's transaction| Caller["the caller's transaction is aborted"]
```

## Refusals

Every refusal in the compiled SQL raises SQLSTATE CM001, with a message, a detail and a hint. They say what was refused, why, and what to do instead. `SqlFragments::refuse()` builds each one.

Laravel turns the error into a `QueryException` with the code CM001, which `CompatibilityWriteRefused::isRefusal()` recognises. `CompatibilityWriteRefused::fromQueryException()` reads the driver's `ERROR`, `DETAIL` and `HINT` lines into `CompatibilityWriteRefused::$refused`, `CompatibilityWriteRefused::$because` and `CompatibilityWriteRefused::$instead`.

The event bridge refuses in PHP. A mirrored listener that refuses or changes the record makes the replayer throw `CompatibilityWriteRefused::byMirroredListener()` or `CompatibilityWriteRefused::byMirroredChange()` directly. A state change whose trigger cannot run makes it throw `CompatibilityWriteRefused::byTransition()`, as [replaying a transition](replaying-a-transition.md) describes. A connection whose class lacks the required trait makes the bridge throw `CompatibilityWriteRefused::byConnection()` at its first mirrored write, as [the required trait](#the-required-trait) describes. `CompatibilityWriteRefused::isRefusal()` accepts both forms, and `InteractsWithCompatibilityMaps::assertWriteRefused()` turns either into a `CompatibilityWriteRefused`.

## A concurrent change

A concurrent change is not a refusal: the conflict check raises SQLSTATE 40001, a serialization failure. It fires when another transaction changed the record after the statement's snapshot and before the trigger's locks. That includes a dependent row the record reads, which the trigger locks after the primary and retained rows. The hint tells the caller to retry, or to write through the model, whose bridge locks the record before the statement. `CompatibilityWriteRefused::isRefusal()` does not recognise it.

## A throw in PHP

A throw while the bridge holds an open write closes the write without a commit, then rethrows. That covers a lock or read that fails as the write opens, and a mirrored listener that refuses or throws during the replay. `EventBridge::close()` undoes the write in one of two ways:

- At transaction level 0, it rolls the connection back to the level the write began at.
- In savepoint mode, it issues `ROLLBACK TO SAVEPOINT`, then `RELEASE SAVEPOINT`.

In savepoint mode the replay runs inside `Connection::transaction()`. When that nested transaction rolls back, Laravel drops the `afterCommit` work a mirrored listener queued.

A throw after the beginning event and before the statement starts finds nothing to close. A cast, the encrypter or a later listener can throw there. The bridge opens its transaction or savepoint only when the write's own statement starts, so no transaction and no lock outlives the write. [INV-34](../invariants.md#inv-34-a-pending-mirrored-write-opens-at-its-statement) records the rule.

## A failed commit

A `COMMIT` can fail after a successful replay, for example on a deferred constraint or a lost connection. Laravel then still counts the bridge's transaction. So the bridge keeps the write until its commit finishes. When the commit fails, the private `EventBridge::endTransaction()` rolls the connection back to the level the write began at, forgets the write and rethrows. The connection is then outside a transaction again. [INV-35](../invariants.md#inv-35-a-pending-mirrored-write-leaves-the-stack-only-once-its-close-finishes) records the rule.

## A failed statement at level 0

A failed statement fires no `QueryExecuted`, so the write never completes and stays pending. At level 0, PostgreSQL has aborted the bridge's own transaction. The connection raises `QueryFailed` for the failed statement, and the bridge rolls that transaction back at once, before the `QueryException` reaches the caller.

## The required trait

Laravel raises no event for a failed statement. `Connections\DispatchesQueryFailed` is a trait for the application's connection class that raises one, `QueryFailed`. [Decision 0019](../decisions/0019-a-connection-trait-raises-query-failed.md) records why the package ships a trait, and why the bridge requires it.

The bridge checks the connection's class when it first meets the connection, at its first mirrored write. A class that does not use the trait gets `CompatibilityWriteRefused::byConnection()`, which names the connection, its class and the trait. The bridge has opened nothing, and it refuses every later mirrored write on that connection too. Registering a map resolves no connection, so registration still needs no database.

Without the trait, the bridge would see a failed statement only when the next statement started. Laravel creates the savepoint of `Connection::transaction()` and `createOrFirst()` through `getPdo()->exec()`, which no `beforeExecuting` callback sees, so both would fail with 25P02. A `Connection::afterCommit()` callback registered before the next statement would join the bridge's aborted transaction and be lost with it. [INV-19](../invariants.md#inv-19-a-failed-write-at-level-0-releases-its-transaction) records the rule.

The trait raises the event from `DispatchesQueryFailed::handleQueryException()`, which Laravel calls from `Connection::run()` when a statement throws. Outside a transaction, the parent method first runs the lost-connection retry. The trait catches the `QueryException` the parent method throws, raises `QueryFailed` once, and throws the same exception again:

- A statement that a lost-connection retry recovers throws nothing, so it raises no event.
- A statement that fails inside a `QueryFailed` listener, on the same connection, raises no second event.
- The event carries the SQL, the bindings, the connection and its name, the exception and the read/write type. Its `QueryFailed::$time` is null, because no statement completed.

A connection class that overrides `Connection::run()` or the lost-connection retry, such as Aryeo's ResilientPostgresConnection, still calls `DispatchesQueryFailed::handleQueryException()`. So the trait composes with it, and the event fires after that class's own retry.

## The immediate release

The bridge listens for `QueryFailed` beside `QueryExecuted`, and ignores its own statements. For any other failure it takes the connection's top pending write. It acts only when that write began at level 0 and the failed statement is the write's own, with the same SQL and bindings. It then runs the check the releases below use, through the private `EventBridge::closeFailed()`. The check finds the transaction aborted and rolls it back, so the connection is back at level 0 before the `QueryException` reaches the caller.

A failure of another statement in the write's window leaves the write alone. A `beforeExecuting` callback can run such a statement and catch its failure. A rollback there would let the write's own statement run next at level 0, with nothing mirrored. The aborted transaction fails that statement with 25P02 instead, and its own `QueryFailed` closes the write.

A rollback that fails there, as after a lost connection, never replaces the statement's `QueryException`. The bridge drops the rollback's own exception, and the caller receives the original one.

In savepoint mode the bridge leaves the write alone, so the caller's transaction stays aborted, as [a failed statement inside a caller's transaction](#a-failed-statement-inside-a-callers-transaction) describes.

## A write left pending

Two cases leave a level-0 write pending after its statement started. A statement in the write's window can fail after the write's own statement ran, such as a read in a `QueryExecuted` listener registered before the bridge's. Its failure leaves the bridge's transaction aborted. Or another `beforeExecuting` callback can throw before the write's statement runs. No event reports either case as the write's own. The first of five events closes the write:

1. **The next statement on the connection.** A new statement can find a started write still pending. The bridge's `beforeExecuting` callback then runs `select 1`, through the private `EventBridge::closeFailed()`. A failure means the write's statement failed, so the callback rolls back before the new statement runs. It then opens the armed write, so an outer write whose listener caught the failure still opens on its own statement.
2. **The next bridged write.** Its beginning event runs `EventBridge::finishFailed()`, which makes the same check. It reaps only a write whose statement started: an unstarted write holds no transaction, and may still wait for its statement.
3. **The next transaction the application begins.** Laravel issues `BEGIN` and `SAVEPOINT` through PDO, so no `beforeExecuting` callback sees them. A `beforeStartingTransaction` callback runs `EventBridge::finishFailed()` first. Without it, `Connection::transaction()` right after a caught failure would raise 25P02.
4. **A queue worker's next loop.** `Provider::boot()` calls `EventBridge::releaseFailed()` on each `Looping` event.
5. **Application termination.** `Provider::boot()` calls `EventBridge::releaseFailed()` from the terminating callbacks too.

A statement can start while the write's own statement still runs. Another `beforeExecuting` callback runs one after the write opens, because the bridge's callback runs first. A `QueryExecuted` listener registered before the bridge's runs one after the statement, and before the bridge's own handler. The first three events then find `select 1` healthy, because no statement failed, and leave the write open. The write completes on its own `QueryExecuted`, as [mirroring a write](mirroring-a-write.md#completing) describes. A `QueryExecuted` listener registered before the bridge's that throws leaves the statement's write open and unmirrored. Only the last two events commit it. A write whose statement another callback stopped is healthy too, so only the last two events release it, with a commit.

`EventBridge::releaseFailed()` first forgets every write that holds no transaction, such as one whose statement never started. A completed write has already left the stack. It then closes the pending mirrored write of each connection, and commits a healthy write and rolls back a failed one. `Provider::boot()` skips the release when the application never resolved the bridge.

Until one of the five happens, `Connection::transactionLevel()` still reports 1, a declared limit. The bridge leaves the write alone when the connection's level sits above the bridge's transaction, and forgets it when the level sits below.

## A failed statement inside a caller's transaction

In savepoint mode, the bridge does nothing for a failed statement. The caller's transaction is aborted, as it is after any failed native statement, and the next query raises 25P02 until the caller rolls back. The next statement, or the next `EventBridge::finishFailed()`, drops the write without a rollback. It drops it when `select 1` fails, or when the caller's rollback has taken the level below the write's. A healthy savepoint write stays open, as it does at level 0.

A caller that wrapped the write in `Connection::transaction()` rolls back to its own savepoint, and its outer transaction stays usable.
