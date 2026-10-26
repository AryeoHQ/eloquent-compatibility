# 0005. A failed statement is released later

## Context

Laravel fires `QueryExecuted` only after a statement succeeds. A failed statement throws a `QueryException` and fires no event, so the bridge does not learn of it when it happens. At transaction level 0, the bridge's own transaction is then open and aborted.

## Decision

The bridge closes that transaction at the first chance it gets:

- the next statement on the connection, through the `Connection::beforeExecuting()` callback that `EventBridge::watch()` adds;
- the next bridged write, through `EventBridge::finishFailed()`;
- a queue worker's next `Looping` event, and application termination, through `EventBridge::releaseFailed()`.

## Consequences

- Until one of these happens, `Connection::transactionLevel()` reports 1. A test names this a declared limit.
- A queued job that fails this way is recorded as failed, and its worker's connection is clean for the next job.
- `EventBridge::finishFailed()` commits instead when `select 1` succeeds, because then no statement failed.
