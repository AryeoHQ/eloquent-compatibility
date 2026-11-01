# 0025. A map is declared through the models' relations

## Context

After [0024](0024-writes-are-split-in-php-without-triggers.md), a map still described tables. Each current model took an alias, a primary foreign key and an SQL readable condition, and each mapped attribute took a type and an SQL read. Most of that restated what the application's models already know:

- A relation knows its tables, its foreign keys, which row it reads (its constraints and order), and what a created row needs (`withAttributes()`).
- A cast knows how a value converts.
- The catalog knows each column's type.

The user asked on 2026-11-01 to rethink the public interface from first principles, writing code that works with the models instead of SQL. They then chose the design and asked for it to be built.

## Decision

- **The previous model declares its map.** It implements `DefinesCompatibility` and returns a `Compatibility` builder from its static `compatibility()` method. `Installer` and `WriteRouter::register()` take the model class.
- **Attributes are relation paths from the retained model.** `Compatibility::attributes()` takes `column`, `relation.column` or `relation.relation.column`. `RelationCompiler` reads the first hop as a `BelongsTo` to the primary current model and the second as a `HasOne` of it. It compiles the `HasOne`'s constraints, its `SoftDeletes` scope and its order into the readable condition, and its `withAttributes()` into the fills of a created row. Each alias is the table's own name, so the qualified columns Eloquent compiles resolve in the view.
- **Registration still reads no database.** `SqlFragments::inline()` writes the bindings as PostgreSQL literals itself, because Laravel's `toRawSql()` escapes through PDO, which would connect. [0011](0011-registration-needs-no-database.md) holds.
- **Types come from the catalog.** A stored attribute without `Attribute::asType()` takes its column's type, so the view leaves it uncast.
- **Closures receive models.** `Compatibility::creating()`, `Compatibility::createWhen()`, `Compatibility::oneForEach()`, `Compatibility::guard()` and `Compatibility::refuse()` hand the closure hydrated models, not raw attribute arrays.
- **Rules are PHP.** A refused state can be a predicate over the written row's model (`UnrepresentableState::checkedBy()`), checked after each write inside the router's transaction. The drift check walks the rows its candidates scope selects. `Compatibility::hide()` takes an Eloquent scope over the retained model.
- **Conversions are casts, and queries translate.** The view stores what the current models store. `RoutesCompatibleWrites` translates where, order, aggregate and increment values on an attribute whose cast implements `QueryableCast`, and applies a builder's `filterTranslations()` to an attribute no cast can translate.
- **The hand-written `CompatibilityMap` stays.** `Compatibility::toMap()` compiles into it, and a map that needs an SQL read still implements it directly.

## Consequences

- **Relations become schema.** Changing `Job::rootPlace()` changes the view the map compiles to, and only `Installer::recompile()` changes the installed one. `compatibility:check` compares the two and reports `DriftCheck::ViewDefinition`.
- **A related model's global scope must not reach the view.** A scope that reads application state, such as a tenant's id, would be frozen at install time. `RelationCompiler` refuses every global scope but `SoftDeletingScope`.
- **Only one-row relations map.** A `HasMany` path refuses at registration, and so does an "of many" relation, which joins.
- **A creation hook or a closure fill hides its columns from the compile-time check.** `Compiler::compile()` skips the `NOT NULL` check for a table a hook fills, and leaves it to the database.
- **Raw SQL reads compare stored values.** The builder translates queries, but `whereRaw()` and a DB::table('listings') query reach the view directly and compare cents with dollars without an error. [Adopting a map](../adopting-a-map.md#raw-sql-reads-compare-stored-values) lists what to audit.
- **One table, one path.** Two relations to one table would share an alias, so the second refuses.
