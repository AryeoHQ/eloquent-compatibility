<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

use InvalidArgumentException;

final readonly class MultipliedBy implements Conversion
{
    public function __construct(public int|float $factor) {}

    public function read(string $stored): string
    {
        return '('.$stored.' * '.(new Fixed($this->factor))->read('').')';
    }

    public function write(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException(var_export($value, true).' is not a number, so it cannot be divided by '.$this->factor.'.');
        }

        $stored = round((float) $value / $this->factor, 9);

        return floor($stored) === $stored ? (int) $stored : $stored;
    }

    public function filter(string $operator, mixed $value): null|array
    {
        $operator = match ($operator) {
            '=', '!=', '<>' => $operator,
            '<', '>', '<=', '>=' => $this->factor > 0 ? $operator : strtr($operator, '<>', '><'),
            default => null,
        };

        return $operator === null ? null : [$operator, $this->write($value)];
    }

    public function order(string $direction): string
    {
        $direction = strtolower($direction);

        return $this->factor > 0 ? $direction : ($direction === 'asc' ? 'desc' : 'asc');
    }
}
