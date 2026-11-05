# 0031. Legacy-only values are projected, or written through the legacy model

## Context

A retained table keeps its legacy-only columns. Some of them hold a value derived from a successor, such as a media URL whose File changes. When an application changes the successor, the stored value goes stale. The media adoption asked how such a value stays current.

## Decision

The package adds no hook.

- **A value that a successor column holds, or that a read computes each time, is projected, not stored.** It cannot go stale.
  - Where a successor owns a column for it, the map reads it with `from()` or `orFrom()`. A legacy media URL, the original and its resized variants, and the media facts, such as size, dimensions and Mux ids, are columns the File owns. The map reads them with `from()` on the File target of `relatedMorph()`. They are ordinary mapped attributes: the backfill copies them, the round trip holds, v1 filters keep working, and rollback refills them from the File.
  - A File that v2 creates gets its stable URL from Aryeo. Aryeo's ingest path writes the stable URL onto the File at creation. Alternatively, the map reads a stable redirect URL over the File with `readAs()`. The choice is Aryeo's, and either way the package needs no change.
  - Where no column holds it anywhere, the map reads it with `computedAs()` and a read closure that computes it from the successor model, marked `readOnly()`. A ZML URL signed on each read is such a value.
- **A genuine legacy-only value** is one that no successor column holds and no read computes. A value that `legacyInitializedBy()` derives once from the successor graph is one: `listings.title`, copied from `Job.name` at birth, and `photos.listing_id`, derived through Place, Job and `listings.job_id`. The application refreshes it through the legacy model.

A legacy write that changes only legacy-only columns writes the retained row. It runs no operation and saves no successor, so no successor event fires. It fires one legacy event sequence, as any Eloquent update does. Under `saveQuietly()` it fires nothing, as in Eloquent. In the unit of work, steps 3 to 6 do nothing for these columns, the package writes the retained row, and step 7 forwards.

Declined: a refresh hook driven by successor saves. It needs a listener on every related successor class, and it misses mass and raw writes to `files`, such as the completion of the ZML migration.

Declined for media URLs: `computedAs()` values, which change at install and cannot round-trip a stored v1 URL. Also declined: legacy-only retained columns, which are copies the application must refresh.

## Consequences

- A value that a successor column holds, or that a read computes, is a mapped attribute, not a legacy-only column. A value that the legacy initializer derives once stays a legacy-only column.
- Creating and linking related records is the consumer's work, as a set-based, idempotent backfill keyed on the related table, such as `orders.job_id` set from the listing. The consumer re-runs it after the package's passes create the roots, and again before install, and gates its install migration on its own readiness check. `successorsInitializedBy()` keeps only the per-root values a successor needs at birth. Declined: related-record work in `successorsInitializedBy()`, which runs once at a root's birth, misses existing roots and later related rows, and writes set-sized work row by row under the package's locks ([Lifecycle: changes outside the legacy row](../specification/lifecycle.md#changes-outside-the-legacy-row)).
- A legacy write of legacy-only columns alone produces no successor event.
- [The map: legacy-only columns](../specification/the-map.md#legacy-only-columns) and [Operations: legacy-only writes](../specification/operations.md#legacy-only-writes) state the rules.
