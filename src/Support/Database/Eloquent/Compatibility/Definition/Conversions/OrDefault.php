<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

use BackedEnum;

final readonly class OrDefault implements Conversion
{
    private const array INTEGER_LIMITS = ['smallint' => '32768', 'int2' => '32768', 'integer' => '2147483648', 'int' => '2147483648', 'int4' => '2147483648', 'bigint' => '9223372036854775808', 'int8' => '9223372036854775808'];

    public bool|int|float|string $default;

    public function __construct(bool|int|float|string|BackedEnum $default)
    {
        $this->default = $default instanceof BackedEnum ? $default->value : $default;
    }

    public function read(string $stored): string
    {
        return 'COALESCE('.$stored.', '.(new Fixed($this->default))->read('').')';
    }

    public function write(mixed $value): mixed
    {
        return $value;
    }

    public function filter(string $operator, mixed $value, null|string $type = null): null|array
    {
        if ($value === null) {
            return match ($operator) {
                '=' => ['none', null],
                '!=', '<>' => ['all', null],
                default => null,
            };
        }

        $default = $this->defaultAs($type);
        $value = self::canonical($value instanceof BackedEnum ? $value->value : $value, $type);

        if ($default === null || $value === null) {
            return null;
        }

        return match ($operator) {
            '=' => $value === $default ? null : ['=', $value],
            '!=', '<>' => [$value === $default ? '!=' : 'is distinct from', $value],
            default => null,
        };
    }

    public function order(string $direction): null|string
    {
        return null;
    }

    public function type(null|string $type): null|string
    {
        return $type;
    }

    public function exact(null|string $type, string $served): bool
    {
        return $type !== null && $type === $served && $this->defaultAs($served) !== null;
    }

    private function defaultAs(null|string $type): null|string
    {
        return self::canonical(is_float($this->default) ? (new Fixed($this->default))->read('') : $this->default, $type);
    }

    private static function canonical(mixed $value, null|string $type): null|string
    {
        if ($type === 'boolean') {
            $boolean = $value === '' ? null : IsNotNull::boolean($value);

            return $boolean === null ? null : ($boolean ? 'true' : 'false');
        }

        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $text = (string) $value;

        if ($type !== null && preg_match('/^(text|character varying(\((\d+)\))?)$/', $type, $varying) === 1) {
            return ($varying[3] ?? '') === '' || mb_strlen($text) <= (int) $varying[3] ? $text : null;
        }

        if ($type !== null && array_key_exists($type, self::INTEGER_LIMITS)) {
            return self::integer($text, self::INTEGER_LIMITS[$type]);
        }

        if ($type !== null && preg_match('/^numeric(\((\d+)(,(\d+))?\))?$/', $type, $numeric) === 1) {
            return self::decimal($text, ($numeric[2] ?? '') === '' ? null : (int) $numeric[2], (int) ($numeric[4] ?? 0));
        }

        return null;
    }

    private static function integer(string $text, string $limit): null|string
    {
        if (preg_match('/^\s*([+-]?)0*(\d+)\s*$/', $text, $match) !== 1) {
            return null;
        }

        [, $sign, $digits] = $match;
        $fits = strlen($digits) < strlen($limit) || (strlen($digits) === strlen($limit) && ($sign === '-' ? strcmp($digits, $limit) <= 0 : strcmp($digits, $limit) < 0));

        return $fits ? ($sign === '-' && $digits !== '0' ? '-' : '').$digits : null;
    }

    private static function decimal(string $text, null|int $precision, int $scale): null|string
    {
        if (preg_match('/^\s*([+-]?)(\d*)(\.(\d*))?\s*$/', $text, $match) !== 1 || $match[2].($match[4] ?? '') === '') {
            return null;
        }

        $whole = ltrim($match[2], '0');
        $fraction = rtrim($match[4] ?? '', '0');

        if ($precision !== null && (strlen($fraction) > $scale || strlen($whole) > $precision - $scale)) {
            return null;
        }

        $decimal = ($whole === '' ? '0' : $whole).($fraction === '' ? '' : '.'.$fraction);

        return ($match[1] === '-' && $decimal !== '0' ? '-' : '').$decimal;
    }
}
