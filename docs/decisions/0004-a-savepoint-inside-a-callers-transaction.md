# 0004. A savepoint inside a caller's transaction

## Context

A write through a model and the events the bridge replays for it must commit or roll back together. A mirrored listener that refuses must undo the write. Some callers write inside their own transaction, and some write outside any.

## Decision

At transaction level 0, `EventBridge::begin()` opens a Laravel transaction and commits it after the replay. Inside a caller's transaction, it issues a raw `SAVEPOINT compatibility_<n>`, which Laravel's transaction level does not count. The replay then runs inside `Connection::transaction()`.

## Consequences

- A refused replay undoes only this write. The caller's transaction level stays as the caller set it.
- Work a mirrored listener queues with `afterCommit` rolls back with a failed replay in savepoint mode.
- A failed statement inside a caller's transaction leaves that transaction aborted, as a native failed statement does. The bridge does not roll back to its savepoint.
- At level 0, a failed statement leaves the bridge's own transaction open for a while. See [0005](0005-a-failed-statement-is-released-later.md).
