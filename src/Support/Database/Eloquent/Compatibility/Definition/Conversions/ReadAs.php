<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

use Closure;
use LogicException;

final readonly class ReadAs implements Conversion
{
    /**
     * @param  null|Closure(mixed): mixed  $inverse
     */
    public function __construct(public string $sql, public null|Closure $inverse = null) {}

    public function read(string $stored): string
    {
        return '('.$this->sql.')';
    }

    public function write(mixed $value): mixed
    {
        if ($this->inverse === null) {
            throw new LogicException('readAs('.$this->sql.') has no writeTo(), so it reads only.');
        }

        return ($this->inverse)($value);
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
