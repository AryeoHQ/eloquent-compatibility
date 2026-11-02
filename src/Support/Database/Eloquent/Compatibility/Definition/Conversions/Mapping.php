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

        foreach ($this->values as $storedValue => $legacyValue) {
            $cases[] = 'WHEN '.SqlFragments::literal((string) $storedValue).' THEN '.(new Fixed($legacyValue))->read('');
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
        if (! in_array($operator, ['=', '!=', '<>'], true) || $value === null) {
            return null;
        }

        $stored = $this->stored($value);

        if ($operator === '=') {
            return $stored === null ? null : ['=', self::typed($stored, $type)];
        }

        $others = array_filter(array_keys($this->values), fn (int|string $key) => $key !== $stored);

        return ['in', array_values(array_map(fn (int|string $key) => self::typed($key, $type), $others))];
    }

    public function order(string $direction): null|string
    {
        return null;
    }

    public function type(null|string $type): string
    {
        return array_filter($this->values, is_string(...)) === [] ? 'integer' : 'text';
    }

    public function exact(null|string $type, string $served): bool
    {
        return true;
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
