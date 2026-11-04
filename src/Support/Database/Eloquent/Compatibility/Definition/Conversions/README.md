# Conversions

A conversion turns a stored successor value into the value the legacy model expects. A map declares it on an attribute, such as `->from('price_cents')->dividedBy(100)`. One declaration drives four things: the SQL the view serves, the value a legacy write stores, the filter `LegacyBuilder` translates, and the check that the translation is exact.

## The contract

Each class implements `Conversion`:

| Method | What it returns | Who uses it |
| :-- | :-- | :-- |
| `Conversion::read()` | The SQL expression the view serves, built around the stored expression it receives | `ViewCompiler`, and `LegacyBuilder::served()` for a rebuilt expression |
| `Conversion::write()` | The stored value for a legacy value; it throws for a value it cannot store | Legacy writes, through `Attribute::write()` |
| `Conversion::filter()` | The same filter on the stored value, or null when none exists | `LegacyBuilder`, through `Attribute::filter()` |
| `Conversion::type()` | The SQL type this step produces, from the type it receives; null when unknown | `Attribute`, to carry types through the chain |
| `Conversion::exact()` | Whether a filter on the stored value returns exactly what a filter on the served value returns | `LegacyBuilder::served()`, through `Attribute::exact()` |

`Conversion::filter()` returns `[operator, value]`, or one of three tokens:

| Token | Meaning |
| :-- | :-- |
| `none` | No row matches |
| `all` | Every row matches |
| `in` | The value is a list of stored values |

`LegacyBuilder` adds a fourth token, `unknown`, for a NULL in a `whereIn()` list. No conversion produces it.

## Chaining

An attribute may chain several conversions. `Attribute::read()` applies them in declaration order. `Attribute::write()` and `Attribute::filter()` apply them in reverse. Each step receives the SQL type the previous step produced, so `Attribute::exact()` can judge every step against its real input type.

`fixed()` and `readAs()` replace the chain. An attribute that declares either one has no other conversion.

## The classes

| Class | Declared as | `read()` serves | `write()` stores | Filters it translates |
| :-- | :-- | :-- | :-- | :-- |
| `DividedBy` | `dividedBy(100)` | `CAST(s AS numeric) / 100` | `value × 100`, rounded to 9 places, an integer when whole | `=`, `!=`, `<`, `>`, `<=`, `>=`; a negative divisor flips the range |
| `MultipliedBy` | `multipliedBy(n)` | `s * CAST(n AS numeric)`, or `s *` a float literal for a float factor | `value ÷ n`, rounded the same way | Same as `DividedBy` |
| `Mapping` | `mapping([stored => legacy])` | `CASE CAST(s AS text) WHEN … END`; an unmapped stored value reads NULL | The stored key of a legacy value; it refuses an unmapped one | `=` and `!=` against a mapped value; `!=` becomes `in` the other keys |
| `IsNotNull` | `isNotNull(fn () => now())` | `s IS NOT NULL` | `true` stores the closure's value; `false` stores NULL | `=` and `!=` against a boolean or NULL |
| `OrDefault` | `orDefault(0)` | `COALESCE(s, 0)` | The value unchanged | `=` and `!=`, except against the default |
| `AsType` | `asType('date')` | `CAST(s AS date)` | The value unchanged | None, and never exact |
| `InTimeZone` | `inTimeZone('America/Chicago')` | `s AT TIME ZONE 'America/Chicago'`, an instant as that zone's wall clock | The wall clock in that zone, as an instant in the application zone | `=`, `!=`, `<`, `>`, `<=`, `>=` against a date or a wall-clock string |
| `ReadAs` | `readAs('sql')` | The SQL as written | Only through `writeTo()`; otherwise the attribute reads only | None |
| `Fixed` | `fixed('listing')` | The literal | Only the same value; it refuses any other | None |

`Fixed` also renders the literals the other classes embed, such as the divisor of `DividedBy`.

An `asType()` cast from `timestamp with time zone` to a naive type reads in the application zone, whatever the session zone. `Attribute::read()` applies that rule, not `AsType`.

## Exactness

`Conversion::exact()` answers one question: does filtering the stored value return the same rows as filtering the served value? When any step says no, `LegacyBuilder` filters on the rebuilt expression instead. The result stays correct, but no plain index applies.

Each class answers from its types. For example:

- **`DividedBy`** is exact when the served type keeps every decimal place the division produces.
- **`Mapping`** is exact when every legacy value fits the served type, without overflow or truncation.
- **`AsType`** is never exact, because a cast can round, truncate or reformat.
- **`Fixed`** and **`ReadAs`** say yes, because neither translates a filter, so the question never arises.

## Adding a conversion

1. Add a `final readonly` class here that implements `Conversion`.
2. Add a fluent method on `Attribute` that validates its arguments, then calls `convert()`. A closure argument goes through `ResolvedMap::refuseCaptures()`, so the map stays free of state.
3. Return null from `Conversion::filter()` for every operator you cannot invert exactly. Null keeps the filter on the view, which is correct. A wrong inverse returns wrong rows.
4. Return false from `Conversion::exact()` whenever you are unsure. False costs an index, and a wrong true costs correctness.
5. Test it beside the class, and through `LegacyBuilderReadTest`, which compares each translated filter against the same filter run directly on the installed view.
