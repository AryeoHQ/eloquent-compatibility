# 0027. The view is driven from the retained table

## Context

The view read from the primary successor and joined the retained table. It compiled every related successor as `LEFT JOIN LATERAL (... LIMIT 1)`, even where a primary key or a unique index proved one row. PostgreSQL cannot push a filter below that `LIMIT`, so a filter on a related attribute scanned every successor row. The media spike measured 850 ms at 200k rows for a `listing_id` filter.

The backfill also wrote read-only related attributes into rows that belong to another aggregate.

## Decision

The view reads from the retained table, and joins the root on the successor key: `FROM <legacy>_retained JOIN <root> ON <root>.<key> = <legacy>_retained.<successor key>`.

- A related successor is a plain `LEFT JOIN` where the database proves at most one row: a BelongsTo, or a HasOne over a unique index.
- Only a degrade HasOne keeps `LATERAL ... LIMIT 1`.
- A `readOnly()` attribute's default operation refuses on an update. On a create, it assigns the inverse, but only into a successor row this create creates, such as a new Job's `company_id`. This amends the earlier design, which said that no path ever writes it. Declined: dropping `readOnly()` for a `writtenBy()` operation that assigns on a create and refuses on an update.
- The view serves an added successor key, such as `place_asset_id`, as it serves `job_id`, so the legacy model's attributes stay the same across install.
- `compatibility:sql --filters` prints, for each attribute, whether a filter runs on a stored column, on a rebuilt expression, or on the view.

Declined: rewriting related filters into semi-joins in the builder.

## Consequences

- PostgreSQL can use a related table's index for a filter through a proven relation.
- The view holds no membership constant: a retained row with a root key is a served record.
- No production install exists yet, so the new shape costs only a rebuild of each view.
- A consumer knows before production whether a filter uses an index.
- [Storage](../specification/storage.md#the-view) states the shape.
