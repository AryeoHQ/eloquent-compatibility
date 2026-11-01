<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

use InvalidArgumentException;

final readonly class DividedBy implements Conversion
{
    public function __construct(public int|float $divisor) {}

    public function read(string $stored): string
    {
        return '(CAST('.$stored.' AS numeric) / '.(new Fixed($this->divisor))->read('').')';
    }

    public function write(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException(var_export($value, true).' is not a number, so it cannot be multiplied by '.$this->divisor.'.');
        }

        $stored = round((float) $value * $this->divisor, 9);

        return floor($stored) === $stored ? (int) $stored : $stored;
    }

    public function filter(string $operator, mixed $value): null|array
    {
        $operator = match ($operator) {
            '=', '!=', '<>' => $operator,
            '<', '>', '<=', '>=' => $this->divisor > 0 ? $operator : strtr($operator, '<>', '><'),
            default => null,
        };

        return $operator === null ? null : [$operator, $this->write($value)];
    }

    public function order(string $direction): string
    {
        $direction = strtolower($direction);

        return $this->divisor > 0 ? $direction : ($direction === 'asc' ? 'desc' : 'asc');
    }
}
