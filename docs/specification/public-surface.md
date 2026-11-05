Status: Proposed

# Public surface

A consumer may use exactly the classes, methods, commands and interfaces on this page. Every other class under `src/` carries `@internal`, and any release may change it. [0028](../decisions/0028-the-public-surface-is-declared.md) records the decision.

Every class name below is relative to `Support\Database\Eloquent\Compatibility\`. The namespaces of the new classes follow the plan's declared layout, which [open question 1](#1-namespaces-of-the-new-classes) records.

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
| `Contracts\ReportsChanges` | interface | a change source for catch-up |
| `Write\Successors` | class | the unit of work an operation receives |
| `Write\Change` | class | the context of one write |
| `Write\Origin` | enum | whether a write is live or comes from the backfill |
| `Write\Refusal` | exception | refuses one write or one backfill row |
| `Testing\AssertsServedCompatibility` | trait | the assertion that a served model works |

`Definition\Targets` replaces today's `Definition\Successors` builder. `Provides\CarriesLegacy` and `Contracts\ReportsChanges` take their names from the worked examples.

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
| `catchUpAlso(class-string<ReportsChanges> $source)` | a change source for catch-up |
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
| `when(Closure $scope)` | membership: which successor rows the legacy model serves |

### `Definition\Attribute`

| Method | Declares |
| :-- | :-- |
| `from(string $column)` | the successor column the attribute reads |
| `dividedBy()`, `multipliedBy()`, `mapping()`, `isNotNull()`, `orDefault()`, `asType()`, `inTimeZone()`, `fixed()` | the package's conversions, unchanged |
| `readAs(string $sql)` | an SQL expression the view reads |
| `writtenBy(class-string<WritesAttribute> $operation)` | the attribute's operation |
| `readOnly(string $because)` | an attribute no path writes, and the reason |

### `Definition\Targets`

| Method | Declares |
| :-- | :-- |
| `as(class-string<Model> $target, Closure $successor)` | one target class and its definition |

### Removed from today's map

- `retainedIn(key:)`: the successor key moves to `servedFrom(key:)`.
- `writeTo()`, `transitions()` and the write closure of `external()`: `writtenBy()` replaces them.
- `servedFrom(choose:, successors:)`: two-primary maps are retired.
- `identifiedBy()` and `discriminatedBy()`: successor identity columns and the discriminator go away. The plan's work item "Keep legacy identity on the legacy table" settles this. Identity comes from the legacy table, and membership comes from the map's chooser and the successor key.
- `Definition\Successors` and the `successors:` parameter: `Definition\Targets` and `targets:` replace them.

[Open question 3](#3-map-methods-the-session-did-not-name) covers the map methods the session did not name.

## Operations

| Method | Does |
| :-- | :-- |
| `Compatibility::prepare(class-string<Model> $model): void` | adds the successor key's constraints, and lists the objects that depend on the legacy table |
| `Compatibility::install(class-string<Model> $model): void` | proves the install gate, then swaps the table for the view in one transaction |
| `Compatibility::rollback(class-string<Model> $model): void` | reverses an install, and makes the legacy table the source of truth again |
| `Compatibility::retire(class-string<Model> $model): void` | drops the view and the retained table once a legacy model has no readers |
| `Compatibility::serves(Model $model): bool` | answers whether the legacy name is the installed view on the model's connection |

`prepare()`, `install()`, `rollback()` and `retire()` are idempotent, as the plan's work item "Keep legacy identity on the legacy table" settles. [Lifecycle](lifecycle.md) states what each one runs.

`Compatibility::rollback()` replaces `Compatibility::uninstall()`. The package has no public `Compatibility::state()`.

`Compatibility::serves()` is for transitional application code that bypasses the legacy model. Its answer can change around an install. It is stable only inside a transaction, so never cache it across requests.

## Commands

| Command | Does |
| :-- | :-- |
| `compatibility:backfill {model} {--chunk=}` | runs one backfill pass, prints counts by status and reason, and exits non-zero when any row is refused or failed |
| `compatibility:check {--model=*} {--limit=}` | reads only, and reports legacy rows without a successor, values that differ, shared successors that serve two legacy records, and trashed rows separately |
| `compatibility:sql {model} {--for=} {--filters} {--output=}` | prints the statements of an operation and every object that depends on the legacy table |
| `compatibility:status` | prints the stage of each legacy model |
| `compatibility:cache` | discovers the served models and caches the list |
| `compatibility:clear` | removes the cached list |

`compatibility:sql --for=install` also prints each lock install takes, with its mode and table. `compatibility:sql --filters` prints, for each attribute, whether a filter runs on a stored column, on a rebuilt expression, or on the view.

The commands are public by name. Their classes are internal.

## Testing

`AssertsServedCompatibility::assertServesCompatibility(class-string<Model> $model)` fails a test when:

- discovery does not find the model,
- the map does not compile,
- the installed view differs from the view the map compiles,
- a native create of the root successor fails, or
- a legacy-only NOT NULL column has neither a default nor an initializer.

`Compatibility::install()` may run inside a caller's transaction when no catch-up is pending. A test that uses `RefreshDatabase` can therefore install a served model.

## Interfaces and their arguments

```php
interface WritesAttribute
{
    public function write(Successors $successors, mixed $value, Change $change): void;
}

interface InitializesSuccessors
{
    public function initialize(Successors $successors, Change $change): void;
}

interface InitializesLegacy
{
    public function initialize(Model $legacy, Successors $successors, Change $change): void;
}

interface ScopesWrites
{
    public function run(array $successors, Closure $write): mixed;
}

interface ManagesDependents
{
    public function beforeSwap(Connection $connection): void;

    public function afterSwap(Connection $connection): void;

    public function beforeRollback(Connection $connection): void;

    public function afterRollback(Connection $connection): void;
}

interface ReportsChanges
{
    public function since(CarbonImmutable $watermark): iterable;
}
```

- `ScopesWrites::run()` receives `array<class-string<Model>, list<int|string>>`: the keys of the successors the write touches, by class. It returns what `$write` returns. The package reads those keys without a lock. Inside `$write`, it locks the successors and checks that their keys equal the keys `run()` received. A difference refuses the write with a retryable error, and the application's outermost transaction retries. [Operations](operations.md#the-write-scope) states the check.
- `ReportsChanges::since()` returns the legacy keys whose served values changed through other tables since the watermark.
- `Write\Successors` is generic over the root class, so `Successors<Job>::root()` returns a `Job`. [Operations](operations.md#successors) states its methods.
- `Write\Change` carries `record`, `original`, `attribute` and `origin`. [Operations](operations.md#the-change-context) states each one.
- `Write\Origin` has two cases, `Origin::Live` and `Origin::Backfill`.
- `Write\Refusal::because(string $reason)` builds a refusal that an operation or initializer throws.

## Discovery

The package discovers served models from the Composer classmap. It classifies a classmap entry by its real path, so an optimized classmap entry such as `vendor/composer/../../app/Listings/Models/Listing.php` counts as application code. An application no longer rewrites its classmap before discovery runs.

## Internal

Everything else is internal. That includes the conversion classes, `Builder\LegacyBuilder`, the writers, the schema classes, the console command classes, `Provider`, the PHPStan rules under `Tooling\`, and the exceptions.

On `Compatibility`, the methods outside the tables above carry `@internal`: `of()`, `resolve()`, `successors()` and `connection()`.

## Open questions

### 1. Namespaces of the new classes

The plan settled this layout. Its work item "Turn legacy writes into successor operations" declares the operation arguments as `Write\Successors`, `Write\Change`, `Write\Origin` and `Write\Refusal`, beside the writers. The same work item puts the interfaces in `Contracts\`, and the successor trait in `Provides\`. The work item "Keep legacy identity on the legacy table" puts `Targets` in `Definition\`.

A different namespace is a deviation from the plan. It needs a plan edit before this page changes.

### 2. Exceptions on the surface

Decision 11 names no exception class. A caller that wants to catch a refusal then has no supported type.

- **A.** Keep every exception internal. A caller catches `RuntimeException`.
- **B.** Publish `Exceptions\CompatibilityException` as the one public base. `Write\Refusal` and every package refusal extend it. The concrete classes stay internal.

**Recommendation: B.** It gives a caller one supported type to catch, and it publishes one class.

### 3. Map methods the session did not name

Three methods of today's map appear in no decision. The plan removes `identifiedBy()` and `discriminatedBy()`, as [removed from today's map](#removed-from-todays-map) states.

- `timestamps()` declares `created_at` and `updated_at` as attributes of the root. The worked Listing map declares them as plain attributes. **Recommendation:** keep it as a shortcut for those two attributes.
- `atMostOne(because:)` refuses a second related row on a write. Decision 10 compiles a plain join only where the database proves uniqueness. **Recommendation:** keep it for writes. The view still uses a lateral join unless a unique index proves the relation.
- `external()` reads a value stored outside the database. Decision 5 replaces only its write closure. **Recommendation:** keep `external()` with its read closure alone.

### 4. `Compatibility::recompile()`

The operations list omits `recompile()`. A map change after install still needs it, and `assertServesCompatibility()` tells the developer to run it.

- **A.** Keep it public.
- **B.** Make it internal, and recompile inside `install()` when the legacy model is already installed.

**Recommendation: A.** A migration that ships a map change calls it today, and B changes what an idempotent `install()` does.

### 5. Parameter names

PHP named arguments make parameter names part of a signature. The worked examples pass `key:`, `successor:`, `choose:`, `targets:` and `because:` by name.

- **A.** Those names are public. Every other parameter is positional, and its name may change.
- **B.** Every parameter name on this page is public.

**Recommendation: A.** It publishes the names the examples use, and nothing else.

### 6. The argument of `Compatibility::serves()`

The session wrote `serves($model)`. Today's `ServedTable::serves()` takes a model instance.

- **A.** A model instance. It carries a non-default connection.
- **B.** A class string, like the other operations.

**Recommendation: A.** The answer depends on the connection, and only an instance carries a non-default one.

### 7. Command options

- `compatibility:backfill --since=` takes a watermark today. `compatibility_backfills` now stores the watermark. **Recommendation:** remove `--since`.
- `compatibility:sql --for=` takes `prepare`, `install`, `recompile` or `uninstall` today. **Recommendation:** accept `prepare`, `install`, `recompile`, `rollback` and `retire`.
- `compatibility:status` has no settled arguments. **Recommendation:** print every discovered legacy model, and accept `--model=*` as `compatibility:check` does.
