<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use InvalidArgumentException;
use Stringable;

final class SqlFragments
{
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

    /**
     * Replaces each `?` placeholder of compiled SQL with its binding as a PostgreSQL literal, without a connection:
     * Laravel's own toRawSql() escapes through PDO, which would connect. A placeholder inside a quoted string stays,
     * and `??`, PostgreSQL's escaped `?` operator, becomes `?`.
     *
     * @param  array<array-key, mixed>  $bindings
     */
    public static function inline(string $sql, array $bindings, Grammar $grammar): string
    {
        $bindings = array_values($bindings);
        $inlined = '';
        $quote = null;
        $length = strlen($sql);

        for ($position = 0; $position < $length; $position++) {
            $character = $sql[$position];

            if ($quote !== null) {
                $inlined .= $character;
                $quote = $character === $quote ? null : $quote;

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;
                $inlined .= $character;

                continue;
            }

            if ($character === '?') {
                if (($sql[$position + 1] ?? '') === '?') {
                    $inlined .= '?';
                    $position++;

                    continue;
                }

                if ($bindings === []) {
                    throw new InvalidArgumentException('The SQL has more placeholders than bindings: '.$sql);
                }

                $inlined .= self::value(array_shift($bindings), $grammar);

                continue;
            }

            $inlined .= $character;
        }

        return $inlined;
    }

    private static function value(mixed $value, Grammar $grammar): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => var_export($value, true),
            $value instanceof Expression => (string) $value->getValue($grammar),
            $value instanceof BackedEnum => self::value($value->value, $grammar),
            $value instanceof DateTimeInterface => self::literal($value->format('Y-m-d H:i:s.uP')),
            is_string($value), $value instanceof Stringable => self::literal((string) $value),
            default => throw new InvalidArgumentException('A binding of type '.get_debug_type($value).' cannot be written as a literal.'),
        };
    }
}
