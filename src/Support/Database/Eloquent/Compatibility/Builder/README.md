# Builder

`LegacyBuilder` is the Eloquent builder of every served legacy model. `ServesCompatibility::newEloquentBuilder()` returns it. It keeps legacy filters fast once the legacy table becomes a view. It never changes which rows a query returns.

## Why filters are translated

After install, the legacy table name is a read-only view over the successors. The view serves a converted attribute as an expression over a stored column. The Listing fixture serves `price` as `jobs.price_cents / 100`.

A filter on that expression returns the right rows. But PostgreSQL cannot use an index on `jobs.price_cents` for it, so a large table needs a sequential scan. An attribute with no conversion needs no help. PostgreSQL inlines the view, so a filter on a renamed column already uses the stored column's index.

`LegacyBuilder` therefore rewrites a filter on a converted attribute onto the stored column, before Laravel compiles the query. `LegacyBuilderReadTest::it_filters_a_converted_attribute_on_its_stored_column_once_installed` pins the result:

```php
Listing::query()
    ->where('price', '>', 100)
    ->whereNot(fn (Builder $listings) => $listings->where('status', '!=', ListingStatus::Sold));
```

```sql
select * from "listings"
where "listings"."id" in (select "jobs"."legacy_id" from "jobs" where ("jobs"."price_cents" > 10000))
  and not ("listings"."id" not in (select "jobs"."legacy_id" from "jobs" where ("jobs"."lifecycle_status" = 'closed')))
  and "listings"."deleted_at" is null
```

Each translated filter becomes a membership test on the legacy key. Its subquery reads the successor that stores the column. A related successor's column is reached from the primary table through the view's own lateral joins.

## What it intercepts

| Methods | What happens to a converted attribute |
| :-- | :-- |
| `where` and its `or`/`not` forms, `whereIn`, `whereBetween`, `whereNull` | Translated, or filtered on the view when no translation exists |
| `orderBy`, `orderByDesc`, `latest`, `oldest` | Sorted on the view; refused when the attribute is stored outside the database |
| `min`, `max`, `sum`, `avg`, `average` | Aggregated on the view; refused when the attribute is stored outside the database |

Everything else passes through to Laravel unchanged. So does every query before install, while the legacy name is still a table. Eloquent forwards most of these methods straight to the query builder, so an override is the only place to intercept them.

## Which filters translate

Each conversion inverts a filter through `Conversion::filter()`. `Attribute::filter()` applies the conversions in reverse order. One conversion with no inverse leaves the whole filter on the view.

| Conversion | Operators it inverts |
| :-- | :-- |
| `dividedBy`, `multipliedBy` | `=`, `!=`, `<`, `>`, `<=`, `>=` |
| `mapping` | `=` and `!=` against a mapped value; `!=` becomes `in` the other stored keys |
| `isNotNull` | `=` and `!=` against a boolean or `null` |
| `orDefault` | `=` and `!=` |
| `inTimeZone` | `=`, `!=`, `<`, `>`, `<=`, `>=` against a date or a wall-clock string |
| `readAs`, `fixed` | None |

`asType()` has no inverse either, but it is never exact, so its filters take the rebuilt-expression path under `## Exactness`.

## When a filter stays on the view

A filter stays on the view, which is correct but slow, in these cases:

- **No inverse exists.** Examples are `like` on a mapping, a value no mapping declares, and any `readAs()` attribute.
- **A range has a NULL bound.** SQL returns unknown for `price > null`, and the view keeps that result.
- **The role cannot read the successors.** It lacks `SELECT` on a successor, or row-level security is active there. The subquery would then fail, or filter different rows than the view.

## Exactness

Comparing the stored column must return exactly what comparing the served value returns. `served()` checks the stored and served types and both collations through `Conversion::exact()`. A conversion may round or cast, or a collation may compare case-insensitively. In that case the subquery filters on the view's expression, rebuilt over the successor table, with the caller's operator and value unchanged. The filter stays on the successor, but no plain index applies.

`sent()` normalizes values the way the view compares them. It formats a date in the grammar's format, and binds a float as a string, so no decimal place is lost.

## Negation and NULL rows

A negated filter must keep SQL's NULL semantics. `NOT (price > 100)` excludes a listing with no price.

`LegacyBuilder` therefore pushes negation into the value predicate. It counts the enclosing `whereNot()` groups, carrying the count into each nested builder. A negated filter then uses each operator's complement, so `>` becomes `<=`, and `and` and `or` swap by De Morgan's law. The outer membership test cancels the group's own `NOT`, because the legacy key is never NULL. The `status` filter in the example above shows the result.

## Refusals

`CompatibilityQueryRefused::untranslatable()` names the attribute, the operation and the reason:

- **A raw expression or a subquery as the value** of a converted attribute. No conversion can apply to a value the database computes.
- **An attribute stored outside the database,** in a filter, a sort or an aggregate. No SQL can read it.

## Caches

- **The resolved map** is cached per model class.
- **The relation** is read once per builder: the current schema, whether the legacy name is a view, and whether the role can read the successors. A legacy name that is still a table is cached for the process.
- **Column types and collations** are cached per connection, schema and table.
- **`forgetViews()`** clears the column, collation and still-a-table caches. `Compatibility::prepare()`, `install()`, `recompile()` and `uninstall()` call it after they run, so the process reads the catalog again. A builder created before the operation keeps its own relation.

## Limits

- **Only Eloquent queries are translated.** `DB::table('listings')` reads the view directly. It returns the same rows, without index help.
- **Sorting is never translated.** An `orderBy` on a converted attribute sorts by the view's expression.
