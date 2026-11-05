# 0018. Legacy identity stays on the legacy table

## Context

`Compatibility::prepare()` added legacy identifier columns to each successor and filled them from the legacy table's sequence. Install then repointed inbound foreign keys through a discriminator and generated constant columns. That design failed three adoption cases:

- Listing's Jobs already corresponded to listings through `listings.job_id`. Prepare allocated fresh identifiers for them, and the backfill then created a second Job for each listing.
- Install refused the existing `job_id` bridge as its retained key, so Aryeo added a second column with the same values.
- Media tables with integer and bigint keys could not share one successor, and the shared sequence could overflow `photos.id`.

## Decision

The retained table, which is the renamed legacy table, keeps its own id, uuid, primary key, sequence and width. It holds the key of its root successor in a successor key column. Listing adopts its existing `job_id`. A media table gains a column such as `place_asset_id`, which the backfill fills.

- The successor key carries a unique constraint and a foreign key `ON DELETE CASCADE`. Under its lock, after the final pass, the NULL-key probe and the native-root inserts, install adds `CHECK (<key> IS NOT NULL) NOT VALID`. That needs no scan, and it checks every later insert and update. Prepare does not add it, because a `NOT VALID` check still applies to new rows, and every native legacy insert with a NULL key would fail.
- Inbound foreign keys keep pointing at the retained table.
- Successor tables gain no columns.
- Only the root has a successor key. Related successors are reached through the root's relations.
- From install onward, a native successor create inserts its legacy row through a successor trait, inside the same transaction. eloquent-ordering's IsOrdered trait is the precedent. The trait refuses a native create outside a transaction.
- Before install, the trait does nothing, so a natively created root gets no legacy row then. Before its lock, install only reads the keys of member roots without a legacy row, through the indexed anti-join on the successor key, and reports their count. Under the lock, after the final pass, the rename and the drop of the mapped columns, it inserts one retained row per key, plus one for every member root created since that read. It runs the legacy initializer per row. No pass walks these rows, and before install no legacy reader sees a half-filled row. Install's pause grows with the number of such roots.
- The application enforces that one shared successor serves one legacy record, and the drift check reports a violation.

Declined: a successor key that stays nullable after install, and `SET NOT NULL`, which scans the whole table under the lock. Also declined: per-successor identity tables, a shared identity table, the exclusive arc, a surrogate key, and a stored key for each related successor. Also declined: mirroring native successor saves into the legacy row before install, which needs a listener per related class and a two-way sync. Also declined: inserting those rows before the lock with the mapped columns filled from the successors, because the final pass would overwrite a v2 edit made inside the install window.

## Consequences

- Install repoints no foreign key and adds no generated column. Mixed integer widths and the shared-sequence overflow disappear.
- A successor delete cascades through the retained row. The legacy schema's own rules then govern every inbound key.
- A related key such as `listings.place_id` becomes an ordinary mapped attribute, read from the related row. A stored related key would go stale when v2 moves a Job to another Place.
- A successor whose native creates legacy readers must see carries the successor trait.
- Legacy readers do not see a v2-created listing until install.
- The database does not enforce exclusivity across legacy models on a shared successor. Revisit that only when a real need appears.
- [Storage](../specification/storage.md) states the columns and constraints.
