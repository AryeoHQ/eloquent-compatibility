# 0021. A mapped write at level 0 gives up the lost-connection retry

## Context

The pre-adoption review of PR #1, thread 4382407457, raised a trade-off. At transaction level 0, Laravel reconnects and retries a statement once when the connection drops. `Connection::handleQueryException()` retries only while the connection holds no transaction. An application's connection class can hand non-idempotent writes to that retry too.

The event bridge opens its own transaction when a mapped write's statement starts. The statement, the bridge's locks and its reads then commit or roll back together. The statement then runs at level 1.

## Decision

The bridge keeps its own transaction at level 0. A mapped write gives up Laravel's lost-connection retry. The trade-off is documented in [adopting a map](../adopting-a-map.md), not worked around.

## Consequences

- A mapped write at level 0 fails on a dropped connection, where an unmapped write would reconnect and retry. So do the bridge's own lock and snapshot reads.
- The failed write leaves the bridge's transaction pending. [0005](0005-a-failed-statement-is-released-later.md) releases it, and [INV-19](../invariants.md) records how.
- A retry inside the bridge's transaction could run the statement twice, or run it without the locks the bridge took. Neither is safe, so the package adds no retry of its own.
- Inside a caller's transaction nothing changes: Laravel never retries there.
