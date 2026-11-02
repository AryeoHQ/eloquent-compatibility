<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

use BackedEnum;
use InvalidArgumentException;
use Support\Database\Eloquent\Compatibility\Schema\SqlFragments;

final readonly class Fixed implements Conversion
{
    public null|bool|int|float|string $value;

    public function __construct(null|bool|int|float|string|BackedEnum $value)
    {
        $this->value = $value instanceof BackedEnum ? $value->value : $value;
    }

    public function read(string $stored): string
    {
        return match (true) {
            $this->value === null => 'NULL',
            is_bool($this->value) => $this->value ? 'true' : 'false',
            is_int($this->value) => (string) $this->value,
            is_float($this->value) => var_export($this->value, true),
            default => SqlFragments::literal($this->value),
        };
    }

    public function write(mixed $value): mixed
    {
        $value = $value instanceof BackedEnum ? $value->value : $value;

        if ($value !== $this->value) {
            throw new InvalidArgumentException('The attribute always reads '.$this->read('').', so it cannot store '.var_export($value, true).'.');
        }

        return $this->value;
    }

    public function filter(string $operator, mixed $value, null|string $type = null): null|array
    {
        return null;
    }

    public function order(string $direction): null|string
    {
        return null;
    }

    public function type(null|string $type): null|string
    {
        return null;
    }

    public function exact(null|string $type, string $served): bool
    {
        return true;
    }
}
