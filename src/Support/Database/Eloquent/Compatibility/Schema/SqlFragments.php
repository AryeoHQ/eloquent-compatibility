<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Support\Database\Eloquent\Compatibility\Exceptions\InvalidCompatibilityMap;

final class SqlFragments
{
    public const int IDENTIFIER_LIMIT = 63;

    private const array INTEGERS = ['smallint', 'integer', 'bigint', 'int', 'int2', 'int4', 'int8'];

    private const array FLOATS = ['double precision', 'real', 'float4', 'float8'];

    private const array RANGES = ['smallint' => [-32768, 32767], 'int2' => [-32768, 32767], 'integer' => [-2147483648, 2147483647], 'int' => [-2147483648, 2147483647], 'int4' => [-2147483648, 2147483647]];

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
     * @return null|array{string, mixed}
     */
    public static function bound(string $operator, mixed $value, int|float $multiplier, int|float $divisor, null|string $type): null|array
    {
        $bound = self::unranged($operator, $value, $multiplier, $divisor, $type);
        [$low, $high] = self::RANGES[(string) $type] ?? [PHP_INT_MIN, PHP_INT_MAX];

        return $bound !== null && is_int($bound[1]) && ($bound[1] < $low || $bound[1] > $high) ? null : $bound;
    }

    /**
     * @return null|array{string, mixed}
     */
    private static function unranged(string $operator, mixed $value, int|float $multiplier, int|float $divisor, null|string $type): null|array
    {
        if ($value === null) {
            return [$operator, null];
        }

        $value = is_numeric($value) ? self::fraction($value) : null;
        $multiplier = self::fraction($multiplier);
        $divisor = self::fraction($divisor);
        $stored = $value === null || $multiplier === null || $divisor === null ? null : self::product(self::product($value, $multiplier), [$divisor[1], $divisor[0]]);

        if ($stored === null) {
            return null;
        }

        if ($stored[1] === 1) {
            return [$operator, $stored[0]];
        }

        $scale = self::scale($type);

        if ($scale === null) {
            $decimal = self::decimal($stored);

            return $decimal === null ? null : [$operator, $decimal];
        }

        $power = 10 ** $scale;
        $units = is_int($power) ? self::product($stored, [$power, 1]) : null;

        if ($units === null) {
            return null;
        }

        [$numerator, $denominator] = $units;

        if ($denominator === 1) {
            return [$operator, self::fixed($numerator, $scale)];
        }

        return match ($operator) {
            '=' => ['none', null],
            '!=', '<>' => ['!=', null],
            '>', '>=' => ['>=', self::fixed(intdiv($numerator, $denominator) + ($numerator % $denominator > 0 ? 1 : 0), $scale)],
            default => ['<=', self::fixed(intdiv($numerator, $denominator) - ($numerator % $denominator < 0 ? 1 : 0), $scale)],
        };
    }

    /**
     * @return null|array{int, int}
     */
    private static function fraction(int|float|string $value): null|array
    {
        $text = is_float($value) ? var_export($value, true) : trim((string) $value);

        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?(?:e([+-]?\d+))?$/i', $text, $match) !== 1 || $match[2].($match[3] ?? '') === '') {
            return null;
        }

        $fraction = $match[3] ?? '';
        $digits = ltrim($match[2].$fraction, '0');
        $exponent = (int) ($match[4] ?? 0) - strlen($fraction);

        if ($digits === '') {
            return [0, 1];
        }

        if (strlen($digits) > 18 || abs($exponent) > 18) {
            return null;
        }

        $numerator = (int) ($match[1].$digits);

        return $exponent >= 0 ? self::product([$numerator, 1], [10 ** $exponent, 1]) : self::product([$numerator, 1], [1, 10 ** -$exponent]);
    }

    /**
     * @param  null|array{int, int}  $first
     * @param  array{int, int}  $second
     * @return null|array{int, int}
     */
    private static function product(null|array $first, array $second): null|array
    {
        if ($first === null || $second[1] === 0) {
            return null;
        }

        $across = self::divisor($first[0], $second[1]);
        $down = self::divisor($second[0], $first[1]);
        $numerator = self::times(intdiv($first[0], $across), intdiv($second[0], $down));
        $denominator = self::times(intdiv($first[1], $down), intdiv($second[1], $across));

        if ($numerator === null || $denominator === null) {
            return null;
        }

        $common = self::divisor($numerator, $denominator) * ($denominator < 0 ? -1 : 1);

        return [intdiv($numerator, $common), intdiv($denominator, $common)];
    }

    /**
     * @param  array{int, int}  $fraction
     */
    private static function decimal(array $fraction): null|string
    {
        $rest = $fraction[1];
        $twos = 0;
        $fives = 0;

        for (; $rest % 2 === 0; $twos++) {
            $rest = intdiv($rest, 2);
        }

        for (; $rest % 5 === 0; $fives++) {
            $rest = intdiv($rest, 5);
        }

        $places = max($twos, $fives);
        $widen = 2 ** ($places - $twos) * 5 ** ($places - $fives);
        $units = $rest === 1 && is_int($widen) ? self::product([$fraction[0], 1], [$widen, 1]) : null;

        return $units === null ? null : (string) self::fixed($units[0], $places);
    }

    private static function fixed(int $units, int $scale): int|string
    {
        if ($scale === 0) {
            return $units;
        }

        $digits = str_pad(ltrim((string) $units, '-'), $scale + 1, '0', STR_PAD_LEFT);

        return ($units < 0 ? '-' : '').substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    }

    private static function times(int $first, int $second): null|int
    {
        return $second !== 0 && abs($first) > intdiv(PHP_INT_MAX, abs($second)) ? null : $first * $second;
    }

    private static function divisor(int $first, int $second): int
    {
        [$first, $second] = [abs($first), abs($second)];

        while ($second !== 0) {
            [$first, $second] = [$second, $first % $second];
        }

        return max($first, 1);
    }

    public static function identifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    public static function literal(string $value): string
    {
        if (str_contains($value, '\\')) {
            return "E'".str_replace(['\\', "'"], ['\\\\', "''"], $value)."'";
        }

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
            is_bool($value) => self::literal($value ? '1' : '0'),
            is_int($value), is_float($value), is_string($value) => self::literal((string) $value),
            default => throw new InvalidArgumentException(get_debug_type($value).' has no SQL literal.'),
        };
    }

    /**
     * @param  list<mixed>  $bindings
     */
    public static function bind(string $sql, array $bindings): string
    {
        $bound = '';
        $next = 0;

        for ($at = 0; $at < strlen($sql);) {
            $quoted = self::quoted($sql, $at);

            if ($quoted > 0) {
                $literal = substr($sql, $at, $quoted);

                if ($literal[0] === "'" && str_contains($literal, '\\')) {
                    throw new InvalidArgumentException("The SQL [{$sql}] has a backslash in the string literal {$literal}, and PostgreSQL reads that backslash as an escape only while standard_conforming_strings is off; write the string as E'…' so that it reads the same under either setting.");
                }

                $bound .= $literal;
                $at += $quoted;
            } elseif (substr($sql, $at, 2) === '??') {
                $bound .= '?';
                $at += 2;
            } elseif ($sql[$at] === '?') {
                if (! array_key_exists($next, $bindings)) {
                    throw new InvalidArgumentException("The SQL [{$sql}] has more placeholders than its ".count($bindings).' bindings.');
                }

                $bound .= self::value($bindings[$next++]);
                $at++;
            } else {
                $bound .= $sql[$at++];
            }
        }

        if ($next !== count($bindings)) {
            throw new InvalidArgumentException("The SQL [{$sql}] has {$next} placeholders for its ".count($bindings).' bindings.');
        }

        return $bound;
    }

    /**
     * @param  list<mixed>  $bindings
     * @return list<mixed>
     */
    public static function asSent(array $bindings, string $dateFormat): array
    {
        return array_map(fn (mixed $binding) => match (true) {
            $binding instanceof DateTimeInterface => $binding->format($dateFormat),
            is_float($binding) => (string) $binding,
            default => $binding,
        }, $bindings);
    }

    private static function quoted(string $sql, int $at): int
    {
        $inIdentifier = $at > 0 && preg_match('/[A-Za-z0-9_$]/', $sql[$at - 1]) === 1;
        $patterns = [
            '/\G[Ee]\'(?:[^\'\\\\]|\\\\.|\'\')*(?:\'|\z)/s' => ! $inIdentifier,
            '/\G\'(?:[^\']|\'\')*(?:\'|\z)/' => true,
            '/\G"(?:[^"]|"")*(?:"|\z)/' => true,
            '/\G--[^\n]*/' => true,
            '/\G(\$(?:[A-Za-z_][A-Za-z0-9_]*)?\$).*?(?:\1|\z)/s' => ! $inIdentifier,
        ];

        foreach ($patterns as $pattern => $applies) {
            if ($applies && preg_match($pattern, $sql, $match, 0, $at) === 1) {
                return strlen($match[0]);
            }
        }

        if (substr($sql, $at, 2) !== '/*') {
            return 0;
        }

        for ($end = $at, $depth = 0; $end < strlen($sql);) {
            $pair = substr($sql, $end, 2);
            $depth += match ($pair) {
                '/*' => 1,
                '*/' => -1,
                default => 0,
            };
            $end += in_array($pair, ['/*', '*/'], true) ? 2 : 1;

            if ($depth === 0) {
                return $end - $at;
            }
        }

        return strlen($sql) - $at;
    }

    public static function objectName(string $map, string $name): string
    {
        if (strlen($name) > self::IDENTIFIER_LIMIT) {
            throw InvalidCompatibilityMap::because($map, "the compiled name {$name} has ".strlen($name).' characters, and PostgreSQL keeps '.self::IDENTIFIER_LIMIT);
        }

        return $name;
    }
}
