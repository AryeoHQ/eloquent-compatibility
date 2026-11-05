# 0016. The package owns mechanism, and consumers own policy

## Context

Two Aryeo teams adopted the package at `a1a2bc1`, one for `Listing` and one for the media models. They reported eighteen findings. Many of them had one cause: the package gave the application's policy no supported place.

Aryeo therefore rebuilt parts of the lifecycle around the package. It wrote its own reconciliation, run ledger and cutover, and it moved dependent views by hand. It also called internals, such as `Applying::run()`, Statements::retain(), `Installer::run()` and LegacyBuilder::serves().

Two premises of the original design no longer hold. The design assumed that the package was a successor's only writer before install, but Aryeo's Job is live with its own writers. The design also assumed that successor invariants live in the database. eloquent-concurrency #8 and the eloquent-ordering stack removed every trigger and moved those invariants into model listeners.

## Decision

The package owns mechanism:

- identity and correspondence between legacy rows and successors,
- the storage swap and its readable state,
- the backfill lifecycle,
- routing and event forwarding, and
- write discipline toward successors.

The consumer owns policy, only through hooks the legacy model's map declares. The [public surface](../specification/public-surface.md) lists those hooks.

Successor obliviousness is a preference, not a constraint. A successor may carry legacy or package knowledge when that is simpler or more robust.

Declined: obliviousness as a hard constraint, which would leave a native successor create no way to insert its legacy row.

## Consequences

- Successors may be live, with their own writers, before install. Their invariants live in model listeners, so the package writes through models ([0017](0017-successors-are-written-through-their-models.md)).
- A successor model may use the package's trait for native creates ([0018](0018-legacy-identity-stays-on-the-legacy-table.md)).
- Business rules stay in the application: payment initialization, name seeding, address rules, consent defaults and tenant authorization.
- A consumer that needs an internal call has found a missing hook. The answer is a declared hook, not a public internal.
