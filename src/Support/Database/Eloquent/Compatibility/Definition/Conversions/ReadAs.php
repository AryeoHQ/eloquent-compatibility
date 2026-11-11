<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

use LogicException;

/**
 * @internal
 */
final readonly class ReadAs implements Conversion
{
    public function __construct(public string $sql) {}

    public function read(string $stored): string
    {
        return '('.$this->sql.')';
    }

    public function write(mixed $value): mixed
    {
        throw new LogicException('readAs('.$this->sql.') has no inverse, so its writtenBy() operation writes it.');
    }

    public function filter(string $operator, mixed $value, null|string $type = null): null|array
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
