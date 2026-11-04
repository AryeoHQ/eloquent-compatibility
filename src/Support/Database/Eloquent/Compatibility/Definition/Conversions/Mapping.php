<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

use BackedEnum;
use InvalidArgumentException;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;

final readonly class Mapping implements Conversion
{
    /** @var array<int|string, int|string> */
    public array $values;

    /**
     * @param  array<int|string, int|string|BackedEnum>  $values
     */
    public function __construct(array $values)
    {
        $this->values = array_map(fn (int|string|BackedEnum $value) => $value instanceof BackedEnum ? $value->value : $value, $values);
    }

    public function read(string $stored): string
    {
        $cases = [];
        $text = $this->type(null) === 'text';

        foreach ($this->values as $storedValue => $legacyValue) {
            $cases[] = 'WHEN '.SqlFragments::literal((string) $storedValue).' THEN '.($text ? SqlFragments::literal((string) $legacyValue) : (new Fixed($legacyValue))->read(''));
        }

        return '(CASE CAST('.$stored.' AS text) '.implode(' ', $cases).' END)';
    }

    public function write(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return $this->stored($value) ?? throw new InvalidArgumentException('mapping() maps no stored value to '.var_export($value instanceof BackedEnum ? $value->value : $value, true).'.');
    }

    public function filter(string $operator, mixed $value, null|string $type = null): null|array
    {
        if (! in_array($operator, ['=', '!=', '<>'], true) || $value === null || ! $this->comparesKeysAsText($type)) {
            return null;
        }

        $stored = $this->stored($value);

        if ($stored === null) {
            return null;
        }

        if ($operator === '=') {
            return ['=', self::typed($stored, $type)];
        }

        $others = array_filter(array_keys($this->values), fn (int|string $key) => $key !== $stored);

        return ['in', array_values(array_map(fn (int|string $key) => self::typed($key, $type), $others))];
    }

    public function type(null|string $type): string
    {
        return array_filter($this->values, is_string(...)) === [] ? 'integer' : 'text';
    }

    public function exact(null|string $type, string $served): bool
    {
        $integers = ['smallint' => 32767, 'integer' => 2147483647, 'bigint' => PHP_INT_MAX];
        $length = preg_match('/^character varying(\((\d+)\))?$/', $served, $match) === 1 ? (int) ($match[2] ?? PHP_INT_MAX) : ($served === 'text' ? PHP_INT_MAX : null);

        foreach ($this->values as $value) {
            $characters = is_int($value) ? false : preg_match_all('/./su', $value);
            $kept = is_int($value)
                ? array_key_exists($served, $integers) && abs($value) <= $integers[$served]
                : $length !== null && $characters !== false && $characters <= $length;

            if (! $kept) {
                return false;
            }
        }

        return true;
    }

    private function comparesKeysAsText(null|string $type): bool
    {
        if (SqlFragments::isInteger($type)) {
            return array_filter(array_keys($this->values), is_string(...)) === [];
        }

        return $type !== null && preg_match('/^(text|character varying(\(\d+\))?)$/', $type) === 1;
    }

    private static function typed(int|string $stored, null|string $type): int|string
    {
        return SqlFragments::isInteger($type) ? (int) $stored : (string) $stored;
    }

    private function stored(mixed $value): null|int|string
    {
        $value = $value instanceof BackedEnum ? $value->value : $value;

        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $stored = array_search((string) $value, array_map(strval(...), $this->values), true);

        return $stored === false ? null : $stored;
    }
}
