<?php

declare(strict_types=1);

namespace Support\Database\Eloquent\Compatibility\Schema;

final readonly class Column
{
    public function __construct(
        public string $type,
        public null|string $default,
        public bool $notNull,
    ) {}
}
