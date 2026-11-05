Status: Proposed

# Operations

Every write to a served legacy model becomes successor operations. The package runs them as one unit of work, and saves each changed successor once through its model. It then checks that every written attribute reads back ([0017](../decisions/0017-successors-are-written-through-their-models.md), [0020](../decisions/0020-reads-are-declared-writes-are-operations.md)).

## Every write is an operation

Each attribute has exactly one operation:

- **`writtenBy(Operation::class)`.** The class's `write()` runs. It implements `Contracts\WritesAttribute`.
- **A default operation that assigns the inverse.** An attribute whose read the package can invert, such as `from()` with invertible conversions, assigns the inverse to its successor column.
- **`readOnly(because:)`.** On an update, the default operation refuses. On a create, it assigns the inverse, but only into a successor row this create creates ([0027](../decisions/0027-the-view-is-driven-from-the-retained-table.md)).
- **A `position()` attribute's default operation.** It runs eloquent-ordering's `move(Placement::at($value), expected: null)` under the package's locks. A position outside the member range refuses. On a trashed record, it does nothing.
- **The default operations of Eloquent-managed columns.** The root's soft-delete column and a successor's updated-at column keep the record writer's current behavior. [Eloquent-managed columns](#eloquent-managed-columns) states them.

A `readAs()` attribute has no inverse. When it declares neither `writtenBy()` nor `readOnly()`, the map refuses to compile it. Every write intent therefore maps to an operation that the map states.

`writtenBy()` replaces `writeTo()`, `transitions()`, the write closure of `external()`, and an application's own use of `Applying::run()`. An operation can write several successor columns, write related successors, or run a domain action.

Every hook is a class string. The package resolves each operation and hook class from the container, so a constructor can take dependencies.

```php
final class SetDownloadsPermission implements WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void
    {
        $job = $successors->root();

        if ($job->are_customer_downloads_enabled === null) {
            $successors->relatedOrNew(ListingCompatibilityState::class)->fill([
                'downloads_enabled_fallback' => (bool) ($value ?? true),
            ]);

            return;
        }

        $job->are_customer_downloads_enabled = (bool) $value;
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
| `related(class-string<T> $class)` | the related successor of that class, loaded and locked, or `null` when the row is absent |
| `relatedOrNew(class-string<T> $class)` | the related successor, or a new instance the package saves with the others |
| `deleteRelated(class-string<Model> $class)` | deletes the related successor through its model, after the saves |
| `afterSave(Closure $action)` | queues an action that runs after the saves, in the order queued |

A morph has one target at a time, and a related row can be missing, so `related()` returns `null|T`. `relatedOrNew()` covers the case where the row must exist.

A companion, such as Listing's compatibility state row, is a declared relation. An operation reaches it with `relatedOrNew()`. The package has no `add()` for undeclared rows.

## The change context

`Write\Change` carries the context of one write.

| Property | Holds |
| :-- | :-- |
| `record` | the legacy record after the write |
| `original` | the legacy record before the write, as a legacy model instance; empty on a create |
| `attribute` | the name of the attribute the operation writes |
| `origin` | `Origin::Live` or `Origin::Backfill` |

`original` is an instance, because operations read relations from `record`, and `original` reads the same way. During catch-up, `original` is the record as the successors serve it before the pass writes, read through the view's compiled `SELECT`.

A write through the legacy model runs with `Origin::Live`. Every backfill pass runs with `Origin::Backfill`, including the final pass inside install.

### The creation context

`Write\Creation` carries the context an initializer receives.

| Property | Holds |
| :-- | :-- |
| `record` | the legacy record the successors serve; on a native create, the new legacy model |
| `origin` | `Origin::Live` or `Origin::Backfill` |

Declined: `Change` with a null `attribute` and an empty `original`. An initializer writes no single attribute and has no record before it.

## The unit of work

One legacy write runs these steps, in this order:

1. **Scope.** The package opens its transaction. It resolves the successors the write touches with an unlocked read, and calls the write scope with their keys inside that transaction. It takes no lock of its own before this step.
2. **Lock.** Inside the scope, the package loads the record's successors. It locks the root and the touched related rows `FOR UPDATE`, parents first. It then checks that the keys it locked equal the keys the scope received. On a difference, it refuses the write with a retryable error and rolls the unit of work back.
3. **Assign.** The package runs the operation of every changed attribute, in declaration order. Operations assign in memory and queue `afterSave()` actions.
4. **Save.** The package saves each changed successor model once. It then applies every `deleteRelated()`.
5. **Act.** The package runs the `afterSave()` actions in order. A domain action such as `publish()` or eloquent-ordering's `move()` saves on its own, with its own events. When the write assigns a successor's updated-at column, the actions run under `Model::withoutTimestampsOn()` for that successor.
6. **Verify.** The package reads the record back through the view and runs the round-trip check.
7. **Forward.** The package forwards one legacy event.

A legacy record delete runs a delete step in place of steps 3 to 6 ([Eloquent-managed columns](#eloquent-managed-columns)).

The scope runs inside the package's transaction, so the locks the scope takes hold until the package's work commits.

`$listing->update(['is_draft' => false, 'downloads_enabled' => true, 'address_id' => 9])` therefore locks the Job, the Place, the JobSite and the state row. It runs `PublishOrDraftJob`, `SetDownloadsPermission` and `ChangeListingAddress`, and saves each changed row once. `publish()` then saves the Job again, as v2's own publish does.

### Events

| Successor | Statements | Successor events |
| :-- | :-- | :-- |
| changed by assignments | one `UPDATE` | one `saving`, `updating`, `updated` and `saved` |
| created by `relatedOrNew()` | one `INSERT` | one `saving`, `creating`, `created` and `saved` |
| removed by `deleteRelated()` | its model's `delete()` | the events its model's `delete()` fires |
| the root, trashed or restored by its soft-delete column | its model's `delete()` or `restore()` | the events that call fires, including `deleting` and `deleted`, or `restoring` and `restored` |
| the root, removed by a legacy record delete | its model's `forceDelete()` | the events `forceDelete()` fires, including `deleting` and `deleted` |
| saved by an `afterSave()` action | what the action runs | the action's own events |
| unchanged | none | none |

The legacy model's listeners receive one event sequence for the write, such as one `updating` and one `updated` for an update. The package forwards none of the successor events above as legacy events.

A mass write forwards no legacy event, as Eloquent's own mass writes fire no model events. Successor events still fire for each record. Declined: one legacy event sequence per record on a mass write.

## The round trip

After a write, every written attribute must read back its new value through the view. The round-trip check compares each written attribute's value with what the view now serves. When one differs, the check refuses the write and rolls the unit of work back. The check skips a `position()` attribute on a trashed row, because a trashed record has no position.

The round trip binds an operation to its attribute's read. `address_id` reads the `id` of the Address that the Place points at. `ChangeListingAddress` must therefore leave the Place pointing at the Address whose `id` is the written value.

Before install, no view exists. The check then reads the record through the view's compiled `SELECT`, filtered to the record's key. A backfill row whose operation loses a value is therefore refused at once, at the cost of one read per row.

## Eloquent-managed columns

Eloquent manages a successor's soft-delete and updated-at columns itself. Their default operations therefore keep the record writer's current behavior ([0020](../decisions/0020-reads-are-declared-writes-are-operations.md)).

- **Soft delete.** A mapped change of the root's soft-delete column does not assign the column. A non-NULL value queues the root's `delete()` as an `afterSave()` action, and NULL queues its `restore()`. When the root's trashed state already matches the value, the operation assigns the value instead.
- **The deletion date.** `delete()` always stamps the current time. After `delete()`, the package therefore writes the legacy `deleted_at` and `updated_at` onto the root's row with a base-query `UPDATE`, which fires no further events. A backfilled trashed row keeps its original deletion date, and the round trip holds.
- **Updated-at.** An assigned updated-at column keeps the legacy value through step 4, Save, even when that value equals the stored one. The package runs step 5, Act, under `Model::withoutTimestampsOn([...])` for that successor. A domain action that saves again, such as `publish()` or eloquent-ordering's `move()`, therefore keeps the legacy value, and the round trip holds.
- **Hard delete.** From install onward, a legacy record delete runs the root's `forceDelete()` as its own step. The step runs after step 2, Lock, and replaces steps 3 to 6, because a delete writes no attribute. Step 7 then forwards the legacy delete's events. The successor key's `ON DELETE CASCADE` carries the delete through the retained row ([Storage](storage.md)).

The successor's `deleting`, `deleted`, `restoring` and `restored` events therefore fire on the delete path. The rule that every package path writes a successor through its model ([0017](../decisions/0017-successors-are-written-through-their-models.md)) holds there too. The deletion date's `UPDATE` is the one named exception to that rule. It runs after the model's `deleting` and `deleted` events have fired, and it writes only the two timestamps.

Declined for the deletion date: a second model save, which fires extra `saving` and `updated` events per delete, and a round trip that compares only whether `deleted_at` is NULL, which loses backfilled deletion dates.

Before install, a legacy delete removes the legacy row, and a pass deletes the successor through its model ([Lifecycle: deletions](lifecycle.md#deletions)).

Declined: plain assignment of the soft-delete column. The successor would then fire `updated` but never `deleted` or `restored`, and a legacy delete would have no path to `forceDelete()`.

## Creates

A create is a write of every supplied attribute against an empty original. The attribute operations run first, in declaration order. `successorsInitializedBy()` then runs once on the new root.

A `readOnly()` attribute's default operation assigns the inverse on a create, into the successor row this create creates. A Listing create therefore assigns `company_id` to its new Job. A Listing created with a reserved `place_id` creates its Place with that id. An update of `company_id`, `place_id` or `job_site_id` refuses. Moving a Job to another Place is a v2 domain action with its own rules, so the package never re-keys a related row.

A create through the legacy model runs with `Origin::Live`. The backfill creates with `Origin::Backfill`.

## Morph targets

When `choose:` picks another target class for a record, the package moves the target. It deletes the old target through its model, and creates the new one, in the same unit of work. A FloorPlan whose `url` becomes non-null therefore moves from a File to a Link.

## Mass writes, the backfill and catch-up

- **A mass write** makes one unlocked read of every record's successors. It calls the write scope once, with the keys of all of them. Inside the scope, it locks every record's successors, parents first, and re-checks the keys once. It then runs steps 3 to 6 of [the unit of work](#the-unit-of-work) once per record, and forwards no legacy event. A mass write has no retry of its own.
- **The backfill** creates each missing successor graph through the attribute operations and `successorsInitializedBy()`, with `Origin::Backfill`. Each pass holds the legacy model's advisory lock, so a second pass of the same model refuses with a named error instead of waiting ([Lifecycle: a pass](lifecycle.md#a-pass)). Inside a row's savepoint, the create path locks the legacy row `FOR UPDATE`, then re-reads its successor key before it creates.
- **Catch-up** runs the attribute operations for changed values. It skips an attribute whose value the view already serves equivalently.

## Positions

A `position()` attribute reads the successor's one-based position through eloquent-ordering's published position expression. Its default operation moves the successor with `Placement::at()`.

The rank conversion runs in the legacy-to-successor direction only: on a backfill row and a catch-up row. The legacy value becomes its rank among the live rows of the same legacy parent, the column `legacyWithin` names. The rows are ordered by the value with NULLs last, then by key. The default operation then moves the successor to that rank.

A trashed record has no position. The default operation does nothing on a trashed record, and the rank conversion counts live rows only. The round trip and install's value proof skip a `position()` attribute on a trashed row. After install, a trashed row reads a NULL position.

After install, a write compares the raw value with the position. A written `index` of 3 moves the row to position 3, and the round trip reads 3 back. [Lifecycle: positions after install](lifecycle.md#positions-after-install) states what consumers then read.

## Initializers

### `successorsInitializedBy()`

The class implements `Contracts\InitializesSuccessors`. It sets only two kinds of value: successor-only values set once at birth, and values derived from history. Examples are `Job.name`, `opted_in_to_zma`, and `payment_status` derived from orders under `Origin::Backfill`. It is optional.

```php
final class InitializeListingJob implements InitializesSuccessors
{
    public function initialize(Successors $successors, Creation $creation): void
    {
        $listing = $creation->record;
        $job = $successors->root();
        $job->name = $listing->title ?: 'Legacy listing '.$listing->uuid;
        $job->opted_in_to_zma = false;
        $job->payment_status = $creation->origin === Origin::Backfill
            ? PaymentStatus::fromOrders($listing->orders)
            : PaymentStatus::Unpaid;
    }
}
```

The legacy record never owns a successor-only column. The initializer and the successor's own code own it.

### `legacyInitializedBy()`

The reverse direction. An application creates a successor natively, and the successor carries `Provides\CarriesLegacy`. From install onward, the package inserts the legacy row inside the successor's transaction:

1. It inserts a legacy row only when the new successor is inside the map's membership.
2. It allocates the legacy identity: `id` from the legacy table's own sequence, and `uuid` through the legacy model's `newUniqueId()` when the model overrides `newUniqueId()`. Eloquent's own `newUniqueId()` returns null, so the package allocates no `uuid` for a model that does not override it.
3. It fills the successor key.
4. It calls the `Contracts\InitializesLegacy` class, which sets the legacy-only values that need the successor graph. `Creation::$record` is the new legacy model, and `Creation::$origin` is `Origin::Live`.

`photos.listing_id`, derived through Place, Job and `listings.job_id`, is one such value. `listings.title`, copied from `Job.name`, is another. The class is optional.

`Provides\CarriesLegacy` takes an `ACCESS SHARE` lock on the legacy table before it checks whether the model is installed. A native create therefore waits for install's `ACCESS EXCLUSIVE` lock, and afterward it sees the installed state.

`Provides\CarriesLegacy` refuses a native create outside a transaction. An insert in a statement of its own could fail and leave a successor that no legacy reader sees, and nothing would report it. Every native create of a member successor therefore runs in a transaction.

When the map compiles, it refuses a legacy-only NOT NULL column that nothing initializes. A column counts as initialized when it has a default, when the package allocates it, or when the map declares `legacyInitializedBy()`. The package allocates the legacy key, and a `newUniqueId()` column when the model overrides `newUniqueId()`. `assertServesCompatibility()` runs a native create, so a test proves the initializer sets what the column needs.

A forwarded legacy `creating` listener may not supply a retained value. The package refuses one that tries, as it does today.

### Native successors before install

`Provides\CarriesLegacy` does nothing before install, so a root created natively then gets no legacy row. Install never inserts a row for such a root before its lock ([Lifecycle: install](lifecycle.md#install)):

- **Before the lock**, install records the start time of its read. It then reads the keys of member roots that no legacy row's successor key matches, through the indexed anti-join on the successor key. The read excludes every root that a `deleted` ledger row names in `successor_id`. Install writes nothing for these roots, and it reports their count.
- **Under the lock**, after the final pass, the rename and the drop of the mapped columns, install inserts one retained row per root. The set holds each key the pre-lock read found, plus every member root whose `created_at` is at or after the read's start time minus the margin. Install anti-joins both against the retained table's successor key, so a root the final pass just created is excluded. It also excludes every root that a `deleted` ledger row names in `successor_id`, because the application hard-deleted that root's legacy row.
- Each row holds the root's key in the successor key. Install runs `legacyInitializedBy()` for it, with `Creation::$origin` set to `Origin::Backfill`.
- Under the lock, install catches nothing from an initializer. A `Refusal` from `legacyInitializedBy()` therefore ends the transaction and aborts install, and the refusal names the root's key and the reason.

No pass ever walks these rows, and before install no legacy reader sees a half-filled row. The cost is that install's pause grows with the number of natively created roots, inside the same lock budget, `statementTimeout:`. Declined: an insert before the lock with the mapped columns filled from the successors, because the final pass would then overwrite a v2 edit made inside the install window. After install, the trait inserts the legacy row on create.

Legacy readers therefore see no v2-created listing until install. Declined: mirroring native successor saves into the legacy row before install, which needs a listener per related class and a two-way sync.

## Refusals

An operation or an initializer refuses with `throw Refusal::because('...')`.

- **A live write.** The unit of work rolls back, and the caller receives the refusal.
- **A backfill row.** The package rolls back that row's savepoint, and records the row as `refused` with the reason. The pass continues.
- **Install's native-root insert.** Install catches nothing under its lock, so the refusal aborts install, and names the root's key and the reason.

Any other exception on a backfill row rolls back that row's savepoint too, and records the row as `failed`.

## The write scope

`writesWithin()` names a class that implements `Contracts\ScopesWrites` ([0026](../decisions/0026-a-write-scope-runs-before-package-locks.md)).

```php
final class ListingWriteScope implements ScopesWrites
{
    public function run(array $successors, Closure $write): mixed
    {
        return JobWriteTransaction::run(jobs: $successors[Job::class] ?? [], write: $write);
    }
}
```

- The package opens its transaction, then calls the scope inside it, before it takes any lock of its own. That holds on every scoped path: a record write, a mass write, a backfill row, and catch-up in an ordinary pass. A mass write calls the scope once, with the keys of every record it writes.
- Install's final catch-up pass and rollback's refill never call the scope, and run no key re-check. Their table lock serializes them against live writes. Rollback waits for in-flight writes to commit, so its refill reads only committed successors. A scope there would deadlock with a live write that holds its scope lock and reads through the view.
- `$successors` holds the keys of the successors the write touches, by class. A successor the write creates has no key yet, so its class is present with an empty list. A scope that must lock a parent before a create then knows the create happens.
- The scope runs `$write` and returns its result. The package's transaction is already open, so the scope's own locks hold until the package's work commits. A scope needs no transaction of its own.
- The scope adds to the package's own row locks, and never replaces them. The package's `FOR UPDATE` on the root and the touched related rows always comes inside the scope, last.
- Without a scope, the package locks as it does today.
- Every scoped path resolves the touched keys with an unlocked read before it calls the scope. After it locks, it checks that the keys of the existing successors it locked equal the keys `run()` received. A related key can move between the two steps, and the package would then lock a row the scope never ordered.
- A difference refuses with a retryable error. On a live write, the caller receives the error, and the application's outermost transaction retries. On a backfill row, the row's savepoint rolls back, and the next pass retries the row.
- The package retries nothing inside its writers, on any path. The application's outermost transaction is the retry point.

A map over ordered successors without a write scope can deadlock against an ordering writer. The package documents that risk and does not detect it.

## Open questions

### 1. The error for moved successor keys

The lock step refuses with a retryable error when the locked keys differ from the keys the scope received. The spec names no class for it. `Write\Refusal` is the refusal an operation or initializer throws, and nothing marks it retryable.

- **A.** A new internal exception under `Exceptions\`.
- **B.** `Write\Refusal`, with a flag that marks it retryable.

The session did not settle this. Either one extends `Exceptions\CompatibilityException`, which [the public surface](public-surface.md#classes) publishes, so a caller catches it by that supported type.
