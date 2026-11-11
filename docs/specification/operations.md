Status: Proposed

# Operations

Every write to a served legacy model becomes successor operations. The package runs them as one unit of work, and saves each changed successor once through its model. It then checks that every written attribute reads back ([0017](../decisions/0017-successors-are-written-through-their-models.md), [0020](../decisions/0020-reads-are-declared-writes-are-operations.md)).

## Every write is an operation

Each attribute has exactly one operation:

- **`writtenBy(Operation::class)`.** The class's `write()` runs. It implements `Contracts\WritesAttribute`.
- **A default operation that assigns the inverse.** An attribute whose read the package can invert, such as `from()` with invertible conversions, assigns the inverse to its successor column.
- **`readOnly(because:, ignoreWrites:)`.** With `ignoreWrites: false`, the default operation refuses on an update. On a create, it assigns the inverse, but only into a successor row this create creates. With `ignoreWrites: true`, it accepts and discards every write, and dispatches `Events\WriteIgnored` ([0027](../decisions/0027-the-view-is-driven-from-the-retained-table.md)). [Read-only attributes](#read-only-attributes) states both, and the rule for an attribute with no stored column.
- **A `position()` attribute's default operation.** On a live write after install, it runs eloquent-ordering's `move(Placement::at($value), expected: null)` under the package's locks. A position outside the member range refuses. On a backfill row and a catch-up row, it re-sequences the row's ordering parent in legacy order instead ([Positions](#positions)). On a trashed record, it does nothing.
- **The default operations of Eloquent-managed columns.** The root's soft-delete column and a successor's updated-at column keep the record writer's current behavior. [Eloquent-managed columns](#eloquent-managed-columns) states them.

An attribute with no inverse, such as a `readAs()` or `computedAs()` attribute, must declare `writtenBy()` or `readOnly()`. When it declares neither, the map refuses to compile it. Every write intent therefore maps to an operation that the map states.

`writtenBy()` replaces `writeTo()`, `transitions()`, the write closure of an external attribute, and an application's own use of `Applying::run()`. An operation can write several successor columns, write related successors, or run a domain action.

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
| `deleteRelated(class-string<Model> $class)` | deletes the related successor through its model, after the saves; a later `related()` or `relatedOrNew()` of that class in the same write refuses |
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
2. **Lock.** Inside the scope, the package loads the record's successors. It locks the root, its retained row and the touched related rows `FOR UPDATE`, parents first. The touched related rows are every related successor the map declares under the root, because an operation names the successors it writes only when it runs. It then checks that the keys it locked equal the keys the scope received. On a difference, it refuses the write with a retryable error and rolls the unit of work back.
3. **Assign.** The package runs the operation of every changed attribute, in declaration order. Operations assign in memory and queue `afterSave()` actions.
4. **Save.** The package saves each changed successor model once. It then applies every `deleteRelated()`.
5. **Act.** The package runs the `afterSave()` actions in order. A domain action such as `publish()` or eloquent-ordering's `move()` saves on its own, with its own events. When the write assigns a successor's updated-at column, the actions run under `Model::withoutTimestampsOn()` for that successor's class.
6. **Verify.** The package reads the record back through the view and runs the round-trip check.
7. **Forward.** The package forwards one legacy event.

A legacy record delete runs a delete step in place of steps 3 to 6 ([Eloquent-managed columns](#eloquent-managed-columns)).

A mass write calls the scope once, with the keys of every record. It then locks every root and its retained row, in key order, before it locks any related row. It runs its records in chunks of 1,000: for each chunk, one statement per related table locks that table's rows in key order, and the unit of work runs once for each record of the chunk. No statement binds more than one chunk of keys, and the records of one chunk are in memory at a time.

The scope runs inside the package's transaction, so the locks the scope takes hold until the package's work commits.

`$listing->update(['is_draft' => false, 'downloads_enabled' => true, 'address_id' => 9])` therefore locks the Job, its retained row, the Place, its Address, the JobSite and the state row. It runs `PublishOrDraftJob`, `SetDownloadsPermission` and `ChangeListingAddress`, and saves each changed row once. `publish()` then saves the Job again, as v2's own publish does.

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

After a write, every written attribute must read back its new value through the view. The round-trip check compares each written attribute's value with what the view now serves. When one differs, the check refuses the write and rolls the unit of work back. [What the comparisons skip](#what-the-comparisons-skip) names the attributes it does not compare.

The round trip binds an operation to its attribute's read. `address_id` reads the `id` of the Address that the Place points at. `ChangeListingAddress` must therefore leave the Place pointing at the Address whose `id` is the written value.

The check compares values the way the legacy model's casts do. A JSON object compares by its keys and values, in any key order. A `DateTimeInterface` value compares in the legacy model's date format. Two numbers compare by value only when the view column or the legacy cast is numeric, so a text value such as `00123` compares exactly. A JSON-path write, such as `settings->color`, compares the value at that path. A path write into a NULL column writes nothing, as it does natively, so the check skips it.

A `hide()` rule can hide the record after the write, whether the write itself, a successor listener or a trigger trips it. The check then reads the record through the map's column expressions without its `hide()` conditions, filtered to the record's key. A hide that keeps every written value passes, and a hide that loses one refuses the write. When that read also finds no row, a listener or a trigger removed the record, and the check refuses the write.

Before install, no view exists, and a legacy write goes to the legacy table without reaching the check. Only a backfill row reaches it then, and the check reads that record through the view's compiled `SELECT`, filtered to the record's key. A backfill row whose operation loses a value is therefore refused at once, at the cost of one read per row.

### What the comparisons skip

Five comparisons check a served value: the round trip, catch-up's equality check, the `unchanged` test, the drift check and install's value proof. None of them compares:

- a `readOnly()` attribute declared with `ignoreWrites: true`;
- a `readOnly()` attribute with no stored column;
- a `computedAs()` attribute, with one exception.

The exception: under `Origin::Live`, the round trip compares a `computedAs()` attribute that declares `writtenBy()`. Such an attribute has no value in the view, because PHP computes it after the query. The check calls its read closure with a collection of the one reloaded successor, and compares the value the closure returns for that successor's key.

A `position()` attribute is compared by its parent's legacy order before install, and by its raw value after install. No comparison checks it on a trashed row, because a trashed record has no position ([Positions](#positions)).

## Legacy-only writes

A legacy-only column holds a value that no successor column holds and no read computes ([The map: legacy-only columns](the-map.md#legacy-only-columns)). The application refreshes it through the legacy model. A legacy write whose changed columns are all legacy-only, apart from the `updated_at` that Eloquent touches:

- writes the retained row;
- drops Eloquent's `updated_at` touch, and writes nothing for `updated_at`, when the map reads `updated_at` from a successor; a legacy-only `updated_at` keeps the touch, which writes only the retained row;
- runs no operation and saves no successor: steps 3 to 6 of [the unit of work](#the-unit-of-work) do nothing, so no successor event fires;
- forwards one legacy event sequence at step 7, as any Eloquent update does;
- fires nothing under `saveQuietly()`, which suppresses that legacy event sequence as it does in Eloquent.

Eloquent's `update()` and `save()` stamp `updated_at` before they write. Listing maps `updated_at` to `jobs.updated_at`, so the touch alone would save the Job and fire its four events. The package therefore drops the touch, and the legacy `updated_at` keeps the value the root serves. A map that leaves `updated_at` legacy-only keeps the touch, because it saves no successor.

## Eloquent-managed columns

Eloquent manages a successor's soft-delete and updated-at columns itself. Their default operations therefore keep the record writer's current behavior ([0020](../decisions/0020-reads-are-declared-writes-are-operations.md)).

- **Soft delete.** A mapped change of the root's soft-delete column does not assign the column. A non-NULL value queues the root's `delete()` as an `afterSave()` action, and NULL queues its `restore()`. When the root's trashed state already matches the value, the operation assigns the value instead.
- **The deletion date.** `delete()` always stamps the current time. After `delete()`, the package therefore writes the legacy `deleted_at` and `updated_at` onto the root's row with a base-query `UPDATE`, which fires no further events. A backfilled trashed row keeps its original deletion date, and the round trip holds.
- **Updated-at.** An assigned updated-at column keeps the legacy value through step 4, Save, even when that value equals the stored one. The package runs each save and delete of step 4, and step 5, Act, under `Model::withoutTimestampsOn([...])` for that successor's class. A successor the write creates gets its `created_at` stamped first, when the write leaves that column unassigned. A listener or an owner touch that saves the successor again during a save, or a domain action that saves again, such as `publish()` or eloquent-ordering's `move()`, therefore keeps the legacy value, and the round trip holds. A catch-up row that writes any attribute also assigns each mapped updated-at column of that row with its legacy value, even when the view already serves that value. Otherwise Eloquent's touch on save would replace the served updated-at with the time of the pass.
- **Hard delete.** From install onward, a legacy record delete runs the root's `forceDelete()` as its own step. The step runs after step 2, Lock, and replaces steps 3 to 6, because a delete writes no attribute. Step 7 then forwards the legacy delete's events. The successor key's `ON DELETE CASCADE`, which install adds, carries the delete through the retained row ([Storage](storage.md#the-successor-key)).

The successor's `deleting`, `deleted`, `restoring` and `restored` events therefore fire on the delete path. The rule that every package path writes a successor through its model ([0017](../decisions/0017-successors-are-written-through-their-models.md)) holds there too. The deletion date's `UPDATE` is the one named exception to that rule. It runs after the model's `deleting` and `deleted` events have fired, and it writes only the two timestamps.

Declined for the deletion date: a second model save, which fires extra `saving` and `updated` events per delete, and a round trip that compares only whether `deleted_at` is NULL, which loses backfilled deletion dates.

Before install, a legacy delete removes the legacy row, and a pass deletes the successor through its model ([Lifecycle: deletions](lifecycle.md#deletions)).

Declined: plain assignment of the soft-delete column. The successor would then fire `updated` but never `deleted` or `restored`, and a legacy delete would have no path to `forceDelete()`.

## Creates

A create is a write of every supplied attribute against an empty original. The attribute operations run first, in declaration order. `successorsInitializedBy()` then runs once on the new root.

A `readOnly()` attribute's default operation assigns the inverse on a create, into the successor row this create creates. A Listing create therefore assigns `company_id` to its new Job. A Listing created with a reserved `place_id` creates its Place with that id. When that id names an existing Place whose Job is gone, such as one that outlived a force-deleted Job, the create links that Place and creates nothing. The create links it before step 3, so every operation of the create sees the linked Place, whatever order the map declares its attributes in. When the named Place still belongs to an existing Job, trashed or not, the create refuses with a named reason. `job_site_id` follows the same rule. The recreation of a root through the create path therefore never collides with a related row that survived it. Listing declares the three attributes with `ignoreWrites: false`, so an update of `company_id`, `place_id` or `job_site_id` refuses. Moving a Job to another Place is a v2 domain action with its own rules, so the package never re-keys a related row.

A `readOnly()` attribute with no stored column has no inverse, and [Read-only attributes with no stored column](#read-only-attributes-with-no-stored-column) states its rule.

The successor key is an identity ([Storage: the successor key](storage.md#the-successor-key)). After install, a create that supplies the successor key uses it as the root's key, and refuses when the key names an existing root. That test reads the root's table without global scopes, so a trashed root counts as existing ([Storage: the successor key](storage.md#the-successor-key)). Before install, a create through the legacy model writes the legacy table only. The backfill then creates the root with the supplied key, when that key names no row.

A create through the legacy model runs with `Origin::Live`. The backfill creates with `Origin::Backfill`.

## Read-only attributes

`readOnly(because:, ignoreWrites:)` gives `ignoreWrites:` no default, so every declaration states it. The flag applies to an attribute with a stored column and to one without ([0027](../decisions/0027-the-view-is-driven-from-the-retained-table.md)).

- **`ignoreWrites: false`.** The attribute refuses writes. An update of the attribute refuses. On a create, the default operation assigns the inverse into a successor row this create creates. An attribute with no stored column follows [the rule below](#read-only-attributes-with-no-stored-column).
- **`ignoreWrites: true`.** Every path accepts a write to the attribute and discards it: a live create, a live update, a mass write, a backfill create and a catch-up row. No path compares the attribute ([What the comparisons skip](#what-the-comparisons-skip)). A create still assigns the inverse into a successor row it creates, where the attribute has an inverse.

A discarded non-NULL write dispatches `Events\WriteIgnored`, synchronously, inside the write's transaction and under its locks. A listener that throws therefore fails the write it observes, and a slow listener holds every lock of the write. Queue a listener that reports to a service outside the request, and dispatch it after commit (`ShouldQueue` with `$afterCommit`). The event has two limits on the backfill's paths. Catch-up dispatches nothing. A backfill create dispatches only for a value it does not assign into a row it creates. `Origin::Backfill` events are therefore bounded to one per row and attribute, and none fires under install's lock. The event carries the write's `Write\Change` and the discarded value. A discarded NULL dispatches nothing, so a create whose `$attributes` defaults hold NULL stays silent. [The map: read-only attributes](the-map.md#read-only-attributes) states when a map flips the flag.

### Read-only attributes with no stored column

A `readOnly()` attribute with no stored column has no inverse: a `computedAs()` value, or a `readOnly()` `readAs()` expression. With `ignoreWrites: false`, its rule depends on the origin:

- **`Origin::Live`.** A create that supplies a non-NULL value for the attribute refuses. An update of the attribute refuses, as it does for every `readOnly()` attribute declared with `ignoreWrites: false`.
- **`Origin::Backfill`.** A pass never writes the attribute: a backfill create and a catch-up row ignore its legacy value. A backfill row's value is history that the successor supersedes.

No comparison checks the attribute, on any origin ([What the comparisons skip](#what-the-comparisons-skip)). A live create that supplies NULL, for example from the model's `$attributes` defaults, therefore passes step 3 and the round trip. The one `Origin::Live` check is the refusal of a non-NULL value at step 3.

A catch-up row that differs only in such an attribute therefore runs no operation, and its outcome is `unchanged`. A map whose successors already exist, such as Listing, takes the update path on its first pass, and the rule holds there too.

Declined: refusing always, because the backfill could not proceed. Declined: ignoring always, because a live caller's value would be discarded without a trace. `ignoreWrites: true` ignores a write and dispatches `Events\WriteIgnored`, so the trace remains. Declined: comparing, because a value that legitimately differs, such as a re-signed URL, would block install.

### Computed attributes under the backfill

A `computedAs()` attribute has no stored column, whatever its write declaration. Under `Origin::Backfill`, no comparison checks it, including one that declares `writtenBy()` ([What the comparisons skip](#what-the-comparisons-skip)).

A pass still runs the attribute's `writtenBy()` operation on every row it walks, because no comparison tells whether the value changed. It skips a row whose chosen morph target does not declare the attribute, such as a floor plan served from a Link. A walked row whose other legacy values are unchanged therefore runs that operation too. The operation decides what to do with the value, and it must change no successor when it runs again with an unchanged value. The row's outcome is `unchanged` unless the operation changes a successor.

A media URL is such an attribute. Its operation writes the v1 URL onto a File that is not in ZML, and does nothing under `Origin::Backfill` for a File in ZML ([The map: media values](the-map.md#media-values)). An equal assignment leaves the File clean, so Eloquent saves nothing. A v1 URL edit made before install reaches a non-ZML File when a pass walks its row: when the edit sets `updated_at`, or when a pass walks the row for another reason. An edit that leaves `updated_at` alone is lost, because install's value proof skips the attribute ([Lifecycle: the writer boundary](lifecycle.md#the-writer-boundary)). An asset the ZML migration moved never refuses.

Under `Origin::Live`, a `computedAs()` attribute that declares `writtenBy()` is compared: the round trip calls its read closure with the reloaded successor.

Declined: an operation signal that its value is superseded, which would be new API. Declined: a resolver that depends on the install state.

## Morph targets

When `choose:` picks another target class for a record, the package moves the target. It deletes the old target through its model, and creates the new one, in the same unit of work. A FloorPlan whose `url` becomes non-null therefore moves from a File to a Link.

## Mass writes, the backfill and catch-up

- **A mass write** makes one unlocked read of every record's successors. It calls the write scope once, with the keys of all of them. Inside the scope, it locks every record's successors, parents first, and re-checks the keys once. It then runs steps 3 to 6 of [the unit of work](#the-unit-of-work) once per record, and forwards no legacy event. A mass write has no retry of its own.
- **The backfill** creates each missing successor graph through the attribute operations and `successorsInitializedBy()`, with `Origin::Backfill`. Each pass holds the legacy model's advisory lock, so a second pass of the same model refuses with a named error instead of waiting ([Lifecycle: a pass](lifecycle.md#a-pass)). Inside a row's savepoint, the create path locks the legacy row `FOR UPDATE`, then re-reads its successor key before it creates. A key that names no row creates the root with that key value ([Storage: the successor key](storage.md#the-successor-key)).
- **Catch-up** runs the attribute operations for changed values. It skips an attribute whose value the view already serves equivalently, and never compares the attributes that [What the comparisons skip](#what-the-comparisons-skip) names. A `position()` attribute is equivalent when its parent's members sit in legacy order ([Positions](#positions)). Catch-up never writes a `readOnly()` attribute declared with `ignoreWrites: true`, or a `readOnly()` attribute with no stored column ([Read-only attributes](#read-only-attributes)). It runs a `computedAs()` attribute's `writtenBy()` operation on every walked row ([Computed attributes under the backfill](#computed-attributes-under-the-backfill)).

## Positions

A `position()` attribute reads the successor's one-based position through eloquent-ordering's published position expression. On a live write after install, its default operation moves the successor with `Placement::at()`, because the legacy value is then a served position. On a backfill row and a catch-up row, the default operation re-sequences the successor's parent in legacy order.

Positions number within the successor's eloquent-ordering parent ([0030](../decisions/0030-positions-number-within-the-ordering-parent.md)). A listing's photos can span several subsections, and v2 orders within each subsection, so the legacy listing is not the ordering parent. The package reads the parent from eloquent-ordering's published parent key columns for the successor.

The re-sequence runs in the legacy-to-successor direction only: on a backfill row and a catch-up row. At step 3, Assign, the default operation queues an `afterSave()` action and assigns nothing. At step 5, Act, the parent is set, because step 4 saved the root and `successorsInitializedBy()` has run. The action then reads the successor's parent, and re-sequences it:

1. The action takes eloquent-ordering's parent lock before it reads the members. That is the lock `move()` takes: `FOR NO KEY UPDATE` on the parent row, held to commit. The re-sequence therefore serializes with every other move in that parent. The members' keys are not added to the write scope's key list.
2. The members are the parent's live legacy rows whose successors are current members of the parent: not trashed, and with a `rank_key`. The written row is one of them. They are ordered by the legacy value with NULLs last, then by key.
3. The action walks the members in that order. The first member stays where it is.
4. It moves each later member whose successor does not already follow its predecessor's successor to just after it, with `move(Placement::after(<the predecessor's successor key>), expected: null)`.

One sweep ends with the parent's members in legacy order, because each move puts a member after a predecessor that already follows every earlier member. Native members of the parent keep their places. Each move saves that member's successor, with its own events. A move of a member other than the written row runs under `Model::withoutTimestampsOn()` for the root class. The package moved that member, and no writer edited it, so its served `updated_at` does not change.

The sweep converges after any reorder, by v1 or by v2. When v1 changes A, B, C and D to the legacy order C, D, A and B, the first walked row re-sequences the whole parent. The rows walked after it find their parent already in order, and run no move.

Before install, the round trip and catch-up's equality check compare a `position()` attribute over the whole parent, once per parent. Each member's successor `rank_key` must exceed its predecessor's. That is install's order proof, over the same members ([Lifecycle: the install gate](lifecycle.md#the-install-gate)).

The cost falls on backfill passes, and on install's catch-up only for the parents its order proof flags. After install, a write moves the row with `Placement::at()`, and no path re-sequences a parent.

Declined: placing only the written row after its nearest previous neighbour, checking only that neighbour, and repeating catch-up rounds until a round makes no progress. Those rounds iterate under install's lock, and they need a stop rule.

Declined: moving the successor to its rank among the legacy rows with `Placement::at()`. Before install, a parent also holds native members with no legacy row. A rank counted among legacy rows alone then puts a row between the wrong neighbours. A parent of N, A and B that receives C at rank 3 reads N, A, C, B, and install's order proof refuses.

The re-sequence walks eloquent-ordering's `(parent, rank_key)` index and the legacy table's successor-key index. It needs no index of the consumer's on the legacy table.

eloquent-ordering ships `Placement::after()` and `Placement::before()` today. `Placement::at()` and the published parent key columns remain requests in https://github.com/AryeoHQ/eloquent-ordering/issues/14.

Declined: a per-listing parent override on `position()`, until a caller needs one sequence per listing. Such a caller reads `readAs()` with an operation instead.

A trashed record has no position. The default operation does nothing on a trashed record, and the re-sequence takes only current members. The round trip and install's value proof skip a `position()` attribute on a trashed row. After install, a trashed row reads a NULL position.

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

`Provides\CarriesLegacy` takes an `ACCESS SHARE` lock on each legacy table whose `when()` the new root matches, before it checks whether that legacy model is installed. It locks no other legacy table. A native create therefore waits for the `ACCESS EXCLUSIVE` lock of an install it would join, and afterward it sees the installed state. A native floor plan create waits for FloorPlan's install, and not for Photo's.

`Provides\CarriesLegacy` opens a transaction around the insert when the caller has none, for a root class that a discovered map serves, or a subclass of one. It does so whether or not that map is installed, and decides inside the transaction, under the `ACCESS SHARE` lock, whether to insert the legacy row. The `creating` event, the root insert, the retained-row insert and the `created` event then commit or roll back together, through `save()` and through `saveOrIgnore()`. A create through those two paths therefore leaves no successor without its legacy row. Inside a caller's transaction, the trait opens nothing. A successor that overrides `performInsert()`, `performInsertOrIgnore()` or `fireModelEvent()` itself conflicts with the trait.

The trait sees only a model insert. A query-builder insert into the root table (`Job::query()->insert()`, `upsert()`, `insertUsing()`, `DB::table('jobs')`) and an insert through another class over the root table that is not a subclass of the map's root insert no legacy row, so the view does not serve that root. [Lifecycle: the writer boundary](lifecycle.md#the-writer-boundary) lists these writers.

`assertServesCompatibility()` refuses a legacy-only NOT NULL column that nothing initializes. Map compilation reads no database ([0011](../decisions/0011-registration-needs-no-database.md)), so it cannot see the column. A column counts as initialized when it has a default, when the package allocates it, or when the map declares `legacyInitializedBy()`. The package allocates the legacy key, and a `newUniqueId()` column when the model overrides `newUniqueId()`. `assertServesCompatibility()` runs the native create a test supplies. For a root inside `when()`, a test therefore proves the initializer sets what the column needs ([Public surface: testing](public-surface.md#testing)).

A forwarded legacy `creating` listener may not supply a retained value. The package refuses one that tries, as it does today.

A native create on a root that several discovered maps share forwards its legacy events only to the legacy model whose `when()` admits the new row. The package raises that model's `saving` and `creating` after the successor's own `creating` event returns, and before the insert. `when()` therefore reads the values that every successor `saving` and `creating` listener set, including the `Creating` listener that sets a discriminator ([The map: membership](the-map.md#membership)). A legacy veto from either event stops the insert. `created` and `saved` follow the insert. A root that one map serves keeps the earlier order, in which the legacy `saving` and `creating` run ahead of the successor's own listeners.

On a shared root, a legacy `saving` listener therefore runs after the successor's `saving` and `creating` listeners, and no successor listener sees a change it makes. The package refuses that listener's change of a `computedAs()` attribute, because the attribute's operation reaches no successor `saving` listener.

When the inserted row joins another legacy model than the one the `creating` event chose, the package refuses the create inside its transaction with `CompatibilityWriteRefused::membershipMoved()`. A database default, a trigger, or a legacy `creating` listener that changes a column `when()` reads causes it.

### Native successors before install

`Provides\CarriesLegacy` inserts no legacy row before install, so a root created natively then gets none. Install never inserts a row for such a root before its lock ([Lifecycle: install](lifecycle.md#install)):

- **Before the lock**, install records the start time of its read. It then reads the keys of member roots that no legacy row's successor key matches, through the indexed anti-join on the successor key. The read excludes every root that a `deleted` ledger row names in `successor_id`. Install writes nothing for these roots, and it reports their count.
- **Under the lock**, after the final pass, the rename and the drop of the mapped columns, install inserts one retained row per root. The set holds each key the pre-lock read found, plus every member root whose `created_at` is at or after the read's start time minus the margin. Install anti-joins both against the retained table's successor key, so a root the final pass just created is excluded. It also excludes every root that a `deleted` ledger row names in `successor_id`, because the application hard-deleted that root's legacy row.
- Each row holds the root's key in the successor key. Install runs `legacyInitializedBy()` for it, with `Creation::$origin` set to `Origin::Backfill`.
- Under the lock, install catches nothing from an initializer. A `Refusal` from `legacyInitializedBy()` therefore ends the transaction and aborts install, and the refusal names the root's key and the reason.

No pass ever walks these rows, and before install no legacy reader sees a half-filled row. The cost is that install's pause grows with the number of natively created roots, inside the same lock budget, `statementTimeout:`. Declined: an insert before the lock with the mapped columns filled from the successors, because the final pass would then overwrite a v2 edit made inside the install window. After install, the trait inserts the legacy row on create.

Legacy readers therefore see no v2-created listing until install. Declined: mirroring native successor saves into the legacy row before install, which needs a listener per related class and a two-way sync.

## Refusals

An operation or an initializer refuses with `throw Refusal::because('...')`.

- **A live write.** The unit of work rolls back, and the caller receives a `Refusal` whose message names the legacy model, the record key or a new record, the attribute and the operation or initializer class, ahead of the reason. The consumer's own `Refusal` is its previous exception.
- **A backfill row.** The package rolls back that row's savepoint, and records the row as `refused` with the reason. The pass continues. A `CompatibilityWriteRefused` on a backfill row is recorded as `refused` too.
- **Install's native-root insert.** Install catches nothing under its lock, so the refusal aborts install, and names the root's key and the reason.

Any other exception on a backfill row rolls back that row's savepoint too, and records the row as `failed`, except a lock timeout or a deadlock, which the pass retries ([Lifecycle: a pass](lifecycle.md#a-pass)).

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
- A scope that catches an exception of `$write` does not commit the refused write: the package rethrows the exception after `run()` returns. A scope that returns without calling `$write` refuses with `CompatibilityWriteRefused::unscoped()`, because the write would report success and store nothing.
- The scope adds to the package's own row locks, and never replaces them. The package's `FOR UPDATE` on the root, its retained row and the touched related rows always comes inside the scope, last.
- Without a scope, the package locks as it does today.
- Every scoped path resolves the touched keys with an unlocked read before it calls the scope. After it locks, it checks that the keys of the existing successors it locked equal the keys `run()` received. A related key can move between the two steps, and the package would then lock a row the scope never ordered.
- A difference refuses with a retryable error, `CompatibilityWriteRefused::successorsMoved()`, which extends `Exceptions\CompatibilityException`. On a live write, the caller receives the error, and the application's outermost transaction retries. On a backfill row, the row's savepoint rolls back, and the next pass retries the row.
- The package retries nothing inside its writers, on any path. The application's outermost transaction is the retry point.

A map over ordered successors without a write scope can deadlock against an ordering writer. The package documents that risk and does not detect it.
