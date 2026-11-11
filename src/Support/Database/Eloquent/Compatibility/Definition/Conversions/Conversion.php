<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

/**
 * @internal
 */
interface Conversion
{
    public function read(string $stored): string;

    public function write(mixed $value): mixed;

    /**
     * @return null|array{string, mixed}
     */
    public function filter(string $operator, mixed $value, null|string $type = null): null|array;

    public function type(null|string $type): null|string;

    public function exact(null|string $type, string $served): bool;
}
