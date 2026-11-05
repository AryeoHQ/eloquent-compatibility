# 0027. The view is driven from the retained table

## Context

The view read from the primary successor and joined the retained table. It compiled every related successor as `LEFT JOIN LATERAL (... LIMIT 1)`, even where a primary key or a unique index proved one row. PostgreSQL cannot push a filter below that `LIMIT`, so a filter on a related attribute scanned every successor row. The media spike measured 850 ms at 200k rows for a `listing_id` filter.

The backfill also wrote read-only related attributes into rows that belong to another aggregate.

## Decision

The view reads from the retained table, and joins the root on the successor key: `FROM <legacy>_retained JOIN <root> ON <root>.<key> = <legacy>_retained.<successor key>`.

- A related successor is a plain `LEFT JOIN` where the database proves at most one row: a BelongsTo, or a HasOne over a unique index.
- Only a degrade HasOne keeps `LATERAL ... LIMIT 1`.
- `readOnly(because:, ignoreWrites:)` takes a flag with no default, so every declaration states it. The flag applies to an attribute with a stored column and to one without.
- With `ignoreWrites: false`, a `readOnly()` attribute's default operation refuses on an update. On a create, it assigns the inverse, but only into a successor row this create creates, such as a new Job's `company_id`. This amends the earlier design, which said that no path ever writes it. Declined: dropping `readOnly()` for a `writtenBy()` operation that assigns on a create and refuses on an update.
- A `readOnly()` attribute with no stored column, a `computedAs()` value or a `readOnly()` `readAs()` expression, has no inverse. With `ignoreWrites: false`, a create with the Live origin that supplies a non-NULL value for it refuses, as an update does. An update of it refuses. Under the Backfill origin, a pass never writes it: a backfill create and a catch-up row ignore its legacy value, and the `unchanged` test, the drift check and install's value proof skip it. A backfill row's value is history that the successor supersedes. The round trip never compares it, on any origin, so a live create that supplies NULL passes. Declined: refusing always, because the backfill could not proceed. Declined: ignoring always without a trace, because a live caller's value would be lost silently. Declined: comparing, because a value that legitimately differs, such as a re-signed URL, would block install.
- Under the Backfill origin, the same comparisons skip every `computedAs()` attribute, whatever its write declaration: catch-up's equality check, the `unchanged` test, the drift check, the round trip and install's value proof. This extends the rule above to a `computedAs()` attribute that declares `writtenBy()`. A pass still runs that operation on every row it walks, and the operation decides what to do with the value. A media URL's operation writes the v1 URL onto a File outside ZML, does nothing under the Backfill origin for a File in ZML, and refuses under the Live origin for one ([0031](0031-legacy-only-values-are-projected-or-written-through-the-legacy-model.md)). Declined: an operation signal that its value is superseded, which would be new API. Declined: a resolver that depends on the install state.
- With `ignoreWrites: true`, every path accepts and discards a write to the attribute, and never compares it. A create still assigns the inverse into a successor row it creates, where the attribute has an inverse. Each discarded non-NULL write dispatches Events\WriteIgnored, which carries the legacy model, the attribute, the value and the origin.
- A map adopts with `ignoreWrites: true`, and flips to `false` after install, once the WriteIgnored events from the Live origin show no writer. After install the ledger keeps only its deleted rows, so only those events show that the writers are gone. Events from the Backfill origin replay history. The flip changes only the map.
- An attribute with no inverse must declare `writtenBy()` or `readOnly()`, or the map does not compile.
- Declined: a separate `ignoresWrites()` method beside `readOnly()`. Declined: counting writes since `prepare()` to gate install. The legacy column is live storage until install, so that count never reaches zero.
- The view serves an added successor key, such as `place_asset_id`, as it serves `job_id`, so the legacy model's attributes stay the same across install.
- `compatibility:sql --filters` prints, for each attribute, whether a filter runs on a stored column, on a rebuilt expression, or on the view.

Declined: rewriting related filters into semi-joins in the builder.

## Consequences

- PostgreSQL can use a related table's index for a filter through a proven relation.
- The view holds no membership constant: a retained row with a root key is a served record.
- No production install exists yet, so the new shape costs only a rebuild of each view.
- A consumer knows before production whether a filter uses an index.
- [Storage](../specification/storage.md#the-view) states the shape.
