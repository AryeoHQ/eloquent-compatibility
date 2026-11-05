# 0018. Legacy identity stays on the legacy table

## Context

`Compatibility::prepare()` added legacy identifier columns to each successor and filled them from the legacy table's sequence. Install then repointed inbound foreign keys through a discriminator and generated constant columns. That design failed three adoption cases:

- Listing's Jobs already corresponded to listings through `listings.job_id`. Prepare allocated fresh identifiers for them, and the backfill then created a second Job for each listing.
- Install refused the existing `job_id` bridge as its retained key, so Aryeo added a second column with the same values.
- Media tables with integer and bigint keys could not share one successor, and the shared sequence could overflow `photos.id`.

## Decision

The retained table, which is the renamed legacy table, keeps its own id, uuid, primary key, sequence and width. It holds the key of its root successor in a successor key column. Listing adopts its existing `job_id`. A media table gains a column such as `place_asset_id`, which the backfill fills.

- The successor key is an identity. `prepare()` gives it a unique constraint only. A key that names no row means "no successor yet": the backfill takes the create path, and creates the root with that key value, so the identity holds. Install's existence proof checks that every non-NULL key names an existing root row, whatever the legacy row's ledger state. Install catches up the keys the proof flags through the create path, which creates the root with the key value, and proves them again.
- Under its lock, after the final pass, the NULL-key probe and the native-root inserts, install adds `CHECK (<key> IS NOT NULL) NOT VALID`. Install's last statement under the lock, after the dependents' afterInstall(), adds the foreign key `ON DELETE CASCADE NOT VALID` to the root. Neither needs a scan, and both check every later insert and update. The foreign key takes `SHARE ROW EXCLUSIVE` on the root's table until commit, so install holds the root's table for that one statement plus the commit. Rollback drops both. Prepare adds neither, because a `NOT VALID` constraint still applies to new rows. Every native legacy insert with a NULL key would fail the check, and every insert whose root does not exist yet would fail the foreign key ([AryeoHQ/aryeo#12364](https://github.com/AryeoHQ/aryeo/issues/12364)). This amends the earlier design, which added the foreign key at prepare.
- After install, a create that supplies the successor key uses it as the root's key, and refuses when the key names an existing root.
- Inbound foreign keys keep pointing at the retained table.
- Successor tables gain no columns.
- Only the root has a successor key. Related successors are reached through the root's relations.
- From install onward, a native successor create inserts its legacy row through a successor trait, inside the same transaction. eloquent-ordering's IsOrdered trait is the precedent. The trait refuses a native create outside a transaction. It takes an `ACCESS SHARE` lock on the legacy table before it checks whether the model is installed, so a native create waits for install's lock and then sees the installed state.
- Before install, the trait does nothing, so a natively created root gets no legacy row then. Before its lock, install records the start time of its read, then only reads the keys of member roots without a legacy row, through the indexed anti-join on the successor key, and reports their count. Under the lock, after the final pass, the rename and the drop of the mapped columns, it inserts one retained row per key, plus one for every member root whose `created_at` is at or after that start time minus the margin. It anti-joins both sets against the retained table's successor key, so a root the final pass just created gets no second row. Both sets exclude a root that a `deleted` ledger row names. It runs the legacy initializer per row, with the Backfill origin. This amends the earlier design, which inserted for every member root created since the read, and so collided with the roots the final pass created. No pass walks these rows, and before install no legacy reader sees a half-filled row. Install's pause grows with the number of such roots.
- The application enforces that one shared successor serves one legacy record, and the drift check reports a violation.

Declined: a successor key that stays nullable after install, and `SET NOT NULL`, which scans the whole table under the lock. Also declined: requiring a backfill, or the creation of every physical Job, before `prepare()`; and keeping the foreign key at prepare as `NO ACTION`, which still rejects the same inserts. Also declined: per-successor identity tables, a shared identity table, the exclusive arc, a surrogate key, and a stored key for each related successor. Also declined: mirroring native successor saves into the legacy row before install, which needs a listener per related class and a two-way sync. Also declined: inserting those rows before the lock with the mapped columns filled from the successors, because the final pass would overwrite a v2 edit made inside the install window.

## Consequences

- Install repoints no foreign key and adds no generated column. Mixed integer widths and the shared-sequence overflow disappear.
- From install onward, a successor delete cascades through the retained row. The legacy schema's own rules then govern every inbound key.
- Before install, a successor delete no longer cascades into the live legacy row, which is still the source of truth. Its key then names no row, and the backfill treats it as having no successor yet. The delete leaves the legacy row's `updated_at` unchanged, so no incremental pass walks the row. Install's catch-up of the keys the existence proof flags is what reaches it.
- Writes to the root's table pause for the foreign key's statement plus the commit. On a shared successor, every legacy model's writes pause for that time only.
- A root hard-deleted after the existence proof and before install adds the foreign key leaves a successor key with no row. The `NOT VALID` foreign key does not catch it, and the view's inner join hides that record. Declined: an anti-join probe under the lock, which is a whole-table scan under the lock.
- A related key such as `listings.place_id` becomes an ordinary mapped attribute, read from the related row. A stored related key would go stale when v2 moves a Job to another Place.
- A successor whose native creates legacy readers must see carries the successor trait.
- Legacy readers do not see a v2-created listing until install.
- The database does not enforce exclusivity across legacy models on a shared successor. Revisit that only when a real need appears.
- [Storage](../specification/storage.md) states the columns and constraints.
