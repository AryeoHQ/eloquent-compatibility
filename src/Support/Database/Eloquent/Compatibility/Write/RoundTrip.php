<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use BackedEnum;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Support\Database\Eloquent\Compatibility\Schema\Column;
use Support\Database\Eloquent\Compatibility\Schema\ColumnCatalog;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;
use Support\Database\Eloquent\Compatibility\Schema\ViewCompiler;
use Throwable;
use WeakMap;

final class RoundTrip
{
    private const array NUMERIC_CASTS = ['int', 'integer', 'real', 'float', 'double', 'decimal'];

    /** @var null|WeakMap<Connection, array<class-string<Model>, string>> */
    private static null|WeakMap $unhidden = null;

    /** @var null|WeakMap<Connection, array<class-string<Model>, list<string>>> */
    private static null|WeakMap $numeric = null;

    public function __construct(private readonly ResolvedMap $map, private readonly Connection $connection) {}

    public static function forget(): void
    {
        self::$unhidden = null;
        self::$numeric = null;
    }

    /**
     * @param  Staging<Model>  $graph
     * @param  array<string, mixed>  $written
     * @param  array<string, float|int>  $added
     * @return null|array<string, mixed>
     */
    public function check(Staging $graph, Model $legacy, Model $original, int|string $key, array $written, array $added, Origin $origin): null|array
    {
        $row = $this->connection->table($this->map->table)->useWritePdo()->where($legacy->getKeyName(), $key)->first();
        $served = $row === null ? $this->hidden(
            keyName: $legacy->getKeyName(),
            key: $key,
        ) : (array) $row;

        if ($served === null) {
            throw CompatibilityWriteRefused::roundTripLost(
                model: $this->map->model,
                key: $key,
            );
        }

        $hidden = $row === null;

        foreach ($written as $name => $value) {
            [$base, $path] = array_pad(
                array: explode(
                    separator: '->',
                    string: $name,
                    limit: 2,
                ),
                length: 2,
                value: null,
            );
            $attribute = $graph->map->attributes[$base] ?? null;

            if ($attribute === null || $this->skips(
                attribute: $attribute,
                origin: $origin,
            )) {
                continue;
            }

            if ($path !== null) {
                $this->checkPath(
                    key: $key,
                    name: $name,
                    path: $path,
                    value: $value,
                    column: $served[$base] ?? null,
                    hidden: $hidden,
                );

                continue;
            }

            if ($attribute->isExternal()) {
                $this->checkComputed($graph, $key, $name, $attribute, $value);

                continue;
            }

            if (! array_key_exists($name, $served)) {
                continue;
            }

            $this->compare(
                legacy: $legacy,
                name: $name,
                key: $key,
                written: $value,
                served: $served[$name],
                hidden: $hidden,
            );
        }

        foreach ($added as $name => $amount) {
            $attribute = $graph->map->attributes[$name] ?? null;
            $before = $original->getAttributes()[$name] ?? null;

            if ($attribute === null || $before === null || $this->skips(
                attribute: $attribute,
                origin: $origin,
            ) || ! array_key_exists($name, $served)) {
                continue;
            }

            $sum = $this->connection->scalar('select cast(? as numeric) + cast(? as numeric)', [$before, $amount]);

            $this->compare(
                legacy: $legacy,
                name: $name,
                key: $key,
                written: $legacy->newInstance(
                    attributes: [],
                    exists: true,
                )->setAttribute(
                    key: $name,
                    value: $sum,
                )->getAttributes()[$name] ?? null,
                served: $served[$name],
                hidden: $hidden,
            );
        }

        return $hidden ? null : $served;
    }

    /**
     * @return null|array<string, mixed>
     */
    private function hidden(string $keyName, int|string $key): null|array
    {
        $compiled = self::$unhidden ??= new WeakMap;
        $selects = $compiled[$this->connection] ?? [];
        $selects[$this->map->model] ??= (new ViewCompiler(
            columnCatalog: new ColumnCatalog(
                connection: $this->connection,
                throughSearchPath: true,
            ),
            connection: $this->connection,
        ))->unhidden($this->map);
        $compiled[$this->connection] = $selects;
        $record = SqlFragments::identifier($this->map->table);
        $row = $this->connection->selectOne(
            query: 'SELECT * FROM ('.$selects[$this->map->model].') AS '.$record.' WHERE '.$record.'.'.SqlFragments::identifier($keyName).' = ?',
            bindings: [$key],
            useReadPdo: false,
        );

        return $row === null ? null : (array) $row;
    }

    public function skips(Attribute $attribute, Origin $origin): bool
    {
        return match (true) {
            $attribute->ignoresWrites() => true,
            $attribute->readOnlyBecause() !== null && ! ($attribute->storesColumn() && $attribute->invertible()) => true,
            $attribute->isExternal() => $origin !== Origin::Live || $attribute->operation() === null,
            default => false,
        };
    }

    private function compare(Model $legacy, string $name, int|string $key, mixed $written, mixed $served, bool $hidden): void
    {
        [$same, $cause] = self::same(
            legacy: $legacy,
            name: $name,
            written: $written,
            served: $served,
            numeric: fn (): bool => $this->numeric($legacy, $name),
        );

        if ($same) {
            return;
        }

        throw CompatibilityWriteRefused::roundTrip(
            model: $this->map->model,
            attribute: $name,
            key: $key,
            written: $written,
            served: $served,
            hidden: $hidden,
            previous: $cause,
        );
    }

    private function checkPath(int|string $key, string $name, string $path, mixed $value, mixed $column, bool $hidden): void
    {
        if (! is_string($column)) {
            return;
        }

        $read = json_decode(
            json: $column,
            associative: true,
        );
        $segments = preg_split(
            pattern: '/->|\[|\]/',
            subject: $path,
            limit: -1,
            flags: PREG_SPLIT_NO_EMPTY,
        );

        foreach (is_array($segments) ? $segments : [] as $segment) {
            $read = is_array($read) && array_key_exists(
                key: $segment,
                array: $read,
            ) ? $read[$segment] : null;
        }

        $written = json_decode(
            json: json_encode(
                value: $value,
                flags: JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
            ),
            associative: true,
        );

        if (self::canonical($read) === self::canonical($written)) {
            return;
        }

        throw CompatibilityWriteRefused::roundTrip(
            model: $this->map->model,
            attribute: $name,
            key: $key,
            written: is_array($written) ? json_encode($written, JSON_THROW_ON_ERROR) : $written,
            served: is_array($read) ? json_encode($read, JSON_THROW_ON_ERROR) : $read,
            hidden: $hidden,
        );
    }

    private function numeric(Model $legacy, string $name): bool
    {
        if ($legacy->hasCast(
            key: $name,
            types: self::NUMERIC_CASTS,
        )) {
            return true;
        }

        $known = self::$numeric ??= new WeakMap;
        $models = $known[$this->connection] ?? [];
        $models[$this->map->model] ??= array_keys(array_filter(
            array: (new ColumnCatalog(
                connection: $this->connection,
                throughSearchPath: true,
            ))->of($this->map->table),
            callback: fn (Column $column) => SqlFragments::isNumeric($column->baseType ?? $column->type),
        ));
        $known[$this->connection] = $models;

        return in_array(
            needle: $name,
            haystack: $models[$this->map->model],
            strict: true,
        );
    }

    /**
     * @param  Closure(): bool  $numeric
     * @return array{bool, null|Throwable}
     */
    private static function same(Model $legacy, string $name, mixed $written, mixed $served, Closure $numeric): array
    {
        $written = match (true) {
            $written instanceof BackedEnum => $written->value,
            $written instanceof DateTimeInterface => $legacy->fromDateTime($written),
            default => $written,
        };

        if (is_array($written)) {
            return [is_string($served) && self::canonical(json_decode(json: $served, associative: true)) === self::canonical($written), null];
        }

        $probe = $legacy->newInstance([], true);
        $probe->setRawAttributes([$name => $served], true);
        $probe->setRawAttributes([$name => $written]);
        $cause = null;

        try {
            if ($probe->originalIsEquivalent($name)) {
                return [true, null];
            }
        } catch (Throwable $exception) {
            $cause = $exception;
        }

        return [match (true) {
            $written === null || $served === null => $written === $served,
            is_bool($written) || is_bool($served) => filter_var($written, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === filter_var($served, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            is_numeric($written) && is_numeric($served) && $numeric() => self::numericallySame(written: $written, served: $served),
            self::decoded($written) !== null && self::decoded($served) !== null => self::canonical(self::decoded($written)) === self::canonical(self::decoded($served)),
            default => ExternalAttributes::same($served, $written),
        }, $cause];
    }

    private static function numericallySame(int|float|string $written, int|float|string $served): bool
    {
        if (is_float($written) || is_float($served)) {
            return (float) $written === (float) $served;
        }

        try {
            return BigDecimal::of(trim((string) $written))->isEqualTo(trim((string) $served));
        } catch (MathException) {
            return false;
        }
    }

    /**
     * @return null|array<array-key, mixed>
     */
    private static function decoded(mixed $value): null|array
    {
        if (! is_string($value) || ! json_validate($value)) {
            return null;
        }

        $decoded = json_decode(
            json: $value,
            associative: true,
        );

        return is_array($decoded) ? $decoded : null;
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }

    /**
     * @param  Staging<Model>  $graph
     */
    private function checkComputed(Staging $graph, int|string $key, string $name, Attribute $attribute, mixed $value): void
    {
        $owner = $graph->map->owners[$name];
        $successor = $graph->model($owner->table)?->fresh();

        if ($successor === null) {
            return;
        }

        $read = $attribute->readExternally(new Collection([$successor]))[$successor->getKey()] ?? null;

        if (! ExternalAttributes::same($value, $read)) {
            throw CompatibilityWriteRefused::roundTrip(
                model: $this->map->model,
                attribute: $name,
                key: $key,
                written: $value,
                served: $read,
            );
        }
    }
}
