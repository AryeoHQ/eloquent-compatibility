<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

use BackedEnum;
use InvalidArgumentException;

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
            $cases[] = 'WHEN '.(new Fixed($storedValue))->read('').' THEN '.(new Fixed($legacyValue))->read('');
        }

        return '(CASE '.$stored.' '.implode(' ', $cases).' END)';
    }

    public function write(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return $this->stored($value) ?? throw new InvalidArgumentException('mapping() maps no stored value to '.var_export($value instanceof BackedEnum ? $value->value : $value, true).'.');
    }

    public function filter(string $operator, mixed $value): null|array
    {
        if (! in_array($operator, ['=', '!=', '<>'], true) || $value === null) {
            return null;
        }

        $stored = $this->stored($value);

        return $stored === null ? null : [$operator, $stored];
    }

    public function order(string $direction): null|string
    {
        return null;
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
