<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

use InvalidArgumentException;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;

final readonly class MultipliedBy implements Conversion
{
    public function __construct(public int|float $factor) {}

    public function read(string $stored): string
    {
        return '('.$stored.' * '.(is_int($this->factor) ? 'CAST('.$this->factor.' AS numeric)' : (new Fixed($this->factor))->read('')).')';
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

    public function filter(string $operator, mixed $value, null|string $type = null): null|array
    {
        $operator = match ($operator) {
            '=', '!=', '<>' => $operator,
            '<', '>', '<=', '>=' => $this->factor > 0 ? $operator : strtr($operator, '<>', '><'),
            default => null,
        };

        return $operator === null ? null : SqlFragments::bound($operator, $value, 1, $this->factor, $type);
    }

    public function order(string $direction): string
    {
        $direction = strtolower($direction);

        return $this->factor > 0 ? $direction : ($direction === 'asc' ? 'desc' : 'asc');
    }

    public function type(null|string $type): null|string
    {
        return match (true) {
            SqlFragments::isFloat($type) => 'double precision',
            SqlFragments::isInteger($type), $type !== null && preg_match('/^(numeric|decimal)\b/i', $type) === 1 => 'numeric',
            default => null,
        };
    }

    public function exact(null|string $type, string $served): bool
    {
        if (SqlFragments::isFloat($type)) {
            return ($served === 'double precision' || $served === $type) && $this->doublesOrHalves();
        }

        $places = $this->places();
        $scale = SqlFragments::scale($type);

        return $places !== null && $scale !== null && SqlFragments::holds($served, $scale + $places);
    }

    private function places(): null|int
    {
        if (is_int($this->factor)) {
            return 0;
        }

        $literal = var_export($this->factor, true);

        return str_contains($literal, 'E') ? null : strlen(rtrim(substr($literal, strpos($literal, '.') + 1), '0'));
    }

    private function doublesOrHalves(): bool
    {
        $factor = abs((float) $this->factor);

        while ($factor >= 2.0) {
            $factor /= 2;
        }

        while ($factor > 0.0 && $factor < 1.0) {
            $factor *= 2;
        }

        return $factor === 1.0;
    }
}
