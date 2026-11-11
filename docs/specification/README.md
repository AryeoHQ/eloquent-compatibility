Status: Accepted

# Interface specification

This specification states the public interface of the redesigned package. It was written before the code, and the shipped code now implements it. Every later change is checked against it. The design comes from the planning session of 2026-11-04, after two Aryeo teams adopted the package for `Listing` and for the media models.

## Status

`Status: Accepted` means that the shipped code implements this design, except for the positions below. `bin/check-doc-names.php` verifies each class name and each class member name in an inline code span outside a fenced block. A span that names a removed name, an illustrative Aryeo class, or a class of a package this one does not require yet sits on the checker's unresolved allowlist, with its reason. The checker fails when an allowlisted name starts to resolve, and when no page names it any more.

Positions land in a later milestone, M5b. They need eloquent-ordering to publish `Placement::at()`, its position expression and its parent key columns (https://github.com/AryeoHQ/eloquent-ordering/issues/14). Until then, the package ships no `position()` and does not require eloquent-ordering, so a map cannot declare an ordered attribute. Aryeo's FloorPlan `index` therefore cannot install until M5b. The pages keep the positions design: [the map](the-map.md#reads-are-projections), [Operations: positions](operations.md#positions), [Lifecycle: positions](lifecycle.md#positions) and the floor plan example's `index`.

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

A page with unsettled details ends with its open questions. An open question names a detail the session did not settle, the options, and one recommendation.

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
| [0030](../decisions/0030-positions-number-within-the-ordering-parent.md) | positions number within the ordering parent |
| [0031](../decisions/0031-legacy-only-values-are-projected-or-written-through-the-legacy-model.md) | legacy-only values are projected, or written through the legacy model |

## Adoption findings

The Listing team reported findings L1 to L9, and the media team reported M1 to M9. Each one maps to the section that answers it.

| Finding | Answered in |
| :-- | :-- |
| L1. Reconciliation cannot keep authoritative successor data | [Lifecycle: the source of truth](lifecycle.md#the-source-of-truth) and [Not in this design](#not-in-this-design) |
| L2. Catch-up misses changes outside the legacy row | [Lifecycle: a pass](lifecycle.md#a-pass), [changes outside the legacy row](lifecycle.md#changes-outside-the-legacy-row) and [deletions](lifecycle.md#deletions) |
| L3. Prepare discards an existing correspondence | [Storage: the successor key](storage.md#the-successor-key) |
| L4. Install does not compose with dependent schema | [Lifecycle: dependent schema](lifecycle.md#dependent-schema) |
| L5. Generic materialization cannot build the successor graph | [Operations: initializers](operations.md#initializers) and [Lifecycle: the backfill](lifecycle.md#the-backfill) |
| L6. Compound operations need a forwarding boundary | [Operations: the unit of work](operations.md#the-unit-of-work) |
| L7. The creation hook breaks the package's own PHPStan rule | [Operations: creates](operations.md#creates) and [initializers](operations.md#initializers) |
| L8. Retained storage requires a redundant identity column and internal staging integration | The identity column: [Storage: the successor key](storage.md#the-successor-key). The staging: [Lifecycle: install](lifecycle.md#install) and [0024](../decisions/0024-install-is-one-transaction.md) |
| L9. Discovery misclassifies Composer `..` paths | [Public surface: discovery](public-surface.md#discovery) |
| M1. Install needs a successor for every legacy row | [Lifecycle: per-row outcomes](lifecycle.md#per-row-outcomes), [the install gate](lifecycle.md#the-install-gate) and [Not in this design](#not-in-this-design) |
| M2. Package writes bypass successor invariants | [Operations: every write is an operation](operations.md#every-write-is-an-operation) and [the write scope](operations.md#the-write-scope) |
| M3. Conversions are closed and write one column | [Operations: every write is an operation](operations.md#every-write-is-an-operation) |
| M4. Successor-only columns cannot be declared, and lifecycle varies by write path | The successor-only columns: [Operations: initializers](operations.md#initializers). The write paths: [Operations: every write is an operation](operations.md#every-write-is-an-operation) and [0017](../decisions/0017-successors-are-written-through-their-models.md) |
| M5. Dependent views surface only as an install failure | [Lifecycle: dependent schema](lifecycle.md#dependent-schema) |
| M6. No supported install-state check | [Public surface: operations](public-surface.md#operations) |
| M7. Related and expression filters cannot use indexes | [Storage: the view](storage.md#the-view) and [Public surface: commands](public-surface.md#commands) |
| M8. Identifiers of different widths cannot share a successor | [Storage: the retained table](storage.md#the-retained-table) |
| M9. Install's write pause covers every model on a shared successor | [Lifecycle: install](lifecycle.md#install). Writes to a shared successor through the other legacy models pause only for install's last statement, which adds the successor key's foreign key, plus its lock wait and the commit. That lock waits for every open writer of the successor's table, and install retries on a lock timeout or a deadlock ([Storage: the successor key](storage.md#the-successor-key)). A native create of a member root waits for the whole hold, because `Provides\CarriesLegacy` locks each legacy table whose `when()` the root matches |

## Not in this design

- **No partial install.** A legacy row without a successor cannot be served. Install refuses until every row of that legacy model has one.
- **No conflict detection and no provenance.** Catch-up overwrites native edits to mapped columns before install. This is documented, not enforced.
- **No set-based successor writes.** Mass writes and the backfill run once per record.
- **No identity table.** Per-successor identity tables, a shared identity table, the exclusive arc and a surrogate key are all declined.
- **No dependency on eloquent-concurrency.** From M5b, the package requires eloquent-ordering for `position()` only. The consumer's write scope sets the lock order.
- **No retries inside the package's writers.** The application's outermost transaction is the retry point. Install keeps its own retries.
- **No public `Compatibility::state()`.** `compatibility:status` and idempotent operations cover its uses.
- **No package-managed rebinding of dependent views,** until a second adopter needs plain rebinds.
- **No declared row scope.** Per-row refusals name the rows a successor cannot represent.
- **No consumer conversions.** The `Conversion` interface stays closed. Reads keep the package's conversions, and writes go through operations.
- **No two-primary maps.** A retained table may still hold two nullable root keys if a real split appears.
- **Out of scope:** the audit findings not named here, `Event::fake()` suppressing forwarding, and guards on insert. Database-enforced exclusivity on shared successors and every change inside Aryeo are out of scope too.

Four external dependencies on eloquent-ordering remain:

- It publishes its position expression as public API, which `position()` reads.
- It adds `Placement::at(int $position)`, which the default operation of `position()` uses.
- It publishes an ordered item's parent key columns, which the relative placement and install's order proof partition by. This is Requirement 3 of https://github.com/AryeoHQ/eloquent-ordering/issues/14.
- It publishes a routine that locks the parents of a set of models first, usable outside an order write. Aryeo's media write scope needs it.
