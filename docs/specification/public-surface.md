Status: Accepted

# Public surface

A consumer may use exactly the classes, methods, commands and interfaces on this page. Every other class the package ships carries `@internal`, and any release may change it. The package ships no `*Test.php` or `*TestCases.php` file under `src/`, because `.gitattributes` export-ignores them. [0028](../decisions/0028-the-public-surface-is-declared.md) records the decision.

Every class name below is relative to `Support\Database\Eloquent\Compatibility\`. The new classes follow this layout. The operation arguments `Write\Successors`, `Write\Change`, `Write\Creation`, `Write\Origin` and `Write\Refusal` sit beside the writers. The interfaces sit in `Contracts\`, the successor trait in `Provides\`, `Targets` in `Definition\`, and the event `WriteIgnored` in `Events\`. A different namespace is a deviation under [Deviations](README.md#deviations).

Every parameter of a public constructor, function or method on this page is public, named and stable. A release may not rename one. The worked examples name every argument of a call that takes more than one value, and a variadic unpack stays positional.

## Classes

| Class | Kind | Role |
| :-- | :-- | :-- |
| `Compatibility` | class | the map a legacy model declares, and the operations |
| `Contracts\Compatible` | interface | a legacy model that declares a map |
| `Provides\ServesCompatibility` | trait | gives a legacy model its builder, its attributes and its events |
| `Provides\CarriesLegacy` | trait | inserts the legacy row when a successor is created natively |
| `Definition\Successor` | class | declares one successor's attributes, relations and membership |
| `Definition\Attribute` | class | declares one attribute's read and its operation |
| `Definition\Targets` | class | declares the targets that a `relatedMorph()` chooses between |
| `Contracts\WritesAttribute` | interface | an attribute operation |
| `Contracts\InitializesSuccessors` | interface | sets successor-only values when the package creates a successor |
| `Contracts\InitializesLegacy` | interface | sets legacy-only values when a successor is created natively |
| `Contracts\ScopesWrites` | interface | the write scope |
| `Contracts\ManagesDependents` | interface | dependent schema around install and rollback |
| `Write\Successors` | class | the unit of work an operation receives |
| `Write\Change` | class | the context of one write |
| `Write\Creation` | class | the context an initializer receives |
| `Write\Origin` | enum | whether a write is live or comes from the backfill |
| `Write\Refusal` | exception | refuses one write or one backfill row, and extends `Exceptions\CompatibilityException` |
| `Events\WriteIgnored` | event | a discarded non-NULL write to a `readOnly()` attribute declared with `ignoreWrites: true`; its constructor is `__construct(Change $change, mixed $value)` |
| `Exceptions\CompatibilityException` | abstract class | the one public base of every package exception |
| `Exceptions\SuccessorsMoved` | exception | the retryable refusal of a write whose successor keys moved after the write scope ran; the application's outermost transaction retries on it, and it extends `Exceptions\CompatibilityException` |
| `Testing\AssertsServedCompatibility` | trait | the assertion that a served model works |

`Exceptions\CompatibilityException` exists today as `abstract class CompatibilityException extends RuntimeException`. `Write\Refusal` and every package refusal extend it, so a caller catches every package exception by one supported type. Beside `Write\Refusal`, `Exceptions\SuccessorsMoved` is the one public concrete exception, because the application retries on it. Every other concrete exception class stays `@internal`.

`Definition\Targets` replaces the removed `Definition\Successors` builder. `Provides\CarriesLegacy` takes its name from the worked examples.

## The map

### `Compatibility`

| Method | Declares |
| :-- | :-- |
| `retainedIn(string $table)` | the name the legacy table takes at install |
| `servedFrom(class-string<Model> $root, string $key, Closure $successor)` | the root successor, the successor key column on the legacy table, and the root's definition |
| `successorsInitializedBy(class-string<InitializesSuccessors> $initializer)` | the class that sets successor-only values at birth |
| `legacyInitializedBy(class-string<InitializesLegacy> $initializer)` | the class that sets legacy-only values on a native successor create |
| `writesWithin(class-string<ScopesWrites> $scope)` | the write scope |
| `dependents(class-string<ManagesDependents> $dependents)` | the class that manages dependent schema |
| `hide(Closure $scope, string $because)` | records the legacy model never shows |
| `guard(string $name, Closure $when, string $because, string $instead)` | a legacy write the map refuses |
| `withoutForwardingTo(string $listener, string $because)` | a legacy listener that forwarded successor events never reach |

Every method returns the `Compatibility` it was called on.

### `Definition\Successor`

| Method | Declares |
| :-- | :-- |
| `attribute(string $name, Closure $definition)` | one attribute, over a `Definition\Attribute` |
| `related(Closure $relation, null\|Closure $successor = null)` | a related successor: the closure returns its Eloquent relation, and `successor:` defines it |
| `relatedMorph(Closure $relation, Closure $choose, Closure $targets)` | a `MorphTo`, the closure that picks its target class, and the targets over a `Definition\Targets` |
| `atMostOne(string $because)` | a write refuses a second related row, with the reason; once `prepare()` builds its unique index, the view joins the relation plainly while that index matches the relation's conditions |
| `when(Closure $scope)` | membership: which natively created successor rows gain a legacy row |

### `Definition\Attribute`

| Method | Declares |
| :-- | :-- |
| `from(string $column)` | the successor column the attribute reads |
| `dividedBy()`, `multipliedBy()`, `mapping()`, `isNotNull()`, `orDefault()`, `asType()`, `inTimeZone()`, `fixed()` | the package's conversions, unchanged |
| `orFrom(class-string<Model> $successor, string $column)` | reads `COALESCE(<the attribute's column>, <that related successor's column>)`; the map refuses a class it does not relate |
| `position()` | waits on [eloquent-ordering#14](https://github.com/AryeoHQ/eloquent-ordering/issues/14) ([Status](README.md#status)); reads the row's one-based position within the successor's eloquent-ordering parent, through eloquent-ordering's published position expression. After install, a write moves the row with `Placement::at()`. A backfill row or catch-up row re-sequences its parent in legacy order ([Operations: positions](operations.md#positions)) |
| `readAs(string $sql)` | an SQL expression the view reads: the escape hatch for a projection the helpers do not cover; it names internal join aliases, where a helper names the successor by class |
| `writtenBy(class-string<WritesAttribute> $operation)` | the attribute's operation |
| `readOnly(string $because, bool $ignoreWrites)` | an attribute no write changes, and the reason; `$ignoreWrites` has no default: `false` refuses an update, and `true` discards the write and dispatches `Events\WriteIgnored` ([Operations: read-only attributes](operations.md#read-only-attributes)) |
| `computedAs(Closure $read)` | a value computed in PHP after the query, from an outside store such as ZML or from a successor model method such as a File accessor. `$read` is `Closure(Collection<array-key, Model>): array<array-key, mixed>`: it receives the successors the attribute sits under, once per query, and returns their values keyed by successor key. The value cannot be filtered or sorted in SQL. It has no inverse, so `writtenBy()` or `readOnly()` must name its operation |

Declined: a separate `ignoresWrites()` method beside `readOnly()`. Declined: counting writes since `prepare()` to gate install, because the legacy column is live storage until install, so that count never reaches zero ([0027](../decisions/0027-the-view-is-driven-from-the-retained-table.md)).

### `Definition\Targets`

| Method | Declares |
| :-- | :-- |
| `as(class-string<Model> $target, Closure $successor)` | one target class and its definition |

<a id="removed-from-todays-map"></a>

### Removed from the earlier map

- `retainedIn(key:)`: the successor key moves to `servedFrom(key:)`.
- `writeTo()`, `transitions()` and the write closure of an external attribute: `writtenBy()` replaces them.
- The external attribute's read: `computedAs()` replaces it, with the same `read` closure ([0028](../decisions/0028-the-public-surface-is-declared.md)).
- `servedFrom(choose:, successors:)`: two-primary maps are retired.
- `identifiedBy()` and `discriminatedBy()`: successor identity columns and the discriminator go away. Identity comes from the legacy table, and membership comes from the map's chooser and the successor key.
- `timestamps()`: every worked map declares `created_at` and `updated_at` as plain attributes. Declined: keeping `timestamps()` as a shortcut.
- `Definition\Successors` and the `successors:` parameter: `Definition\Targets` and `targets:` replace them.

## Operations

| Method | Does |
| :-- | :-- |
| `Compatibility::prepare(class-string<Model> $model): void` | adds the successor key's unique constraint, creates the backfill tables, inserts the model's `compatibility_backfills` row, and lists the objects that depend on the legacy table |
| `Compatibility::install(class-string<Model> $model, CarbonInterval $statementTimeout, int $passSize = 1000, null\|CarbonInterval $margin = null): void` | runs passes until one changes the outcome of fewer rows than `$passSize`, proves the install gate, then swaps the table for the view in one transaction; `$statementTimeout` sets `statement_timeout` for every statement under install's lock, and is the total budget for the lock hold, after which install rolls back and refuses, naming the step it reached; a null `$margin` means 5 minutes |
| `Compatibility::rollback(class-string<Model> $model): void` | reverses an install, and makes the legacy table the source of truth again |
| `Compatibility::retire(class-string<Model> $model): void` | drops the view, the retained table and `<legacy>_backfill` once a legacy model has no readers; refuses while any object depends on either |
| `Compatibility::update(class-string<Model> $model): void` | rebuilds an installed view after a map change |
| `Compatibility::serves(class-string<Model> $model): bool` | answers whether the legacy name is the installed view on the model's connection |

`prepare()`, `install()`, `rollback()` and `retire()` are idempotent. [Lifecycle](lifecycle.md) states what each one runs.

`Compatibility::rollback()` replaces `Compatibility::uninstall()`. The package has no public `Compatibility::state()`.

`Compatibility::update()` replaces `recompile()` under a new name, and stays public. A migration that ships a map change after install calls it. A migration that drops or replaces an index that proves a plain join calls it too, in the same migration. It refuses while an `atMostOne()` index differs from the index `prepare()` builds for the changed conditions. The refusal names the index, and says to drop it and run `prepare()`.

`Compatibility::serves()` is for transitional application code that bypasses the legacy model. It takes a class string and constructs the model internally to resolve its connection and table, as every other operation does. A connection or table set on one instance at runtime is not supported, as for every other operation. Declined: taking a model instance. Its answer can change around an install. It is stable only inside a transaction, so never cache it across requests.

## Commands

| Command | Does |
| :-- | :-- |
| `compatibility:backfill {model} {--chunk=1000} {--margin=300}` | runs one backfill pass, prints each chunk's key range and counts, then the counts by status and the reasons with their first keys, and exits non-zero when any row is refused or failed |
| `compatibility:check {--model=*} {--limit=100}` | reads only, and reports legacy rows without a successor, values that differ, shared successors that serve two legacy records, served records whose root no longer matches the map's `when()`, member roots that no retained row names after install, and trashed rows separately |
| `compatibility:sql {operation} {model} {--filters} {--output=}` | prints the statements of an operation and every object that depends on the legacy table, for review; it refuses no install because of a watermark |
| `compatibility:status {--model=*}` | prints the stage of every discovered legacy model, or of the models `--model` names; a backfilling model also shows its pass count, when that pass started and finished, its watermark, and its refused and failed counts |
| `compatibility:cache` | discovers the served models and caches the list |
| `compatibility:clear` | removes the cached list |

`compatibility:backfill` takes no `--since`, because `compatibility_backfills` stores the watermark. `--margin` is in seconds. The command takes no pass size, because a pass size only sets `install()`'s stop condition, and the command runs one pass.

The package has no configuration of its own. Each tuning value is a named argument of the operation that uses it. `install()` requires `statementTimeout:`, because the hold budget depends on each application's tolerance. Declined: package-wide configuration keys.

`compatibility:sql` takes the operation as its first positional argument: `prepare`, `install`, `update`, `rollback` or `retire`. It refuses an unknown operation. The `--for=` option is removed.

`compatibility:sql install` also prints each lock install takes, with its mode and table. Its header says that `install()` runs backfill passes before these statements, that the printed SQL omits them, and that running the SQL by hand can drop legacy edits that no pass has written. It also says that the printed SQL omits the legacy rows install inserts in PHP under its lock for natively created roots. `compatibility:sql --filters` prints, for each attribute, whether a filter runs on a stored column, on a rebuilt expression, or on the view. A stored-column filter falls back to the view where its conversion cannot invert the operator. For an attribute of a related successor, the command names the lateral join that reaches it, or the parent columns that the plain joins traverse and the indexes that can serve the filter. It claims no index where the view casts the column or reads an `orFrom()` fallback. A `computedAs()` attribute has no SQL filter.

The commands are public by name. Their classes are internal.

## Testing

`AssertsServedCompatibility::assertServesCompatibility(class-string<Model> $model, Closure $nativeCreate)` fails a test when:

- discovery does not find the model,
- the map does not compile,
- the installed view differs from the view the map compiles, and the message tells the developer to run `Compatibility::update()`; `compatibility:check` also names each relation whose join changes between plain and lateral,
- `$nativeCreate` fails, inserts no retained row of `$model`, or creates a root that also matches the `when()` of another discovered map with the same root class,
- a membership query of `compatibility:check` finds a row: a served root outside its map's `when()`, or a shared successor that serves two legacy records, or
- a legacy-only NOT NULL column has neither a default nor an initializer.

`$nativeCreate` creates one member root natively, such as `nativeCreate: fn () => PlaceAsset::factory()->floorPlan()->create()`. The assertion runs it after an in-transaction install. A create outside `when()` inserts nothing, so the assertion catches a discriminator that the consumer's `creating` listener leaves unset. The membership queries run against the test database, so a test that seeds a legacy row catches an initializer that leaves the discriminator unset: the assertion's first install runs the full pass inside the test's transaction.

`Compatibility::install()` may run inside a caller's transaction when no catch-up is pending. A first install, which no pass preceded, runs its full pass inside that transaction. A test that uses `RefreshDatabase` can therefore install a served model.

## Interfaces and their arguments

```php
interface WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void;
}

interface InitializesSuccessors
{
    public function initialize(Successors $successors, Creation $creation): void;
}

interface InitializesLegacy
{
    public function initialize(Successors $successors, Creation $creation): void;
}

interface ScopesWrites
{
    public function run(array $successors, Closure $write): mixed;
}

interface ManagesDependents
{
    public function beforeInstall(Connection $connection): void;

    public function afterInstall(Connection $connection): void;

    public function beforeRollback(Connection $connection): void;

    public function afterRollback(Connection $connection): void;
}
```

- `ScopesWrites::run()` receives `array<class-string<Model>, list<int|string>>`: the keys of the successors the write touches, by class. A successor the write creates appears under its class with an empty list. It returns what `$write` returns. The package opens its transaction first, and calls the scope inside it, so the locks the scope takes hold until the package's work commits. The scope needs no transaction of its own. The package reads those keys without a lock. Inside `$write`, it locks the successors and checks that their keys equal the keys `run()` received. A difference refuses the write with the retryable `Exceptions\SuccessorsMoved`, and the application's outermost transaction retries. [Operations](operations.md#the-write-scope) states the check.
- Install's final catch-up pass and rollback's refill never call `ScopesWrites::run()`, and run no key re-check. Their table lock serializes them against live writes. [Operations](operations.md#the-write-scope) states why.
- `Write\Successors` is generic over the root class, so `Successors<Job>::root()` returns a `Job`. The three hook interfaces take the root class as their template, so an operation for a Job root declares `@implements WritesAttribute<Job>`. `related()` returns `null` for an absent row. [Operations](operations.md#successors) states its methods.
- `Write\Change` carries `record`, `original`, `attribute` and `origin`. `Write\Creation` carries `record` and `origin`. On a native create, `Creation::$record` is the new legacy model, so `InitializesLegacy::initialize()` takes no separate legacy parameter. [Operations](operations.md#the-change-context) states each one.
- `Write\Origin` has two cases, `Origin::Live` and `Origin::Backfill`.
- `Write\Refusal::because(string $reason)` builds a refusal that an operation or initializer throws.

## Discovery

The package discovers served models from the Composer classmap. It classifies a classmap entry by its real path, so an optimized classmap entry such as `vendor/composer/../../app/Entities/Listings/Listing.php` counts as application code. An application no longer rewrites its classmap before discovery runs.

## Internal

Everything else is internal. That includes the conversion classes, `Builder\LegacyBuilder`, the writers, the schema classes, the console command classes, `Provider`, the PHPStan rules under `Tooling\`, and every concrete exception class except `Exceptions\SuccessorsMoved`.

On `Compatibility`, the methods outside the tables above carry `@internal`: `of()`, `resolve()`, `successors()` and `connection()`.

Every public method on a published `Definition\` class outside the map tables carries `@internal` too. That covers `Definition\Successor`, `Definition\Attribute` and `Definition\Targets`, with methods such as `attributes()`, `relations()`, `read()`, `write()` and `filter()`. The surface's verification checks each public method of a published class, not only each class.
