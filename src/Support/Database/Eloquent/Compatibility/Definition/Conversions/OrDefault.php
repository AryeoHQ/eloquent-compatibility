<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

use BackedEnum;

final readonly class OrDefault implements Conversion
{
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

        $value = $this->normalised($value);

        if ($value === null) {
            return null;
        }

        return match ($operator) {
            '=' => $this->isDefault($value) ? null : ['=', $value],
            '!=', '<>' => [$this->isDefault($value) ? '!=' : 'is distinct from', $value],
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
        return $type !== null && $type === $served;
    }

    private function normalised(mixed $value): null|bool|int|float|string
    {
        $value = $value instanceof BackedEnum ? $value->value : $value;

        return match (true) {
            is_bool($this->default) => IsNotNull::boolean($value),
            is_string($this->default) => is_string($value) || is_int($value) || is_float($value) ? (string) $value : null,
            default => is_numeric($value) ? $value + 0 : null,
        };
    }

    private function isDefault(bool|int|float|string $value): bool
    {
        return is_bool($this->default) || is_string($this->default) ? $value === $this->default : (float) $value === (float) $this->default;
    }
}
