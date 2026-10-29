# 0024. Planner support comes from structured attributes

## Context

A mapped attribute that converts its stored column, such as a `timestamp with time zone` column read as a `timestamp without time zone`, is an expression in the compatibility view. PostgreSQL keeps no statistics for such an expression, and no index on the column serves a query on it. An attribute read as its column's own type is not affected: PostgreSQL drops the identity cast, so the column's statistics and indexes serve the view. A spike on a 47 GB local database measured the cost on the Listing and Image maps. Expression statistics took one query, R11, from 48.5 s to 0.57 s. An expression index on the company and the creation time, partial on the deletion time, took another, R4, from 170 ms to 0.8 ms.

Before this decision, a map author wrote each expression. `Attribute::readAs()` stores its text as written, and the compiler wraps it in a cast without reading it. The spike's maps wrote `AT TIME ZONE 'UTC'` themselves. The workbench maps cast a `timestamp with time zone` column straight to a `timestamp without time zone`. That cast depends on the session's time zone, so PostgreSQL treats it as stable and refuses it in an index.

Install runs every statement in one transaction, as [INV-24](../invariants.md) records. `CREATE INDEX CONCURRENTLY`, the way to add an index to a live table, cannot run in one. A plain `CREATE INDEX` holds a `SHARE` lock on the table until the install commits. A recompile builds a new index under the `ACCESS EXCLUSIVE` lock that its `DROP TRIGGER` takes on the table, so reads wait too.

## Decision

The map declares the structure, and the package generates every planner expression from it.

- `Attribute::inTimeZone()` declares the zone of a stored `timestamp with time zone` column that the attribute reads as a `timestamp without time zone`. Only that conversion needs a zone. `Attribute::storedAs()` and `Attribute::inTimeZone()` build the view's read and its write from the stored column, the zone and the declared type. `Attribute::plannerExpression()` builds the planner's expression from the same parts. `Compiler::defaults()` builds the view's default: it converts the stored column's catalog default with the zone, and takes an `Attribute::defaultsTo()` expression as written, so that expression must already be in the zone.
- `PrimaryCurrentModel::indexedBy()` declares `PlannerIndex` values that name attributes of the map. `ResolvedMap` turns each name into the attribute's planner expression, and refuses a name that is no mapped attribute stored on the primary current model.
- The compiler emits one statistics object over the distinct expressions of the zoned attributes of the primary current model's table, at most 8, one `CREATE INDEX` for each declared index and an `ANALYZE`, all `IF NOT EXISTS`.
- `Installer::prepare()` prebuilds them with `CREATE INDEX CONCURRENTLY`, outside a transaction, one statement at a time. The adopter runs it in a migration ahead of the install.
- Install and recompile emit the same statements, so they fill any object the prebuild did not make. An uninstall drops every index and statistics object under the map's prefix.

## Declined

- **Author-written planner text.** An author who writes the index expression a second time can let it drift from the view's. The index then serves no query, and nothing reports that. The structured parts leave one place to say what the view reads.
- **Parsing the author's `readAs()` text.** The package would need to understand SQL to recover the parts, and each new form of SQL would slip past it.
- **Raw statements on the map.** The package would emit nothing, and so would not name the objects with the map's prefix, drop them on an uninstall or check their names against the 63-character limit. [0009](0009-map-names-fit-the-object-name-limit.md) records that limit.
- **Install alone.** Every adopter would build each index inside the install's transaction, with the locks above.
- **Several statistics objects.** PostgreSQL allows at most 8 columns and expressions in one statistics object. Splitting a longer list across several objects would need a naming rule for each, so `ResolvedMap::of()` refuses a map whose zoned attributes give more than 8 distinct expressions instead.
- **Prebuild alone.** A new environment, or a test database, would install a map with no planner support, and nothing would say so.

## Consequences

- `IF NOT EXISTS` matches by name alone. A changed definition under the same name is never rebuilt, and an object removed from the map stays until an uninstall. To change one, rename it.
- A failed `CREATE INDEX CONCURRENTLY` leaves an invalid index, which a later prepare or install skips. The adopter drops it and prepares again.
- `Installer::prepare()` cannot serve a partitioned primary current model's table, because PostgreSQL refuses `CREATE INDEX CONCURRENTLY` there. Install can.
- Install's planner statements hold their locks longer. A recompile and an uninstall hold an `ACCESS EXCLUSIVE` lock on the primary current model's table from their `DROP TRIGGER`, so a recompile that builds an index, and its `ANALYZE`, block reads too. [Adopting a map](../adopting-a-map.md#before-the-install-prebuild-the-planner-objects) says to prebuild, before the install and before a recompile that adds or renames an index.
- An index on an attribute that reads a `timestamp with time zone` column as a `timestamp without time zone` needs `Attribute::inTimeZone()`. Without it, install fails with PostgreSQL's 42P17. An attribute of its column's own type needs no zone.
- `Compiler` checks each zone against `pg_timezone_names` and `pg_timezone_abbrevs` while it lists the statements. An unknown zone would install over an empty table and then fail every write.
- Statistics cover the zoned attributes alone, which are the expressions the spike measured. The package offers no opt-out.
- A map's index and statistics names join its other objects under the prefix, so [INV-9](../invariants.md) covers their discovery. [INV-52](../invariants.md#inv-52-every-planner-expression-comes-from-the-structured-parts-of-the-view-column) records the rule on expressions.
