# 0005. A failed statement is released later

## Context

Laravel fires `QueryExecuted` only after a statement succeeds. A failed statement throws a `QueryException` and fires no event, so the bridge does not learn of it when it happens. At transaction level 0, the bridge's own transaction is then open and aborted.

## Decision

The bridge closes that transaction at the first chance it gets:

- the next statement on the connection, through the `Connection::beforeExecuting()` callback that `EventBridge::watch()` adds;
- the next bridged write, through `EventBridge::finishFailed()`;
- the next transaction the application begins, through the `Connection::beforeStartingTransaction()` callback that `EventBridge::watch()` adds;
- a queue worker's next `Looping` event, and application termination, through `EventBridge::releaseFailed()`.

Each of the first three runs `select 1` before it closes the write, and closes it only when that fails.

## Consequences

- Until one of these happens, `Connection::transactionLevel()` reports 1. That is a declared limit.
- A queued job that fails this way is recorded as failed, and its worker's connection is clean for the next job.
- A statement can start while the write's own statement runs, from a `QueryExecuted` listener registered before the bridge's. Its `select 1` succeeds, so the bridge leaves the write open, and the write completes on its own `QueryExecuted`. Every other `beforeExecuting` callback runs before the write opens, as [decision 0019](0019-a-connection-trait-raises-query-failed.md) records.
- Only `EventBridge::releaseFailed()` commits a healthy write. A `QueryExecuted` listener registered before the bridge's that throws therefore leaves its statement's write open, unmirrored, until `Looping` or termination.
- Laravel begins a transaction or a savepoint through PDO, outside `Connection::beforeExecuting()`. Without the `beforeStartingTransaction` callback, `Connection::transaction()` right after a caught failure raised 25P02.
- `EventBridge::finishFailed()` reaps only a write whose statement started. A write whose statement has not started holds no transaction. It may still be waiting for its statement, because a listener on its beginning event can write another mapped model first. [INV-34](../invariants.md) records the rule.
- In savepoint mode, the next statement forgets a write whose statement failed, without a rollback, so the caller's transaction stays aborted. It also forgets a write whose savepoint the caller rolled back, because the level then sits below the write's.
- [Decision 0019](0019-a-connection-trait-raises-query-failed.md) supersedes these releases for a write's own failed statement. The bridge requires `DispatchesQueryFailed` on every connection it mirrors a write on. The trait calls the bridge as soon as that statement fails, before it raises `QueryFailed`, and the bridge releases the transaction then.
- When that rollback fails and Laravel still counts the transaction, the bridge keeps the write. Each later statement, bridged write or transaction then tries the rollback once, without `select 1`. `Looping` and termination roll the write back too, and never commit it.
- The releases above still close a level-0 write whose transaction another statement in its window aborted. A `QueryExecuted` listener registered before the bridge's can run that statement after the write's own. No event reports it as the write's, so the declared limit now names that case.
- A write whose statement another `beforeExecuting` callback stopped holds no transaction, because the bridge opens the write after every other callback.
