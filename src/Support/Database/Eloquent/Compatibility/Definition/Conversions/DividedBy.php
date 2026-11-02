<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

use InvalidArgumentException;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;

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

    public function filter(string $operator, mixed $value, null|string $type = null): null|array
    {
        $operator = match ($operator) {
            '=', '!=', '<>' => $operator,
            '<', '>', '<=', '>=' => $this->divisor > 0 ? $operator : strtr($operator, '<>', '><'),
            default => null,
        };

        return $operator === null ? null : SqlFragments::bound($operator, $this->write($value), $type);
    }

    public function order(string $direction): string
    {
        $direction = strtolower($direction);

        return $this->divisor > 0 ? $direction : ($direction === 'asc' ? 'desc' : 'asc');
    }

    public function type(null|string $type): string
    {
        return 'numeric';
    }

    public function exact(null|string $type, string $served): bool
    {
        $places = $this->places();
        $scale = SqlFragments::scale($type);

        return $places !== null && $scale !== null && SqlFragments::holds($served, $scale + $places);
    }

    private function places(): null|int
    {
        $divisor = abs($this->divisor);

        if (floor($divisor) !== (float) $divisor) {
            return floor(1 / $divisor) === 1 / $divisor ? 0 : null;
        }

        $remaining = (int) $divisor;
        $places = [2 => 0, 5 => 0];

        foreach ([2, 5] as $prime) {
            while ($remaining % $prime === 0) {
                $remaining = intdiv($remaining, $prime);
                $places[$prime]++;
            }
        }

        return $remaining === 1 ? max($places) : null;
    }
}
