# 0028. The public surface is declared

## Context

No class carried `@internal`, so every public symbol was in effect API. Aryeo called `Applying::run()`, called `LegacyBuilder::serves()` at 13 call sites, called `Statements::retain()` and `Installer::run()`, and matched a private catalog comment. The package's PHPStan rule forbade the one creation seam that worked.

Two smaller gaps sat beside it. Discovery missed application classes under an optimized Composer classmap, so Aryeo rewrote its classmap before discovery ran. Install refused a caller's transaction whenever it had a catch-up to run, so no test that refreshes its database in a transaction could install.

## Decision

Everything is internal except:

- the fluent map,
- the `Compatibility` operations,
- the commands,
- the testing trait, and
- the operation and hook interfaces, with their arguments: the unit of work, the change context, the origin and the refusal.

The documentation lists exactly that surface. `serves()` on `Compatibility` is documented API, for transitional application code that bypasses the legacy model. Its answer can change around an install. It is stable only inside a transaction, and is never cached across requests.

- Discovery lands commit `6ec7755`, which classifies a classmap entry by its real path, with a test over an optimized classmap.
- `install()` may run inside a caller's transaction when no catch-up is pending.
- The redesign ships as a new 0.x minor release, with no data migration, because no production install exists.
- The [interface specification](../specification/README.md) ships first. Every later change is checked against it, and a deliberate deviation updates it in the same pull request.

Declined: a public `state()` reader, because `compatibility:status` and idempotent operations cover its uses.

## Consequences

- Every class outside the surface carries `@internal`, and any release may change it.
- Aryeo deletes its classmap rewrite, and its tests can install a served model.
- Aryeo reworks its drafts against the new surface.
- [Public surface](../specification/public-surface.md) lists every class and method.
