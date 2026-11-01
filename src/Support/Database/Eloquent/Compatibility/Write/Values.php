<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Write;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Facades\Date;
use JsonSerializable;
use Stringable;
use Throwable;

/**
 * Compares and computes raw attribute values the way PostgreSQL would for the column's type, without a round trip.
 */
final class Values
{
    private const array NUMBER_TYPES = ['smallint', 'integer', 'bigint', 'real', 'double precision'];

    /**
     * Whether two raw values are the same stored value of a column of $type, as IS NOT DISTINCT FROM decides it.
     */
    public static function same(mixed $a, mixed $b, null|string $type = null): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        if ($a === $b) {
            return true;
        }

        $type = (string) $type;

        if ($type === 'boolean' || is_bool($a) || is_bool($b)) {
            return self::bool($a) !== null && self::bool($a) === self::bool($b);
        }

        if (self::numeric($type) || (is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return is_numeric($a) && is_numeric($b) && self::number($a) !== null && self::number($a) === self::number($b);
        }

        if (str_starts_with($type, 'timestamp') || $type === 'date') {
            return self::instant($a) !== null && self::instant($a) === self::instant($b);
        }

        if (in_array($type, ['json', 'jsonb'], true)) {
            return json_decode(self::string($a), true) == json_decode(self::string($b), true);
        }

        return self::string($a) === self::string($b);
    }

    /**
     * Adds $amount to a stored number, exactly for integers.
     */
    public static function add(mixed $value, float|int $amount): float|int
    {
        if ($value === null) {
            $value = 0;
        }

        if (is_int($amount) && (is_int($value) || (is_string($value) && preg_match('/^\s*-?\d+\s*$/', $value) === 1))) {
            return (int) $value + $amount;
        }

        return (float) $value + $amount;
    }

    public static function numeric(string $type): bool
    {
        return in_array($type, self::NUMBER_TYPES, true) || str_starts_with($type, 'numeric');
    }

    public static function number(float|int|string $value): null|string
    {
        if (preg_match('/^\s*([+-]?)(\d*)(?:\.(\d*))?(?:e([+-]?\d{1,9}))?\s*$/i', is_float($value) ? var_export($value, true) : (string) $value, $parts) !== 1) {
            return null;
        }

        $digits = $parts[2].($parts[3] ?? '');
        $significant = ltrim($digits, '0');
        $exponent = (int) ($parts[4] ?? 0) + strlen($parts[2]) - strlen($digits) + strlen($significant);
        $significant = rtrim($significant, '0');

        return $significant === '' ? '0' : ($parts[1] === '-' ? '-' : '').$significant.'e'.$exponent;
    }

    private static function instant(mixed $value): null|string
    {
        try {
            return $value instanceof DateTimeInterface ? Date::instance($value)->utc()->format('Y-m-d H:i:s.u') : Date::parse(self::string($value), 'UTC')->utc()->format('Y-m-d H:i:s.u');
        } catch (Throwable) {
            return null;
        }
    }

    private static function bool(mixed $value): null|bool
    {
        return match (true) {
            is_bool($value) => $value,
            in_array($value, [1, '1', 't', 'true', 'y', 'yes', 'on'], true) => true,
            in_array($value, [0, '0', 'f', 'false', 'n', 'no', 'off'], true) => false,
            default => null,
        };
    }

    private static function string(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof Stringable, is_scalar($value) => (string) $value,
            is_array($value), $value instanceof JsonSerializable => (string) json_encode($value),
            default => serialize($value),
        };
    }
}
