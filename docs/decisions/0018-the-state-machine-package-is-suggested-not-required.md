# 0018. The state machine package is suggested, not required

## Context

The event bridge runs a state machine's trigger through `Trigger::withoutTransition()` from `aryeo/eloquent-state-machines`. Most compatibility maps have no state machine. A hard requirement would install the package, and its own dependencies, in every application that uses a map.

## Decision

`composer.json` suggests the package and requires it for development only. `EventReplayer::states()` checks that `Trigger::withoutTransition()` exists before it loads any other class of the package. Without the method it finds no state column, and no trigger runs.

## Consequences

- An application without the package, or with a release that has no `Trigger::withoutTransition()`, mirrors every write as it did before state changes ran a trigger. [INV-31](../invariants.md) holds the rule.
- `EventReplayer` is the only production class that names the package. `composer-dependency-analyser.php` allows the development dependency in that file alone.
- Each test that needs the package carries `#[RequiresMethod(Trigger::class, 'withoutTransition')]` and skips without it. The CI job `tests-without-state-machines` removes the package, then runs the name check and the suite.
- `bin/check-doc-names.php` resolves the package's names in these docs when the package is installed. Without it, the check accepts those names unchecked and says how many it left.
