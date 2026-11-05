# 0020. Reads are declared, and writes are operations

## Context

A legacy attribute write could only become one value in one column of one successor row. Several adoption cases did not fit:

- Changing a listing's address replaces or creates a Place, a JobSite and a state row. Aryeo called `Applying::run()` from inside the legacy model to coordinate them.
- Creating a listing needs successor-only values, such as `Job.name`. Aryeo overrode an insert hook that the package's own PHPStan rule forbids.
- A media position is a move through eloquent-ordering, not a column value.
- One legacy `display_type` splits into two successor columns.

The map had two partial escapes, transitions() and the write closure of an external attribute. Neither one composed with a read.

## Decision

The map's read half declares each attribute's projection. Every legacy write intent maps to a successor operation:

- An invertible attribute has a default operation that assigns the inverse.
- `writtenBy()` names an operation class for any other write.
- A `readOnly()` attribute declared with `ignoreWrites: false` refuses an update. On a create, its default operation assigns the inverse into a successor row the create creates. With `ignoreWrites: true`, every path discards a write to it and reports the write ([0027](0027-the-view-is-driven-from-the-retained-table.md)).
- An attribute with no inverse, such as a `readAs()` or `computedAs()` attribute, with neither `writtenBy()` nor `readOnly()` fails map compilation.

`writtenBy()` replaces writeTo(), transitions(), the external write closure, and the consumer's use of `Applying::run()`.

The package runs a write as a unit of work. It opens its own transaction, calls the write scope inside it, locks the record's successors parents first, and runs every changed attribute's operation in declaration order. It saves each changed successor once, applies deletions, and runs the queued after-save actions. It then reads the record back through the view and forwards one legacy event.

The scope runs inside the package's transaction, so its parent locks hold until the package's work commits, and a scope needs no transaction of its own.

After a write, every written attribute must read back its new value. The round-trip check refuses and rolls back a write that fails it.

A create is a write of every supplied attribute against an empty original. The backfill creates with the Backfill origin, and catch-up runs the operations of changed values only.

A mass write makes one unlocked read of every record's successors, then one scope call with all their keys. It then locks and re-checks once, and runs the remaining steps once per record. It forwards no legacy event, and successor events still fire. This amends the earlier design, which forwarded one legacy event sequence per record on the mass path. Declined: per-record legacy events on a mass write, which Eloquent's own mass writes never fire.

`successorsInitializedBy()` sets only successor-only values set once at birth, and values derived from history. `legacyInitializedBy()` covers the reverse: a native successor create. Both are optional.

An operation receives the change context: the record after the write, the original, the attribute and the origin. The original is a legacy model instance, empty on a create. During catch-up, the original is the record as the successors serve it before the pass writes, read through the view's compiled `SELECT`. An initializer receives a creation context instead, Write\Creation, with the record and the origin. On a native create, its record is the new legacy model, so the legacy initializer takes no separate legacy parameter. Declined: the change context with a null attribute and an empty original.

Two projection helpers cover the common reads. `orFrom()` reads a related successor's column, named by class, when the attribute's own column is NULL. `position()` reads eloquent-ordering's published position expression, and its default operation moves the row. It takes no arguments: positions number within the successor's eloquent-ordering parent ([0030](0030-positions-number-within-the-ordering-parent.md)). This amends the earlier design, which named a legacy parent column as its argument. `readAs()` stays public as the escape hatch, for any projection the helpers do not cover. Declined: `position(rankedBy:, within:)`, and `equals()` or CASE helpers until a second caller exists.

A legacy `orderBy('index')` on a `position()` attribute compiles to the successor's `rank_key` order. A `where('index', N)` across parents compiles without a count per candidate row.

When `choose:` picks another target class for a record, the package moves the morph target. It deletes the old target through its model and creates the new one, in one unit of work. Declined: an operation that moves it.

The backfill's round-trip check reads through the view's compiled `SELECT`, filtered to the record's key, because no view exists before install. Declined: skipping the check before install.

The root's soft-delete column and a successor's updated-at column have default operations of their own. A change of the soft-delete column queues `delete()` or `restore()` after the saves. A legacy record delete runs the root's `forceDelete()` as its own step, after the lock and in place of the assignments, the saves, the actions and the round-trip check. An assigned updated-at column survives the after-save actions, because the package runs them under `Model::withoutTimestampsOn()` for that successor. Declined: plain assignment of the soft-delete column.

`delete()` always stamps the current time. After it, a base-query `UPDATE` writes the legacy `deleted_at` and `updated_at` onto the root's row, with no further events. This is the one named exception to the rule that every package path writes a successor through its model ([0017](0017-successors-are-written-through-their-models.md)), because the model's `deleting` and `deleted` events have already fired. Declined: a second model save, which fires extra `saving` and `updated` events per delete, and a round trip that compares only whether `deleted_at` is NULL, which loses backfilled deletion dates.

The floor plan example matches Aryeo's schema. `display_type` and `displays_on_property_websites` are `place_assets` columns. WriteDisplayType therefore writes the PlaceAsset whatever the morph target, and no longer refuses a Link. FloorPlan's membership filters `place_assets.legacy_type`, a column Aryeo adds to hold the media's morph alias ([AryeoHQ/aryeo#12349](https://github.com/AryeoHQ/aryeo/issues/12349)).

The package evaluates `when()` only when it creates a legacy row: at a native successor create, and at install's insert of retained rows for natively created roots. From then on, the successor key binds the row to its root. Membership must therefore be exclusive across the maps that share a root, and stable from creation. For a root that several legacy models share, the specification recommends an immutable discriminator column, such as `place_assets.legacy_type`. `successorsInitializedBy()` sets it for a root the package creates, and a creating listener on the successor sets it for a native create. A change of membership after creation is a consumer reclassification: delete through one legacy model, then create through the other. It is never a package action ([AryeoHQ/aryeo#12360](https://github.com/AryeoHQ/aryeo/issues/12360)). `compatibility:check` reports a served record whose root no longer matches its map's `when()`.

Declined: a complete column specification, and one adapter class per legacy model.

## Consequences

- Each changed successor produces one `UPDATE` and one each of `saving`, `updating`, `updated` and `saved`. A domain action queued after the save saves again, with its own events.
- An operation's write must agree with its attribute's read, or the write refuses.
- The map refuses to compile a legacy-only NOT NULL column with neither a default nor an initializer. `assertServesCompatibility()` exercises a native create.
- The map's membership decides which native successor creates insert a legacy row. It decides nothing after that row exists.
- A forwarded legacy `creating` listener still may not supply a retained value.
- Conversions stay closed, and serve reads only.
- A position() attribute reads eloquent-ordering's published position expression. After install, a write moves the row with Placement::at(). A backfill row or catch-up row is placed relative to its legacy neighbours ([Operations: positions](../specification/operations.md#positions)): after or before its nearest live legacy neighbour whose successor already belongs to the successor's eloquent-ordering parent. The package therefore requires eloquent-ordering.
- A legacy soft delete, restore and record delete fire the successor's `deleting`, `deleted`, `restoring` and `restored` events, so the package writes the successor through its model on the delete path too, apart from the deletion date's `UPDATE`. A backfilled trashed row keeps its original deletion date. A domain action that saves again, such as `publish()` or eloquent-ordering's `move()`, keeps the legacy `updated_at`, and the round trip holds.
- [Operations](../specification/operations.md) states the interfaces and the steps.
