# 0022. A recompile replaces the view and refuses its dependents

## Context

The pre-adoption review of PR #1, thread 4382407493, found that `CREATE OR REPLACE VIEW` cannot drop, retype or reorder a view's columns. `Installer::recompile()` then failed with 42P16 after such a change to a map. The only path left was `Installer::uninstall()` then `Installer::install()`, which drops the view's grants.

Dropping the view has its own cost. `DROP VIEW` without `CASCADE` fails while another object depends on the view. With `CASCADE`, it drops those objects silently.

## Decision

`Installer::recompile()` drops the view and creates it again in its transaction. It saves the view's own grants first and grants them again after. It refuses with `InvalidCompatibilityMap` while another object depends on the view, and names each object. It never uses `CASCADE`.

## Consequences

- A recompile takes any change to the map's attributes, and keeps every grant on the view. A column grant follows only when the new view still has that column.
- A view with no explicit grants saves its owner's default privileges. The recompiling role owns the new view, so a previous owner keeps its access as an explicit grant.
- A migration that recompiles drops each dependent object first and creates it again after. [Adopting a map](../adopting-a-map.md) describes the order.
- A comment on the view, and its ownership, do not survive a recompile. The view's triggers and functions are created again anyway.
- [INV-41](../invariants.md) records the rule.
