<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Mapping;

use Closure;

final readonly class TargetColumn
{
    /**
     * @param  Closure(array<string, mixed>): mixed  $value
     */
    public function __construct(
        public string $alias,
        public string $column,
        public Closure $value,
        public null|string $copiedFrom = null,
    ) {}

    public static function copy(string $alias, string $column, string $attribute): self
    {
        return new self($alias, $column, static fn (array $attributes): mixed => $attributes[$attribute] ?? null, $attribute);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function value(array $attributes): mixed
    {
        return ($this->value)($attributes);
    }
}
