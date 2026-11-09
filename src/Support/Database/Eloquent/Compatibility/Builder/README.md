# Builder

`LegacyBuilder` is the Eloquent builder of every served legacy model. `ServesCompatibility::newEloquentBuilder()` returns it. It keeps legacy filters fast once the legacy table becomes a view. It never changes which rows a query returns.

## Why filters are translated

After install, the legacy table name is a read-only view over the successors. The view serves a converted attribute as an expression over a stored column. The Listing fixture serves `price` as `jobs.price_cents / 100`.

A filter on that expression returns the right rows. But PostgreSQL cannot use an index on `jobs.price_cents` for it, so a large table needs a sequential scan. An attribute with no conversion needs no help. PostgreSQL inlines the view, so a filter on a renamed column already uses the stored column's index.

`LegacyBuilder` therefore rewrites a filter on a converted attribute onto the stored column, before Laravel compiles the query. `LegacyBuilderReadTest::it_filters_a_converted_attribute_on_its_stored_column_once_installed()` pins the result:

```php
Listing::query()
    ->where('price', '>', 100)
    ->whereNot(fn (Builder $listings) => $listings->where('status', '!=', ListingStatus::Sold));
```

```sql
select * from "listings"
where "listings"."job_id" in (select "jobs"."id" from "jobs" where ("jobs"."price_cents" > 10000))
  and not ("listings"."job_id" not in (select "jobs"."id" from "jobs" where ("jobs"."lifecycle_status" = 'closed')))
  and "listings"."deleted_at" is null
```

Each translated filter becomes a membership test on the successor key. Its subquery reads the successor that stores the column. A related successor's column is reached from the primary table through the view's own lateral joins.

Everything that does not touch a converted attribute passes through to Laravel unchanged. So does every query before install, while the legacy name is still a table.

## How the code is organized

Eloquent forwards most query methods straight to the query builder, so an override is the only place to intercept them. Each public filter method below normalizes its arguments into `[operator, value]` conditions. It then hands them to `LegacyBuilder::translate()`, with a closure that runs Laravel's own method on the view as the fallback.

### `where()`

`LegacyBuilder::where()` handles three shapes:

- **A nested closure.** It builds a child `LegacyBuilder` and carries over the count of enclosing `whereNot()` groups. It merges eager loads and removed scopes back, as Laravel's nested `where()` does. See `## Negation and NULL rows` for why the count matters.
- **An array of conditions.** When any key names a converted attribute, it splits the array into one `where()` per item, inside a nested group. `LegacyBuilder::touchesConversions()` makes that check.
- **A single column.** It normalizes the operator and value as Laravel does, then translates. An unconverted column goes to `parent::where()`.

### `whereIn()` and its variants

`LegacyBuilder::whereIn()` turns each value into an `=` condition, combined with `or`. A NULL value becomes `unknown`, because `x IN (NULL)` never matches in SQL. An empty list passes through, and a subquery as the list is refused. `whereNotIn()`, `orWhereIn()` and `orWhereNotIn()` only set the flags and delegate.

### `whereBetween()` and its variants

`LegacyBuilder::whereBetween()` turns the two bounds into `>=` and `<=`, combined with `and`. Bounds that are not an array are refused. `whereNotBetween()`, `orWhereBetween()` and `orWhereNotBetween()` delegate.

### `whereNull()` and its variants

`LegacyBuilder::whereNull()` turns each column into an `= null` or `!= null` condition. The attribute's conversions decide what NULL means after conversion. `whereNotNull()`, `orWhereNull()` and `orWhereNotNull()` delegate.

### Ordering

`LegacyBuilder::orderBy()` sorts on the view and never translates. It refuses an attribute stored outside the database. `orderByDesc()`, `latest()` and `oldest()` route through it, because Eloquent's own `latest()` calls the query builder directly. `LegacyBuilder::createdAt()` qualifies the timestamp column when the query joins another table.

### Aggregates

`min()`, `max()`, `sum()`, `avg()` and `average()` call `LegacyBuilder::aggregateOver()`. It aggregates on the view, and refuses an attribute stored outside the database.

### The translation pipeline

1. **`LegacyBuilder::translate()`** runs the pipeline. It refuses what cannot translate, then normalizes each value through `LegacyBuilder::sent()`. It falls back to the view where `## When a filter stays on the view` says. Otherwise it builds the subquery and adds the membership test.
2. **`LegacyBuilder::served()`** decides whether comparing the stored column is exact. See `## Exactness`.
3. **`LegacyBuilder::stored()`** handles negation. A negated filter translates only through each operator's complement from `LegacyBuilder::COMPLEMENTS`. When any complement has no inverse, it returns null, and the filter stays on the view.
4. **`LegacyBuilder::filters()`** inverts each condition through the attribute's conversions. One condition with no inverse returns null, and the filter stays on the view.
5. **`LegacyBuilder::apply()`** writes the filters into the subquery. An `or` of equalities becomes one `whereIn`. The tokens `none`, `all` and `unknown` become `false`, `true` and `null`.

### Lookups and caches

- **`LegacyBuilder::converted()`** returns the attribute name only for a map attribute with conversions, or one stored outside the database, once the view is installed. Every other column passes through. `LegacyBuilder::attributeOf()` strips this model's table prefix first.
- **`LegacyBuilder::relation()`** reads once per builder the current schema, whether the legacy name is a view, and whether the role can read the successors. A legacy name that is still a table is cached for the process.
- **`LegacyBuilder::map()`** caches the resolved map per model class. `LegacyBuilder::collations()` caches collations per connection, schema and table.
- **`LegacyBuilder::forgetViews()`** clears the column, collation and still-a-table caches. `Compatibility::prepare()`, `Compatibility::install()`, `Compatibility::update()`, `Compatibility::rollback()` and `Compatibility::retire()` call it after they run. A builder created before the operation keeps its own relation.

## Which filters translate

Each conversion inverts a filter through `Conversion::filter()`. `Attribute::filter()` applies the conversions in reverse order. One conversion with no inverse leaves the whole filter on the view.

| Conversion | Operators it inverts |
| :-- | :-- |
| `dividedBy`, `multipliedBy` | `=`, `!=`, `<`, `>`, `<=`, `>=` |
| `mapping` | `=` and `!=` against a mapped value; `!=` becomes `in` the other stored keys |
| `isNotNull` | `=` and `!=` against a boolean or `null` |
| `orDefault` | `=` and `!=`, except against the default |
| `inTimeZone` | `=`, `!=`, `<`, `>`, `<=`, `>=` against a date or a wall-clock string |
| `readAs`, `fixed` | None |

`asType()` has no inverse either, but it is never exact, so its filters take the rebuilt-expression path under `## Exactness`.

## When a filter stays on the view

A filter stays on the view, which is correct but slow, in these cases:

- **No inverse exists.** Examples are `like` on a mapping, a value no mapping declares, and any `readAs()` attribute.
- **A range has a NULL bound,** as in `whereBetween('price', [null, 100])`. SQL returns unknown for that comparison, and the view keeps that result.
- **A negated filter's complement has no inverse.** Negating the stored filter instead would drop rows whose stored value is NULL, while the view can serve them as a value, for example through `orDefault()`.
- **The role cannot read the successors.** It lacks `SELECT` on a successor, or row-level security is active there. The subquery would then fail, or filter different rows than the view.

## Exactness

Comparing the stored column must return exactly what comparing the served value returns. `LegacyBuilder::served()` checks the stored and served types through `Conversion::exact()`, and checks both collations itself. A conversion may round or cast, or a collation may compare case-insensitively. In that case the subquery filters on the view's expression, rebuilt over the successor table, with the caller's operator and value unchanged. The filter stays on the successor, but no plain index applies.

`LegacyBuilder::sent()` normalizes values the way the view compares them. It formats a date in the grammar's format, and binds a float as a string, so no decimal place is lost.

## Negation and NULL rows

A negated filter must keep SQL's NULL semantics. `NOT (price > 100)` excludes a listing with no price.

`LegacyBuilder` therefore pushes negation into the value predicate. It counts the enclosing `whereNot()` groups, carrying the count into each nested builder. On the stored column, a negated filter translates only through each operator's complement, so `>` becomes `<=`, and `and` and `or` swap by De Morgan's law. A negated filter whose complement does not translate stays on the view. On the rebuilt expression, the subquery negates the caller's filter directly. That keeps the view's NULL semantics, because the expression is the view's own. The outer membership test cancels the group's own `NOT`, because the view serves no row whose successor key is NULL, and the subquery selects the root's primary key. The `status` filter in the example above shows the result.

## Refusals

`CompatibilityQueryRefused::untranslatable()` names the attribute, the operation and the reason:

- **A raw expression or a subquery as the value** of a converted attribute. No conversion can apply to a value the database computes.
- **An attribute stored outside the database,** in a filter, a sort or an aggregate. No SQL can read it.
- **`whereBetween()` bounds that are not an array,** such as a collection.

## Limits

- **Only Eloquent queries are translated.** A query built on the database connection, without the legacy model, reads the view directly. It returns the same rows, without index help.
- **Sorting is never translated.** An `orderBy` on a converted attribute sorts by the view's expression.
