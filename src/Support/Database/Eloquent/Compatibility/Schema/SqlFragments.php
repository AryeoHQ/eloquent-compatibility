<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use BackedEnum;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;

final class SqlFragments
{
    public const int IDENTIFIER_LIMIT = 63;

    private const array INTEGERS = ['smallint', 'integer', 'bigint', 'int', 'int2', 'int4', 'int8'];

    private const array FLOATS = ['double precision', 'real', 'float4', 'float8'];

    public static function isInteger(null|string $type): bool
    {
        return in_array($type, self::INTEGERS, true);
    }

    public static function isFloat(null|string $type): bool
    {
        return in_array($type, self::FLOATS, true);
    }

    public static function isZoned(null|string $type): bool
    {
        return $type !== null && preg_match('/^(timestamp(\(\d\))? with time zone|timestamptz(\(\d\))?)$/i', $type) === 1;
    }

    public static function isNaive(null|string $type): bool
    {
        return $type !== null && preg_match('/^(timestamp(\(\d\))?( without time zone)?|date|time(\(\d\))?( without time zone)?)$/i', $type) === 1;
    }

    public static function inApplicationZone(string $sql): string
    {
        return '('.$sql.' AT TIME ZONE '.self::literal(Config::string('app.timezone', 'UTC')).')';
    }

    public static function isWallClock(null|string $type): bool
    {
        return $type !== null && preg_match('/^timestamp(\(\d\))?( without time zone)?$/i', $type) === 1;
    }

    public static function precision(string $type): int
    {
        return preg_match('/\((\d)\)/', $type, $match) === 1 ? (int) $match[1] : 6;
    }

    public static function scale(null|string $type): null|int
    {
        if (self::isInteger($type)) {
            return 0;
        }

        return $type !== null && preg_match('/^(numeric|decimal)\(\d+(, ?(\d+))?\)$/i', $type, $match) === 1 ? (int) ($match[3] ?? 0) : null;
    }

    public static function holds(string $type, int $scale): bool
    {
        return in_array(strtolower($type), ['numeric', 'decimal'], true) || (self::scale($type) ?? -1) >= $scale;
    }

    /**
     * @return array{string, mixed}
     */
    public static function bound(string $operator, mixed $value, null|string $type): array
    {
        if (! is_float($value) || ! self::isInteger($type)) {
            return [$operator, $value];
        }

        return match ($operator) {
            '=' => ['none', null],
            '!=', '<>' => ['!=', null],
            '>', '>=' => ['>=', (int) ceil($value)],
            default => ['<=', (int) floor($value)],
        };
    }

    public static function identifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    public static function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    public static function like(string $prefix): string
    {
        return self::literal(addcslashes($prefix, '\\_%').'%');
    }

    public static function value(mixed $value): string
    {
        $value = $value instanceof BackedEnum ? $value->value : $value;

        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => var_export($value, true),
            is_string($value) => self::literal($value),
            default => throw new InvalidArgumentException(get_debug_type($value).' has no SQL literal.'),
        };
    }

    /**
     * @param  list<mixed>  $bindings
     */
    public static function bind(string $sql, array $bindings): string
    {
        $bound = '';
        $quoted = false;
        $next = 0;

        for ($at = 0; $at < strlen($sql); $at++) {
            $pair = substr($sql, $at, 2);

            if ($pair === "''") {
                $bound .= $pair;
                $at++;
            } elseif ($pair === '??' && ! $quoted) {
                $bound .= '?';
                $at++;
            } elseif ($sql[$at] === "'") {
                $bound .= "'";
                $quoted = ! $quoted;
            } elseif ($sql[$at] === '?' && ! $quoted) {
                if (! array_key_exists($next, $bindings)) {
                    throw new InvalidArgumentException("The SQL [{$sql}] has more placeholders than its ".count($bindings).' bindings.');
                }

                $bound .= self::value($bindings[$next++]);
            } else {
                $bound .= $sql[$at];
            }
        }

        if ($next !== count($bindings)) {
            throw new InvalidArgumentException("The SQL [{$sql}] has {$next} placeholders for its ".count($bindings).' bindings.');
        }

        return $bound;
    }

    public static function objectName(string $map, string $name): string
    {
        if (strlen($name) > self::IDENTIFIER_LIMIT) {
            throw InvalidCompatibilityMap::because($map, "the compiled name {$name} has ".strlen($name).' characters, and PostgreSQL keeps '.self::IDENTIFIER_LIMIT);
        }

        return $name;
    }
}
