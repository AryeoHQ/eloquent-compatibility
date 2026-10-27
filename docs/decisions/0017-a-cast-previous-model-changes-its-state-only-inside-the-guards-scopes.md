# 0017. Retired: a cast previous model changes its state only inside the guard's scopes

## Status

Retired on 2026-10-27. The limit this decision declared no longer exists.

## Context

A previous model can cast its mapped state column to the same state machine as the current model. The first design of the transition replay depended on eloquent-state-machines PR #49, which added a state guard. That guard refused the previous model's own write to the column before the event bridge saw it. The user chose on 2026-10-25 to declare that limit, rather than let the bridge exempt the column from the guard.

PR #49 closed without merging. Its replacement, eloquent-state-machines PR #52, adds `Trigger::withoutTransition()` and no state guard.

## Consequences

- A previous model that casts its mapped column writes it as any previous model does, and the bridge runs the trigger. `EventBridgeTransitionTest::it_runs_the_trigger_of_a_state_change_through_a_previous_model_that_casts_the_mapped_state_column_to_the_state_machine()` pins it. `EventBridgeTransitionTest::it_mirrors_a_native_transition_to_a_previous_model_that_casts_the_mapped_state_column_to_the_state_machine()` pins the mirrored events such a model receives.
- [INV-28](../invariants.md) is retired with this decision. Both numbers stay, so later invariants and decisions keep theirs.
