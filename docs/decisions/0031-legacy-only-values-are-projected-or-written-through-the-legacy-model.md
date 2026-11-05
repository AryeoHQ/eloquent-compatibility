# 0031. Legacy-only values are projected, or written through the legacy model

## Context

A retained table keeps its legacy-only columns. Some of them hold a value derived from a successor, such as a media URL whose File changes. When an application changes the successor, the stored value goes stale. The media adoption asked how such a value stays current.

## Decision

The package adds no hook.

- **A value that a successor column holds, or that a read computes each time, is projected, not stored.** It cannot go stale.
  - Where a successor owns a column for it, the map reads it with `from()` or `orFrom()`.
  - Where no column holds it anywhere, the map reads it with `computedAs()` and a read closure that computes it from the successor model, marked `readOnly()`. A ZML URL signed on each read is such a value.
- **Media values separate by kind.**
  - Intrinsic metadata, such as width, height, size, format, duration and orientation, and third-party ids, such as Mux ids, are File columns. The map reads them with `from()` on the File target of `relatedMorph()`. Every creation path writes them once: the backfill copies v1's values, a v1 create writes v1's values, and the consumer's ZML ingest writes ZML's metadata. The ZML migration does not change them. They are ordinary mapped attributes: the round trip holds, v1 filters keep working, and rollback refills them from the File.
  - Delivery values, the original URL and the variant URLs, resolve by the File's storage state. The map reads each one with `computedAs()` through the consumer's File resolver, which prefers ZML when `zml_asset_id` is set. A `writtenBy()` operation writes a v1 legacy URL onto a File that is not in ZML. For a File in ZML, it does nothing under the Backfill origin and refuses under the Live origin. Under the Backfill origin, the comparisons skip a `computedAs()` attribute, and a pass runs its operation on every row it walks ([0027](0027-the-view-is-driven-from-the-retained-table.md)). The pass skips a row whose chosen target does not declare the attribute, and the operation must change nothing when it runs again with an unchanged value. A v1 URL edit made before install therefore reaches a non-ZML File when a pass walks the row. An edit that leaves `updated_at` alone is a documented gap: install's value proof skips the attribute, so the edit is lost unless a pass walks the row for another reason. An asset the ZML migration moved never refuses. Under the Live origin, the round trip calls the read closure with the reloaded File, because a computed value is not in the view. The read closure keeps the shipped batch shape: a collection of successors in, values keyed by successor key out.
  - The alternative for a delivery value is a stable redirect URL that the consumer controls, read with `readAs()`. That value can be filtered in SQL.
  - This amends the earlier design, which stored the media URLs as File columns read with `from()`, and declined `computedAs()` for them.
- **A genuine legacy-only value** is one that no successor column holds and no read computes. A value that `legacyInitializedBy()` derives once from the successor graph is one: `listings.title`, copied from `Job.name` at birth, and `photos.listing_id`, derived through Place, Job and `listings.job_id`. The application refreshes it through the legacy model.

A legacy write whose changed columns are all legacy-only, apart from the `updated_at` that Eloquent touches, writes the retained row. It drops that touch and writes nothing for `updated_at`, because a mapped `updated_at` would otherwise save the root and fire its events. It runs no operation and saves no successor, so no successor event fires. It fires one legacy event sequence, as any Eloquent update does. Under `saveQuietly()` it fires nothing, as in Eloquent. In the unit of work, steps 3 to 6 do nothing for these columns, the package writes the retained row, and step 7 forwards.

Declined: a refresh hook driven by successor saves. It needs a listener on every related successor class, and it misses mass and raw writes to `files`, such as the completion of the ZML migration.

Declined for media values: reading metadata live from an outside store on each read, which costs one call per asset, loses SQL filters, and fetches a value that does not change. Also declined: choosing the source by the legacy record, and legacy-only retained columns, which are copies the application must refresh.

## Consequences

- A value that a successor column holds, or that a read computes, is a mapped attribute, not a legacy-only column. A value that the legacy initializer derives once stays a legacy-only column.
- Creating and linking related records is the consumer's work, as a set-based, idempotent backfill keyed on the related table, such as `orders.job_id` set from the listing. The consumer re-runs it after the package's passes create the roots, and again before install, and gates its install migration on its own readiness check. `successorsInitializedBy()` keeps only the per-root values a successor needs at birth. Declined: related-record work in `successorsInitializedBy()`, which runs once at a root's birth, misses existing roots and later related rows, and writes set-sized work row by row under the package's locks ([Lifecycle: changes outside the legacy row](../specification/lifecycle.md#changes-outside-the-legacy-row)).
- A legacy write of legacy-only columns alone produces no successor event, even through `update()` or `save()`, because the package drops Eloquent's `updated_at` touch.
- [The map: legacy-only columns](../specification/the-map.md#legacy-only-columns) and [Operations: legacy-only writes](../specification/operations.md#legacy-only-writes) state the rules.
