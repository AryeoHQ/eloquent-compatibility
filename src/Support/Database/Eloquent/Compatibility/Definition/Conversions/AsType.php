<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

/**
 * @internal
 */
final readonly class AsType implements Conversion
{
    public function __construct(public string $type) {}

    public function read(string $stored): string
    {
        return 'CAST('.$stored.' AS '.$this->type.')';
    }

    public function write(mixed $value): mixed
    {
        return $value;
    }

    public function filter(string $operator, mixed $value, null|string $type = null): null|array
    {
        return null;
    }

    public function type(null|string $type): string
    {
        return $this->type;
    }

    public function exact(null|string $type, string $served): bool
    {
        return false;
    }
}
