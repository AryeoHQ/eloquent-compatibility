Status: Proposed

# Operations

Every write to a served legacy model becomes successor operations. The package runs them as one unit of work, and saves each changed successor once through its model. It then checks that every written attribute reads back ([0017](../decisions/0017-successors-are-written-through-their-models.md), [0020](../decisions/0020-reads-are-declared-writes-are-operations.md)).

## Every write is an operation

Each attribute has exactly one operation:

- **`writtenBy(Operation::class)`.** The class's `write()` runs. It implements `Contracts\WritesAttribute`.
- **A default operation that assigns the inverse.** An attribute whose read the package can invert, such as `from()` with invertible conversions, assigns the inverse to its successor column.
- **`readOnly(because:)`.** The default operation refuses. No path writes the attribute.

`writtenBy()` replaces `writeTo()`, `transitions()`, the write closure of `external()`, and an application's own use of `Applying::run()`. An operation can write several successor columns, write related successors, or run a domain action.

Every hook is a class string. The package resolves each operation and hook class from the container, so a constructor can take dependencies. The plan's work item "Turn legacy writes into successor operations" settles this.

```php
final class SetDownloadsPermission implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $successors->root()->are_customer_downloads_enabled = (bool) $value;
    }
}

final class PublishOrDraftJob implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $successors->afterSave(fn () => $value ? $successors->root()->draft() : $successors->root()->publish());
    }
}
```

An operation makes in-memory assignments only. The package saves. A domain action that saves on its own goes in `afterSave()`.

## Successors

`Write\Successors` is the unit of work an operation receives. It is generic over the root class, so `Successors<Job>::root()` returns a `Job`. Related successors are addressed by model class, and each method is generic: `class-string<T>` in, `T` out.

| Method | Returns or does |
| :-- | :-- |
| `root()` | the root successor, loaded and locked; on a create, the new root |
| `related(class-string<T> $class)` | the related successor of that class, loaded and locked |
| `relatedOrNew(class-string<T> $class)` | the related successor, or a new instance the package saves with the others |
| `deleteRelated(class-string<Model> $class)` | deletes the related successor through its model, after the saves |
| `afterSave(Closure $action)` | queues an action that runs after the saves, in the order queued |

A companion, such as Listing's compatibility state row, is a declared relation. An operation reaches it with `relatedOrNew()`. The package has no `add()` for undeclared rows.

## The change context

`Write\Change` carries the context of one write.

| Property | Holds |
| :-- | :-- |
| `record` | the legacy record after the write |
| `original` | the legacy record before the write |
| `attribute` | the name of the attribute the operation writes |
| `origin` | `Origin::Live` or `Origin::Backfill` |

A write through the legacy model runs with `Origin::Live`. Every backfill pass runs with `Origin::Backfill`, including the final pass inside install.

## The unit of work

One legacy write runs these steps, in this order:

1. **Scope.** The package resolves the successors the write touches with an unlocked read. It calls the write scope with their keys, and takes no lock before this step.
2. **Lock.** Inside the scope, the package opens its transaction and loads the record's successors. It locks the root and the touched related rows `FOR UPDATE`, parents first. It then checks that the keys it locked equal the keys the scope received. On a difference, it refuses the write with a retryable error and rolls the unit of work back.
3. **Assign.** The package runs the operation of every changed attribute, in declaration order. Operations assign in memory and queue `afterSave()` actions.
4. **Save.** The package saves each changed successor model once. It then applies every `deleteRelated()`.
5. **Act.** The package runs the `afterSave()` actions in order. A domain action such as `publish()` or `moveTo()` saves on its own, with its own events.
6. **Verify.** The package reads the record back through the view and runs the round-trip check.
7. **Forward.** The package forwards one legacy event.

`$listing->update(['is_draft' => false, 'downloads_enabled' => true, 'address_id' => 9])` therefore locks the Job, the Place, the JobSite and the state row. It runs `PublishOrDraftJob`, `SetDownloadsPermission` and `ChangeListingAddress`, and saves each changed row once. `publish()` then saves the Job again, as v2's own publish does.

### Events

| Successor | Statements | Successor events |
| :-- | :-- | :-- |
| changed by assignments | one `UPDATE` | one `saving`, `updating`, `updated` and `saved` |
| created by `relatedOrNew()` | one `INSERT` | one `saving`, `creating`, `created` and `saved` |
| removed by `deleteRelated()` | its model's `delete()` | the events its model's `delete()` fires |
| saved by an `afterSave()` action | what the action runs | the action's own events |
| unchanged | none | none |

The legacy model's listeners receive one event sequence for the write, such as one `updating` and one `updated` for an update. The package forwards none of the successor events above as legacy events.

A mass write runs the unit of work once per record, so it forwards one legacy event sequence per record. Eloquent's own mass writes fire no model events, and a served legacy model differs from them here. Decision 5 settles this.

## The round trip

After a write, every written attribute must read back its new value through the view. The round-trip check compares each written attribute's value with what the view now serves. When one differs, the check refuses the write and rolls the unit of work back.

The round trip binds an operation to its attribute's read. `address_id` reads `"addresses"."id"` through the Place. `ChangeListingAddress` must therefore leave the Place pointing at the Address whose `id` is the written value.

## Creates

A create is a write of every supplied attribute against an empty original. The attribute operations run first, in declaration order. `successorsInitializedBy()` then runs once on the new root.

A create's default operation for a related row's key assigns that key to the new related row. A Listing created with a reserved `place_id` therefore creates its Place with that id. Decision 10 states this rule.

A create through the legacy model runs with `Origin::Live`. The backfill creates with `Origin::Backfill`.

## Mass writes, the backfill and catch-up

- **A mass write** runs the unit of work once per record. Each record's lock step checks its locked keys, as every path does. A mass write has no retry of its own.
- **The backfill** creates each missing successor graph through the attribute operations and `successorsInitializedBy()`, with `Origin::Backfill`.
- **Catch-up** runs the attribute operations for changed values. It skips an attribute whose value the view already serves equivalently.

## Initializers

### `successorsInitializedBy()`

The class implements `Contracts\InitializesSuccessors`. It sets only two kinds of value: successor-only values set once at birth, and values derived from history. Examples are `Job.name`, `opted_in_to_zma`, and `payment_status` derived from orders under `Origin::Backfill`. It is optional.

```php
final class InitializeListingJob implements InitializesSuccessors
{
    public function initialize(Successors $successors, Change $change): void
    {
        $listing = $change->record;
        $job = $successors->root();
        $job->name = $listing->title ?: 'Legacy listing '.$listing->uuid;
        $job->opted_in_to_zma = false;
        $job->payment_status = $change->origin === Origin::Backfill
            ? PaymentStatus::fromOrders($listing->orders)
            : PaymentStatus::Unpaid;
        $successors->relatedOrNew(ListingCompatibilityState::class)->fill([
            'downloads_enabled_fallback' => $listing->downloads_enabled ?? true,
        ]);
    }
}
```

The legacy record never owns a successor-only column. The initializer and the successor's own code own it.

### `legacyInitializedBy()`

The reverse direction. An application creates a successor natively, and the successor carries `Provides\CarriesLegacy`. Inside the successor's transaction, the package inserts the legacy row:

1. It inserts a legacy row only when the new successor is inside the map's membership.
2. It allocates the legacy identity: `id` from the legacy table's own sequence, and `uuid` through the legacy model's `newUniqueId()` when the model defines it.
3. It fills the successor key.
4. It calls the `Contracts\InitializesLegacy` class, which sets the legacy-only values that need the successor graph.

`photos.listing_id`, derived through Place, Job and `listings.job_id`, is one such value. `listings.title`, copied from `Job.name`, is another. The class is optional.

When the map compiles, it refuses a legacy-only NOT NULL column that has neither a default nor an initializer. `assertServesCompatibility()` runs a native create, so a test proves the initializer sets what the column needs.

A forwarded legacy `creating` listener may not supply a retained value. The package refuses one that tries, as it does today.

## Refusals

An operation or an initializer refuses with `throw Refusal::because('...')`.

- **A live write.** The unit of work rolls back, and the caller receives the refusal.
- **A backfill row.** The package rolls back that row's savepoint, and records the row as `refused` with the reason. The pass continues.

Any other exception on a backfill row rolls back that row's savepoint too, and records the row as `failed`.

## The write scope

`writesWithin()` names a class that implements `Contracts\ScopesWrites` ([0026](../decisions/0026-a-write-scope-runs-before-package-locks.md)).

```php
final class ListingWriteScope implements ScopesWrites
{
    public function run(array $successors, Closure $write): mixed
    {
        return JobWriteTransaction::run($successors[Job::class] ?? [], $write);
    }
}
```

- The package calls the scope before it takes any lock of its own. That holds on every path: a record write, a mass write, a backfill row, catch-up, and the refill inside rollback.
- `$successors` holds the keys of the successors the write touches, by class. The scope calls `$write` and returns its result.
- The scope adds to the package's own row locks, and never replaces them. The package's `FOR UPDATE` on the root and the touched related rows always comes inside the scope, last.
- Without a scope, the package locks as it does today.
- Every path resolves the touched keys with an unlocked read before it calls the scope. After it locks, it checks that the keys of the existing successors it locked equal the keys `run()` received. A related key can move between the two steps, and the package would then lock a row the scope never ordered.
- A difference refuses with a retryable error. On a live write, the caller receives the error, and the application's outermost transaction retries. On a backfill row, the row's savepoint rolls back, and the next pass retries the row.
- The package retries nothing inside its writers, on any path. The application's outermost transaction is the retry point.

A map over ordered successors without a write scope can deadlock against an ordering writer. The package documents that risk and does not detect it.

## Open questions

### 1. `company_id` on a create, and an update of `place_id`

Decision 10 settles `place_id` and `job_site_id` on a create, as [creates](#creates) states. Two questions remain.

**`company_id` on a create.** Decision 10 says no path writes a `readOnly()` attribute. The worked Listing map marks `company_id` read-only, and a new Job needs `company_id`.

- **A.** `company_id` drops `readOnly()`, and `writtenBy()` names an operation that assigns it on a create and refuses on an update.
- **B.** `company_id` stays read-only, and `successorsInitializedBy()` sets `Job.company_id` at birth. Decision 5 limits that initializer to successor-only values, so B amends decision 5.

**Recommendation: A.** It stays inside decisions 5 and 10, and the refusal on an update keeps the reason the map states.

**An update of `place_id`.** `place_id`'s default operation assigns the inverse, so an update would assign a new key to the existing Place.

- **A.** The default operation of a related row's key refuses on an update. A map that supports the change names a `writtenBy()` operation, such as one that moves the Job to another Place.
- **B.** The default operation re-points the root's `BelongsTo` relation at the row with the new key, and refuses for any other relation.

**Recommendation: A.** Moving a Job to another Place is a v2 domain action with its own rules, so the map names it. The package never re-keys a related row.

### 2. An attribute with no inverse and no `writtenBy()`

A `readAs()` attribute has no inverse. Without `writtenBy()` or `readOnly()`, it has no operation.

- **A.** The map refuses to compile such an attribute.
- **B.** A write of the attribute refuses at run time.

**Recommendation: A.** Every write intent then maps to an operation that the map states.

### 3. `related()` on an absent row

`related()` returns `T`. A morph has one target at a time, and a related row can be missing.

- **A.** `related()` returns `null` when the row is absent.
- **B.** `related()` refuses when the row is absent.

**Recommendation: A.** `relatedOrNew()` already covers the case where the row must exist. PHPStan then makes an operation handle the absence.

### 4. The types inside `Change`

- **`original`.** A legacy model instance with the original attributes, empty on a create, or an array of attributes. **Recommendation:** an instance, because operations read relations from `record`, and `original` should read the same way.
- **`attribute` on an initializer.** An initializer writes no single attribute. **Recommendation:** `null`.
- **`record` on a native create.** **Recommendation:** the new legacy model, which `InitializesLegacy::initialize()` also receives as `$legacy`.
- **`original` during catch-up.** **Recommendation:** the record as the view serves it before the pass writes.

### 5. What the write scope receives for a successor the write creates

A created successor has no key until the package saves it.

- **A.** The class is absent from `$successors`.
- **B.** The class is present, with an empty list.

**Recommendation: B.** A scope that must lock a parent before a create then knows the create happens. The worked scopes handle both forms with `?? []`.

### 6. A change of morph target

A FloorPlan whose `url` becomes non-null moves from a File to a Link.

- **A.** The package moves the target when `choose:` picks another class. It deletes the old target through its model and creates the new one in the same unit of work.
- **B.** An operation moves it with `deleteRelated()` and `relatedOrNew()`.

**Recommendation: A.** The map already declares the choice, and today's package performs morph moves.

### 7. The round-trip check before install

Before install, no view exists to read back through.

- **A.** The check reads the record through the view's compiled `SELECT`, filtered to the record's key.
- **B.** The backfill skips the check, and the install gate's value comparison covers it.

**Recommendation: A.** An operation that loses a value then refuses its row at once. The cost is one read per backfill row.

### 8. What counts as an initializer for the NOT NULL refusal

- **A.** A column counts as initialized when it has a default, when the package allocates it, or when the map declares `legacyInitializedBy()`. The package allocates the legacy key and a `newUniqueId()` column.
- **B.** The map lists the columns its initializer sets.

**Recommendation: A.** The native create in `assertServesCompatibility()` proves the class sets the column, and no second declaration can drift.

### 9. A native create outside a transaction

The session put the legacy insert inside the successor's transaction. An application can call `Job::create()` outside any transaction.

- **A.** `Provides\CarriesLegacy` refuses a create outside a transaction.
- **B.** The trait inserts the legacy row in a statement of its own after the successor's insert.

**Recommendation: A.** With B, a failed insert leaves a successor that no legacy reader sees, and nothing reports it. The cost is that every native create of a member successor runs in a transaction.

### 10. Native successors before install

The session settled native creates for the retained row. It did not settle two cases before install:

- A successor created while the legacy table is still a table needs a legacy row with values in its mapped columns. Otherwise the next pass overwrites the successor.
- A successor created before the trait shipped has no legacy row at all.

- **A.** The trait acts from its release. Before install, the package fills the new legacy row's mapped columns from the successor's served values, as rollback refills them. The backfill also walks member successors without a legacy row, and inserts one through the same path.
- **B.** The trait acts only after install. Member successors without a legacy row stay unserved, and `compatibility:check` reports them.

**Recommendation: A.** A legacy reader then sees every member successor after install. Catch-up finds the filled values equal and skips them. The cost is a second walk in the backfill.

### 11. The error for moved successor keys

The lock step refuses with a retryable error when the locked keys differ from the keys the scope received. The spec names no class for it. `Write\Refusal` is the refusal an operation or initializer throws, and nothing marks it retryable.

- **A.** A new exception under `Exceptions\`, which [the public surface](public-surface.md#2-exceptions-on-the-surface) decides whether to publish.
- **B.** `Write\Refusal`, with a flag that marks it retryable.

The session did not settle this. Public surface open question 2 decides whether a caller can catch either one by a supported type.
