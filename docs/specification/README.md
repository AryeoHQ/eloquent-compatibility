Status: Proposed

# Interface specification

This specification states the public interface of the redesigned package, before any of its code exists. Every later change is checked against it. The design comes from the planning session of 2026-11-04, after two Aryeo teams adopted the package for `Listing` and for the media models.

## Status

`Status: Proposed` means that the code does not implement this design yet. `bin/check-doc-names.php` skips every document whose first non-empty line is `Status: Proposed`. These pages can therefore name classes and methods that do not exist yet, such as `Compatibility::rollback()` and `Contracts\WritesAttribute`.

The last implementation change flips every page to `Status: Accepted`. From then on, the checker verifies every name the specification cites.

## Deviations

A change that departs from this specification on purpose updates the specification in the same pull request. Its description says so and names the section it changed. A departure without that update is a defect in the change.

## Pages

| Page | What it states |
| :-- | :-- |
| [Public surface](public-surface.md) | every class, method, command and interface a consumer may use |
| [The map](the-map.md) | how a legacy model declares its reads, its successors and its hooks, and the glossary |
| [Operations](operations.md) | how a write becomes successor operations: the unit of work, the round trip, the initializers, refusals and the write scope |
| [Lifecycle](lifecycle.md) | prepare, the backfill passes, the install gate, install, rollback, retire, `compatibility:status` and the writer boundary |
| [Storage](storage.md) | the retained table and its successor key, the view, `compatibility_backfills` and `<legacy>_backfill` |
| [Listing example](examples/listing.md) | the complete `Listing` map over `Job`, with its operation and hook classes |
| [Floor plan example](examples/floor-plan.md) | the complete `FloorPlan` map over `PlaceAsset`, with its operation and hook classes |

Each page ends with its open questions. An open question names a detail the session did not settle, the options, and one recommendation.

## Decisions

Each settled decision has one record. Records 0009 to 0015 stay in force, and none of them is superseded.

| Record | Decision |
| :-- | :-- |
| [0016](../decisions/0016-the-package-owns-mechanism-consumers-own-policy.md) | the package owns mechanism, and consumers own policy |
| [0017](../decisions/0017-successors-are-written-through-their-models.md) | successors are written through their models |
| [0018](../decisions/0018-legacy-identity-stays-on-the-legacy-table.md) | legacy identity stays on the legacy table |
| [0019](../decisions/0019-legacy-is-the-source-of-truth-until-install.md) | legacy is the source of truth until install |
| [0020](../decisions/0020-reads-are-declared-writes-are-operations.md) | reads are declared, and writes are operations |
| [0021](../decisions/0021-related-successors-are-referenced-by-class.md) | related successors are referenced by class |
| [0022](../decisions/0022-operations-are-classes.md) | operations are classes |
| [0023](../decisions/0023-the-backfill-records-an-outcome-per-row.md) | the backfill records an outcome per row |
| [0024](../decisions/0024-install-is-one-transaction.md) | install is one transaction |
| [0025](../decisions/0025-rollback-and-retire-are-separate-operations.md) | rollback and retire are separate operations |
| [0026](../decisions/0026-a-write-scope-runs-before-package-locks.md) | a write scope runs before package locks |
| [0027](../decisions/0027-the-view-is-driven-from-the-retained-table.md) | the view is driven from the retained table |
| [0028](../decisions/0028-the-public-surface-is-declared.md) | the public surface is declared |
| [0029](../decisions/0029-two-primary-maps-are-retired.md) | two-primary maps are retired |

## Adoption findings

The Listing team reported findings L1 to L9, and the media team reported M1 to M9. Each one maps to the section that answers it.

| Finding | Answered in |
| :-- | :-- |
| L1. Reconciliation cannot keep authoritative successor data | [Lifecycle: the source of truth](lifecycle.md#the-source-of-truth) and [Not in this design](#not-in-this-design) |
| L2. Catch-up misses changes outside the legacy row | [Lifecycle: a pass](lifecycle.md#a-pass), [change sources](lifecycle.md#change-sources) and [deletions](lifecycle.md#deletions) |
| L3. Prepare discards an existing correspondence | [Storage: the successor key](storage.md#the-successor-key) |
| L4. Install does not compose with dependent schema | [Lifecycle: dependent schema](lifecycle.md#dependent-schema) |
| L5. Generic materialization cannot build the successor graph | [Operations: initializers](operations.md#initializers) and [Lifecycle: the backfill](lifecycle.md#the-backfill) |
| L6. Compound operations need a forwarding boundary | [Operations: the unit of work](operations.md#the-unit-of-work) |
| L7. The creation hook breaks the package's own PHPStan rule | [Operations: creates](operations.md#creates) and [initializers](operations.md#initializers) |
| L8. The retained key cannot reuse an existing bridge | [Storage: the successor key](storage.md#the-successor-key) |
| L9. Discovery misclassifies Composer `..` paths | [Public surface: discovery](public-surface.md#discovery) |
| M1. Install needs a successor for every legacy row | [Lifecycle: per-row outcomes](lifecycle.md#per-row-outcomes), [the install gate](lifecycle.md#the-install-gate) and [Not in this design](#not-in-this-design) |
| M2. Package writes bypass successor invariants | [Operations: every write is an operation](operations.md#every-write-is-an-operation) and [the write scope](operations.md#the-write-scope) |
| M3. Conversions are closed and write one column | [Operations: every write is an operation](operations.md#every-write-is-an-operation) |
| M4. Successor-only columns cannot be declared | [Operations: initializers](operations.md#initializers) |
| M5. Dependent views surface only as an install failure | [Lifecycle: dependent schema](lifecycle.md#dependent-schema) |
| M6. No supported install-state check | [Public surface: operations](public-surface.md#operations) |
| M7. Related and expression filters cannot use indexes | [Storage: the view](storage.md#the-view) and [Public surface: commands](public-surface.md#commands) |
| M8. Identifiers of different widths cannot share a successor | [Storage: the retained table](storage.md#the-retained-table) |
| M9. Install's write pause covers every model on a shared successor | [Lifecycle: install](lifecycle.md#install) |

## Not in this design

- **No partial install.** A legacy row without a successor cannot be served. Install refuses until every row of that legacy model has one.
- **No conflict detection and no provenance.** Catch-up overwrites native edits to mapped columns before install. This is documented, not enforced.
- **No set-based successor writes.** Mass writes and the backfill run once per record.
- **No identity table.** Per-successor identity tables, a shared identity table, the exclusive arc and a surrogate key are all declined.
- **No dependency on eloquent-concurrency or eloquent-ordering.** The consumer's write scope sets the lock order.
- **No retries inside the package's writers.** The application's outermost transaction is the retry point. Install keeps its own retries.
- **No public `Compatibility::state()`.** `compatibility:status` and idempotent operations cover its uses.
- **No package-managed rebinding of dependent views,** until a second adopter needs plain rebinds.
- **No declared row scope.** Per-row refusals name the rows a successor cannot represent.
- **No consumer conversions.** The `Conversion` interface stays closed. Reads keep the package's conversions, and writes go through operations.
- **No two-primary maps.** A retained table may still hold two nullable root keys if a real split appears.
- **Out of scope:** the audit findings not named here, `Event::fake()` suppressing forwarding, and guards on insert. Database-enforced exclusivity on shared successors and every change inside Aryeo are out of scope too.

One external dependency remains. eloquent-ordering must publish a routine that locks the parents of a set of models first, usable outside an order write. Aryeo's media write scope needs it.
