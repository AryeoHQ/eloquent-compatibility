# 0026. A write scope runs before package locks

## Context

The package locked a successor row before the successor's listeners locked its ordering parent. eloquent-ordering's writers lock the parent first, so the two orders deadlocked with `40P01`.

Aryeo's Job also requires every writer to run inside an application lock scope. Aryeo wrapped the legacy model's `save()` and `delete()` to open that scope, and mass writes and the backfill ran outside it.

The research proposed that the package depend on eloquent-concurrency's parent-lock contract, and lock the parents through a routine a sibling publishes.

## Decision

`writesWithin()` names a class whose `run()` receives the touched successor keys by class, and the write to run. The package calls it before it takes any lock of its own. That holds on every path: a record write, a mass write, a backfill row, catch-up, and the refill inside rollback.

- The scope adds to the package's own row locks, and never replaces them. The package's `FOR UPDATE` on the root and the touched related rows always comes inside the scope, last.
- Without a scope, the package locks as it does today.
- Every path resolves the touched successors with an unlocked read, calls the scope with their keys, and locks inside it. Right after its locks, it checks that the locked keys equal the keys the scope received. A difference refuses with a retryable error.
- The package retries nothing inside its writers, on any path. The application's outermost transaction is the retry point. Install keeps its own retries.
- This amends decision 9 of the plan, which gave the mass write alone a re-check and a retry of its own. A related key can move between the unlocked read and the lock on any path. The package would then lock a row the scope never ordered.
- The package targets eloquent-concurrency 0.1.0 and the eloquent-ordering release after #13, never the 0.0.x triggers.

Declined: a dependency on eloquent-concurrency or eloquent-ordering.

## Consequences

- Listing's scope runs Aryeo's Job write transaction. Media's scope locks ordering parents first, through Aryeo's own use of eloquent-ordering.
- A map over ordered successors without a scope can still deadlock. The package documents that risk and does not detect it.
- eloquent-ordering must publish a routine that locks the parents of a set of models first, usable outside an order write. Aryeo's media scope needs it.
- [Operations](../specification/operations.md#the-write-scope) states the contract.
