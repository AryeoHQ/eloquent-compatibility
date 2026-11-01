<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Definition\Conversions;

interface Conversion
{
    public function read(string $stored): string;

    public function write(mixed $value): mixed;

    /**
     * @return null|array{string, mixed}
     */
    public function filter(string $operator, mixed $value): null|array;

    public function order(string $direction): null|string;
}
