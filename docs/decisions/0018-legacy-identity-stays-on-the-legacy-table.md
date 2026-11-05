# 0018. Legacy identity stays on the legacy table

## Context

`Compatibility::prepare()` added legacy identifier columns to each successor and filled them from the legacy table's sequence. Install then repointed inbound foreign keys through a discriminator and generated constant columns. That design failed three adoption cases:

- Listing's Jobs already corresponded to listings through `listings.job_id`. Prepare allocated fresh identifiers for them, and the backfill then created a second Job for each listing.
- Install refused the existing `job_id` bridge as its retained key, so Aryeo added a second column with the same values.
- Media tables with integer and bigint keys could not share one successor, and the shared sequence could overflow `photos.id`.

## Decision

The retained table, which is the renamed legacy table, keeps its own id, uuid, primary key, sequence and width. It holds the key of its root successor in a successor key column. Listing adopts its existing `job_id`. A media table gains a column such as `place_asset_id`, which the backfill fills.

- The successor key carries a unique constraint and a foreign key `ON DELETE CASCADE`.
- Inbound foreign keys keep pointing at the retained table.
- Successor tables gain no columns.
- Only the root has a successor key. Related successors are reached through the root's relations.
- A native successor create inserts its legacy row through a successor trait, inside the same transaction. eloquent-ordering's IsOrdered trait is the precedent.
- The application enforces that one shared successor serves one legacy record, and the drift check reports a violation.

Declined: per-successor identity tables, a shared identity table, the exclusive arc, a surrogate key, and a stored key for each related successor.

## Consequences

- Install repoints no foreign key and adds no generated column. Mixed integer widths and the shared-sequence overflow disappear.
- A successor delete cascades through the retained row. The legacy schema's own rules then govern every inbound key.
- A related key such as `listings.place_id` becomes an ordinary mapped attribute, read from the related row. A stored related key would go stale when v2 moves a Job to another Place.
- A successor whose native creates legacy readers must see carries the successor trait.
- The database does not enforce exclusivity across legacy models on a shared successor. Revisit that only when a real need appears.
- [Storage](../specification/storage.md) states the columns and constraints.
