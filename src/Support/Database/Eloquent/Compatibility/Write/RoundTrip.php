<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Support\Database\Eloquent\Compatibility\Definition\Attribute;
use Support\Database\Eloquent\Compatibility\Definition\ResolvedMap;
use Support\Database\Eloquent\Compatibility\Exceptions\CompatibilityWriteRefused;
use Throwable;

final readonly class RoundTrip
{
    public function __construct(private ResolvedMap $map, private Connection $connection) {}

    /**
     * @param  Staging<Model>  $graph
     * @param  array<string, mixed>  $written
     * @param  array<string, float|int>  $added
     * @return null|array<string, mixed>
     */
    public function check(Staging $graph, Model $legacy, Model $original, int|string $key, array $written, array $added, Origin $origin): null|array
    {
        $row = $this->connection->table($this->map->table)->useWritePdo()->where($legacy->getKeyName(), $key)->first();

        if ($row === null) {
            return null;
        }

        $served = (array) $row;

        foreach ($written as $name => $value) {
            $attribute = $graph->map->attributes[$name] ?? null;

            if ($attribute === null || $this->skips($attribute, $origin) || str_contains($name, '->')) {
                continue;
            }

            if ($attribute->isExternal()) {
                $this->checkComputed($graph, $key, $name, $attribute, $value);

                continue;
            }

            if (! array_key_exists($name, $served)) {
                continue;
            }

            if (! self::same($legacy, $name, $value, $served[$name])) {
                throw CompatibilityWriteRefused::roundTrip(
                    model: $this->map->model,
                    attribute: $name,
                    key: $key,
                    written: $value,
                    served: $served[$name],
                );
            }
        }

        foreach ($added as $name => $amount) {
            $attribute = $graph->map->attributes[$name] ?? null;
            $before = $original->getAttributes()[$name] ?? null;

            if ($attribute === null || $before === null || ! array_key_exists($name, $served)) {
                continue;
            }

            $sum = $this->connection->scalar('select cast(? as numeric) + cast(? as numeric)', [$before, $amount]);
            $expected = $legacy->newInstance([], true)->setAttribute($name, $sum)->getAttributes()[$name] ?? null;

            if (! self::same($legacy, $name, $expected, $served[$name])) {
                throw CompatibilityWriteRefused::roundTrip(
                    model: $this->map->model,
                    attribute: $name,
                    key: $key,
                    written: $expected,
                    served: $served[$name],
                );
            }
        }

        return $served;
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

    public static function same(Model $legacy, string $name, mixed $written, mixed $served): bool
    {
        $written = $written instanceof BackedEnum ? $written->value : $written;

        if (is_array($written)) {
            return is_string($served) && self::canonical(json_decode($served, true)) === self::canonical($written);
        }

        $probe = $legacy->newInstance([], true);
        $probe->setRawAttributes([$name => $served], true);
        $probe->setRawAttributes([$name => $written]);

        try {
            if ($probe->originalIsEquivalent($name)) {
                return true;
            }
        } catch (Throwable) {
        }

        return match (true) {
            $written === null || $served === null => $written === $served,
            $written instanceof DateTimeInterface || $served instanceof DateTimeInterface => false,
            is_bool($written) || is_bool($served) => filter_var($written, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === filter_var($served, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            is_numeric($written) && is_numeric($served) => self::numericallySame(written: $written, served: $served),
            default => ExternalAttributes::same($served, $written),
        };
    }

    private static function numericallySame(int|float|string $written, int|float|string $served): bool
    {
        if (is_float($written) || is_float($served) || stripos((string) $written, 'e') !== false || stripos((string) $served, 'e') !== false) {
            return (float) $written === (float) $served;
        }

        return self::decimal((string) $written) === self::decimal((string) $served);
    }

    private static function decimal(string $number): string
    {
        $number = trim($number);
        $negative = str_starts_with($number, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($number, '+-'), 2), 2, '');
        $whole = ltrim($whole, '0');
        $fraction = rtrim($fraction, '0');
        $digits = ($whole === '' ? '0' : $whole).($fraction === '' ? '' : '.'.$fraction);

        return $negative && $digits !== '0' ? '-'.$digits : $digits;
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
