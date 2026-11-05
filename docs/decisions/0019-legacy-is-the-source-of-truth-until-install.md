# 0019. Legacy is the source of truth until install

## Context

Aryeo's Job has native writers before install. A catch-up pass overwrote a native edit to a mapped column. It also wrote a required boolean over a Job's nullable permission override, which changed what NULL meant.

Aryeo answered with a three-way reconciliation: per-row baselines of both sides, durable conflicts, and no automatic deletion. The research proposed provenance and both-sides conflict detection in the backfill ledger.

## Decision

The legacy model is the source of truth until install. The successor is the source of truth from install onward.

- Before install, catch-up overwrites mapped successor columns with the legacy values.
- Catch-up skips a write when the successor already serves the legacy value equivalently.
- The legacy record never owns a successor-only column. The creation and derivation hooks own it.
- One deletion rule applies, with no provenance. Catch-up deletes the successor of a deleted legacy row, whatever created it. Soft deletes keep it recoverable. Foreign keys and per-row outcomes refuse loudly when native history depends on it.
- The package detects no conflicts.

Declined: provenance and both-sides conflict detection in the ledger.

## Consequences

- A native edit to a mapped column before install is lost at the next pass. The package documents this and does not enforce it, because Aryeo's v2 writes Jobs live.
- The drift check's value comparison shows what the next pass would overwrite.
- The skip rule keeps a nullable override whose served value already matches.
- [Lifecycle](../specification/lifecycle.md#the-source-of-truth) states the rule.
