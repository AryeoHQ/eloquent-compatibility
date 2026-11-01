# 0021. A mapped write at level 0 gives up the lost-connection retry

## Context

The pre-adoption review of PR #1, thread 4382407457, raised a trade-off. At transaction level 0, Laravel reconnects and retries a statement once when the connection drops. `Connection::handleQueryException()` retries only while the connection holds no transaction. An application's connection class can hand non-idempotent writes to that retry too.

The write router runs each routed write inside `Connection::transaction()`. Its locks, its reads and every statement of the split write then commit or roll back together. Those statements run at level 1.

## Decision

The router keeps its own transaction at level 0. A mapped write gives up Laravel's lost-connection retry. The trade-off is documented in [adopting a map](../adopting-a-map.md), not worked around.

## Consequences

- A mapped write at level 0 fails on a dropped connection, where an unmapped write would reconnect and retry.
- Laravel's own transaction handling rolls the failed write back, so nothing is left pending.
- A retry inside the router's transaction could run a statement twice, or run it without the locks the router took. Neither is safe, so the package adds no retry of its own.
- Inside a caller's transaction nothing changes: Laravel never retries there.
