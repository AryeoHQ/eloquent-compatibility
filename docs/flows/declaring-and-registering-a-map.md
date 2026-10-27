# Declaring and registering a map

This flow follows a compatibility map from its class to a `ResolvedMap` that the schema and the event bridge can use. The example is the workbench map, [WidgetCompatibilityMap](../../tests/Fixtures/Support/Workbench/WidgetCompatibilityMap.php).

```mermaid
flowchart LR
  Map["WidgetCompatibilityMap"] -->|"ResolvedMap::of()"| Resolved["ResolvedMap"]
  Installer -->|"ResolvedMap::of()"| Resolved
  Mirror["EventBridge::mirror()"] -->|"register()"| Registry["CompatibilityMaps"]
  Registry -->|"ResolvedMap::of()"| Resolved
  Mirror -->|"add()"| Index["MirrorIndex"]
```

## The declaration

A map implements `CompatibilityMap`, whose six methods return builders. Every builder is a `final readonly` class: each call returns a new instance, and nothing mutates.

- `PreviousModel::servedAsView()` names the compatibility view. `PreviousModel::retainedInTable()`, `PreviousModel::forModel()`, `PreviousModel::aliasedAs()` and `PreviousModel::primaryForeignKey()` are required. `PreviousModel::keyedBy()` is optional; without it the key is the model's own key name.
- `PrimaryCurrentModel::for()` and `DependentCurrentModel::for()` take the model class. Each needs an alias, and a dependent needs `DependentCurrentModel::primaryForeignKey()`.
- `PreviousModel::forModel()`, `PrimaryCurrentModel::for()` and `DependentCurrentModel::for()` store the name that `ModelClass::canonical()` returns. For a class that loads, that is its declared name, so a leading backslash or another letter case names the class as Eloquent's events do. A name that loads no class stays as written, and `ResolvedMap::of()` refuses it.
- `DependentCurrentModel::readableWhen()` defaults to `true`. `DependentCurrentModel::createdWhen()` and `DependentCurrentModel::designatedBy()` are optional.
- `Attribute::retained()` keeps a column in the retained table. `Attribute::mapped()` stores it in a current model's table and needs `Attribute::asType()`.
- `UnrepresentableState::refuse()`, `UnrepresentableState::degrade()` and `UnrepresentableState::hide()` declare the states one side holds and the other cannot.

`Attribute` orders its calls. `Attribute::storedAs()` sets the read and one `TargetColumn` together. `Attribute::readAs()` and `Attribute::readOnlyBecause()` clear the writes, so `Attribute::writesTo()` must follow `Attribute::readAs()`. An insert through the view still stores a read-only attribute's `storedAs()` column, as [INV-49](../invariants.md) records. The workbench `price` attribute shows that order.

## Resolving

`ResolvedMap::of()` turns the declaration into a checked, immutable `ResolvedMap`. It needs no database. It checks, in order:

1. The name has at most 24 lowercase letters, digits and single underscores, and does not end in an underscore.
2. The previous model made every required call. `ResolvedMap::previous()` fills a missing key from the model's key name.
3. Each current model is an Eloquent model with a unique alias, and its class appears once among the map's current models. `ResolvedMap::of()` compares the class names of the instances it creates, so two spellings of one class count as one class. A fresh instance of each model gives its table and key, held as an `AliasedTable`.
4. Each dependent declares its primary foreign key.
5. Each alias is a lowercase identifier other than `new` and `old`, and not one of `ReservedWords::POSTGRESQL`. It names no variable the triggers declare: not one of `ResolvedMap::VARIABLES`, and nothing that starts with `key_`.
6. `Attribute::resolve()` points each retained attribute at the previous model's alias. A retained attribute that is not read-only writes its own column.
7. `ResolvedMap::validateAttributes()` checks each attribute: a unique identifier, `Attribute::asType()` on a mapped attribute, a read on a known alias, and a write or a read-only reason. Each stored column has one owner. Two read-only attributes stored in one column need a writable owner, and the previous model's key and primary foreign key are attributes.
8. `ResolvedMap::validateUnrepresentableStates()` checks each state: a unique identifier, a check on every refused state, and a known alias.

Every failure throws `InvalidCompatibilityMap`, whose message names the map. Checks that need the catalog wait for compile time; [compiling and installing the schema](compiling-and-installing-the-schema.md) lists them.

## Registering

Three callers resolve a map, and each resolves it again:

- `Installer` calls `ResolvedMap::of()` in each of its methods, and never reads the registry.
- `CompatibilityMaps::register()` resolves a map and stores it by name. It refuses a second map with the same name and a different view. A map with the same name and the same view replaces the stored one.
- `EventBridge::mirror()` resolves the map that `EventMirror::compatibilityMap()` returns, runs its own checks, and only then calls `CompatibilityMaps::register()` with it. A refused mirror leaves the registry as it was.

`EventBridge::mirror()` refuses a second event mirror for one map name. It also refuses a map that names a class using Parental's HasChildren trait, found by name through `class_uses_recursive()`. A Parental child fires its events under its own class name, which the bridge would never match; [INV-43](../invariants.md) records the rule. The first call also listens for `QueryExecuted`. `MirrorIndex::add()` then indexes the map three ways: by model class, by current table and by relation. It returns the model classes it has not seen before. `EventBridge::listen()` attaches the beginning and finishing listeners to each, as [mirroring a write](mirroring-a-write.md) describes.

`Provider` binds `CompatibilityMaps` and `EventBridge` as singletons. A map reaches `compatibility:check` only through `CompatibilityMaps::register()`, directly or through `EventBridge::mirror()`.

## Tests

`ResolvedMapTest` covers the registration refusals. Most of its tests call `ResolvedMap::of()` directly. `ResolvedMapTest::it_refuses_a_mapped_attribute_without_a_type_when_the_map_is_registered()` goes through `CompatibilityMaps::register()`, and `ResolvedMapTest::it_refuses_an_attribute_the_tables_cannot_store()` compiles. `ResolvedMapTest::it_refuses_two_current_models_of_one_class()` covers a repeated current model class, and `ResolvedMapTest::it_refuses_a_current_model_class_that_does_not_load()` a name that loads no class. `ModelClassTest` covers `ModelClass::canonical()`, and `EventBridgeTest::it_mirrors_the_writes_of_models_its_map_names_with_a_leading_backslash_or_in_another_letter_case()` covers a write through such a map. `ResolvedMapTest::it_refuses_an_alias_that_names_a_variable_the_compiled_triggers_declare()` covers the variable names. `EventBridgeTest::it_refuses_to_mirror_a_map_that_names_a_model_whose_children_fire_events_under_their_own_classes()` covers the Parental refusal, and `EventBridgeTest::it_keeps_the_mirrored_map_registered_when_a_second_mirror_of_its_name_is_refused()` the repeated-mirror refusal. Both assert that the refusal leaves the registry as it was. `CompatibilityMapsTest` covers `CompatibilityMaps::register()` and its refusal of a second map with the same name. `ProviderTest::it_shares_one_set_of_compatibility_maps_and_one_event_bridge_across_the_application()` covers the singletons.
