<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

use Closure;

final readonly class IsNotNull implements Conversion
{
    /**
     * @param  Closure(): mixed  $setTo
     */
    public function __construct(public Closure $setTo) {}

    public function read(string $stored): string
    {
        return '('.$stored.' IS NOT NULL)';
    }

    public function write(mixed $value): mixed
    {
        return (bool) $value ? ($this->setTo)() : null;
    }

    public function filter(string $operator, mixed $value): null|array
    {
        return match ($operator) {
            '=' => (bool) $value ? ['!=', null] : ['=', null],
            '!=', '<>' => (bool) $value ? ['=', null] : ['!=', null],
            default => null,
        };
    }

    public function order(string $direction): null|string
    {
        return null;
    }
}
