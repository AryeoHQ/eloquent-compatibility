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

    public function filter(string $operator, mixed $value): null|array
    {
        $value = $value instanceof BackedEnum ? $value->value : $value;

        if ($operator !== '=' || ! is_scalar($value) || (string) $value === (string) $this->default) {
            return null;
        }

        return ['=', $value];
    }

    public function order(string $direction): null|string
    {
        return null;
    }
}
