# Write

This namespace turns a legacy write into successor writes, and runs the backfill. [Operations](../../../../../../docs/specification/operations.md) and [Lifecycle](../../../../../../docs/specification/lifecycle.md) state the contracts that its classes implement.

Five classes here are public: `Successors`, `Change`, `Creation`, `Origin` and `Refusal`. An operation or an initializer receives the first four, and throws the fifth. Every other class carries `@internal`.

## How a write arrives

Before install, the legacy name is still a table, and a legacy write runs natively against it. The backfill then copies the change to the successors.

After install, the legacy name is a read-only view. `ServedTable::write()` sends the write here instead of to the database. `LegacyQueryBuilder` hands a write of one record to `RecordWriter`, and a query-builder write over many records to `MassWriter`.

## The unit of work

`UnitOfWork` runs the steps of [the unit of work](../../../../../../docs/specification/operations.md#the-unit-of-work), for one record:

| Step | Method |
| :-- | :-- |
| Scope | `UnitOfWork::transaction()` opens the package's transaction, and `UnitOfWork::scoped()` calls the map's write scope through `ScopedWrite` |
| Lock | `UnitOfWork::roots()` and `UnitOfWork::graphs()` load the successors and lock them, parents first, and `UnitOfWork::refuseMoved()` checks the locked keys against the keys the scope received |
| Assign | `UnitOfWork::assign()` runs the operation of every changed attribute, and `UnitOfWork::initialize()` runs the initializers on a create |
| Save | `UnitOfWork::save()` saves each changed successor once, then applies every `deleteRelated()` |
| Act | `UnitOfWork::act()` runs the `afterSave()` actions in order |

`ScopedWrite` records whether the write scope ran the write it received, and the exception the write threw. The unit of work refuses a scope that never runs the write, and rethrows a failure that the scope catches.

`Staging` holds one record's successors in memory: the root, each related successor that the write loaded or created, the queued deletions and the `afterSave()` actions. `Successors` is the view of `Staging` that an operation receives.

`RoundTrip` runs the Verify step. It reads the record back through the view's compiled `SELECT`, and `RoundTrip::check()` compares each written value with the value served. `RoundTrip::skips()` names the attributes that a comparison skips for an `Origin`.

## The writers

- **`RecordWriter`** writes one legacy record: `RecordWriter::insert()`, `RecordWriter::update()`, `RecordWriter::delete()` and `RecordWriter::increment()`. `RecordWriter::catchUp()` and `RecordWriter::backfill()` write one row of a pass.
- **`MassWriter`** runs a query-builder update or delete. It calls the write scope once, with every record's keys, then runs the unit of work once per record, in chunks.
- **`RetainedRows`** writes the retained table. `RetainedRows::carry()` inserts the retained row when `Provides\CarriesLegacy` sees a native root create. `RetainedRows::carryNative()` inserts install's retained rows for the roots created natively before install.
- **`ExternalAttributes`** resolves `computedAs()` values. It calls each read closure once per batch of loaded records, keeps the values through a write, and refills a `computedAs()` column on rollback.
- **`Applying`** records which legacy record a successor save belongs to. The forwarder reads it, so it raises legacy events for the package's own saves and not for a native save.

## The backfill

`Backfiller` walks the legacy table and writes each row through `RecordWriter`:

- `Backfiller::run()` runs one pass, for `compatibility:backfill` and for install's passes.
- `Backfiller::deletions()` deletes the successor of each legacy row that is gone.
- `Backfiller::catchUp()` writes the keys that install's proofs flag, before install takes its lock.
- `Backfiller::finalPass()` runs install's last pass, under the lock.

`Watermark` reads and writes the model's `compatibility_backfills` row. `Watermark::hold()` takes the session advisory lock for the legacy type, so a second pass refuses. `Ledger` reads and writes `<legacy>_backfill`, and `Ledger::refuseBlocked()` is the install gate. `Pass` counts the outcomes of one pass, and `Outcome` is the status of one row.
