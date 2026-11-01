<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

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

    public function filter(string $operator, mixed $value): null|array
    {
        return in_array($operator, ['=', '!=', '<>'], true) ? [$operator, $value] : null;
    }

    public function order(string $direction): null|string
    {
        return null;
    }
}
