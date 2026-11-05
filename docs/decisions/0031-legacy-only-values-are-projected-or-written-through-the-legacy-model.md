# 0031. Legacy-only values are projected, or written through the legacy model

## Context

A retained table keeps its legacy-only columns. Some of them hold a value derived from a successor, such as a media URL whose File changes. When an application changes the successor, the stored value goes stale. The media adoption asked how such a value stays current.

## Decision

The package adds no hook.

- **A value derived from a successor is projected, not stored.** It cannot go stale.
  - Where a successor owns a column for it, the map reads it with `from()` or `orFrom()`. A legacy media URL, the original and its resized variants, and the media facts, such as size, dimensions and Mux ids, are columns the File owns. The map reads them with `from()` on the File target of `relatedMorph()`. They are ordinary mapped attributes: the backfill copies them, the round trip holds, v1 filters keep working, and rollback refills them from the File.
  - Where no column holds it anywhere, the map reads it with `computedAs()` and a read closure that computes it from the successor model, marked `readOnly()`. A ZML URL signed on each read is such a value.
- **A genuine legacy-only value** is one that no successor can supply. The application refreshes it through the legacy model.

A legacy write that changes only legacy-only columns writes the retained row. It runs no operation and saves no successor, so no successor event fires. It fires one legacy event sequence, as any Eloquent update does. Under `saveQuietly()` it fires nothing, as in Eloquent. In the unit of work, steps 3 to 6 do nothing for these columns, the package writes the retained row, and step 7 forwards.

Declined: a refresh hook driven by successor saves. It needs a listener on every related successor class, and it misses mass and raw writes to `files`, such as the completion of the ZML migration.

Declined for media URLs: `computedAs()` values, which change at install and cannot round-trip a stored v1 URL. Also declined: legacy-only retained columns, which are copies the application must refresh.

## Consequences

- A value that a successor supplies is a mapped attribute, not a legacy-only column.
- A legacy write of legacy-only columns alone produces no successor event.
- [The map: legacy-only columns](../specification/the-map.md#legacy-only-columns) and [Operations: legacy-only writes](../specification/operations.md#legacy-only-writes) state the rules.
