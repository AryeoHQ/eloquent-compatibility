<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use BackedEnum;
use InvalidArgumentException;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;

final class SqlFragments
{
    public const int IDENTIFIER_LIMIT = 63;

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
