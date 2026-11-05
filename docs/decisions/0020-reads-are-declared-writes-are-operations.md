# 0020. Reads are declared, and writes are operations

## Context

A legacy attribute write could only become one value in one column of one successor row. Several adoption cases did not fit:

- Changing a listing's address replaces or creates a Place, a JobSite and a state row. Aryeo called `Applying::run()` from inside the legacy model to coordinate them.
- Creating a listing needs successor-only values, such as `Job.name`. Aryeo overrode an insert hook that the package's own PHPStan rule forbids.
- A media position is a move through eloquent-ordering, not a column value.
- One legacy `display_type` splits into two successor columns.

The map had two partial escapes, transitions() and the write closure of `external()`. Neither one composed with a read.

## Decision

The map's read half declares each attribute's projection. Every legacy write intent maps to a successor operation:

- An invertible attribute has a default operation that assigns the inverse.
- `writtenBy()` names an operation class for any other write.
- A `readOnly()` attribute's default operation refuses.

`writtenBy()` replaces writeTo(), transitions(), the external write closure, and the consumer's use of `Applying::run()`.

The package runs a write as a unit of work. It calls the write scope first, opens its own transaction inside the scope, locks the record's successors parents first, and runs every changed attribute's operation in declaration order. It saves each changed successor once, applies deletions, and runs the queued after-save actions. It then reads the record back through the view and forwards one legacy event.

The scope comes first on purpose, and this reverses the order decision 5 of the plan names. The write scope's contract requires the scope to run the write inside a transaction it opens. A scope's parent locks then hold until the package's work commits, rather than being released in autocommit before the package locks the record.

After a write, every written attribute must read back its new value. The round-trip check refuses and rolls back a write that fails it.

A create is a write of every supplied attribute against an empty original. Mass writes run the flow once per record. The backfill creates with the Backfill origin, and catch-up runs the operations of changed values only.

`successorsInitializedBy()` sets only successor-only values set once at birth, and values derived from history. `legacyInitializedBy()` covers the reverse: a native successor create. Both are optional.

Declined: a complete column specification, and one adapter class per legacy model.

## Consequences

- Each changed successor produces one `UPDATE` and one each of `saving`, `updating`, `updated` and `saved`. A domain action queued after the save saves again, with its own events.
- An operation's write must agree with its attribute's read, or the write refuses.
- The map refuses to compile a legacy-only NOT NULL column with neither a default nor an initializer. `assertServesCompatibility()` exercises a native create.
- The map's membership decides which native successor creates insert a legacy row.
- A forwarded legacy `creating` listener still may not supply a retained value.
- Conversions stay closed, and serve reads only.
- A position() attribute reads eloquent-ordering's published position expression, and its default operation moves the row with Placement::at(). The package therefore requires eloquent-ordering. Before install, a pass converts each legacy value to its rank among the live rows of its legacy parent.
- [Operations](../specification/operations.md) states the interfaces and the steps.
